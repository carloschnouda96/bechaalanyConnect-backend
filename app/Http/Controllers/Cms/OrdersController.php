<?php

namespace App\Http\Controllers\Cms;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Hellotreedigital\Cms\Controllers\CmsPageController;
use App\Http\Controllers\Controller;
use App\Jobs\FulfillYassenOrderJob;
use App\Models\User;
use App\Order;
use App\Services\Yassen\YassenOrderFulfillment;

class OrdersController extends Controller
{
    /** @var CmsPageController */
    protected $cmsPageController;
    public function __construct(CmsPageController $cmsPageController)
    {
        $this->cmsPageController = $cmsPageController;
    }

    /**
     * Calculate total profit for all approved (successful) orders.
     * Success status assumed to be statuses_id = 1.
     * Profit per order = total_price - (cost_price * quantity).
     * Uses a single aggregate query for efficiency.
     *
     * @return float
     */
    public static function calculateTotalProfit(): float
    {
        try {
            $profit = Order::where('statuses_id', Order::STATUS_APPROVED)
                ->join('products_variations', 'orders.product_variation_id', '=', 'products_variations.id')
                ->selectRaw('COALESCE(SUM(orders.total_price - (COALESCE(products_variations.cost_price,0) * COALESCE(orders.quantity,1))),0) as total_profit')
                ->value('total_profit');
            return (float) $profit;
        } catch (\Throwable $e) {
            return 0.0; // Fail safe
        }
    }

    public function update(Request $request, $id)
    {
        // Get the order before updating to check previous status
        $order = Order::find($id);
        $previousStatus = $order ? $order->statuses_id : null;

        $this->cmsPageController->update($request, $id, 'orders', 'App\Order', 'App\Http\Controllers\Cms\OrdersController');

        //Update User credits_balance and total_purchases if Order approved
        $this->updateUserCredits($id, $request->statuses_id, $previousStatus);

        // Auto-fulfill via the supplier when an order is freshly approved.
        $this->maybeFulfillExternalOrder($id, $request->statuses_id, $previousStatus);

        // Redirect to the orders page
        return url(config('hellotree.cms_route_prefix') . '/orders');
    }

    /**
     * Dispatch supplier fulfillment when an order moves into APPROVED from a
     * non-approved state, but only for Yassen-sourced orders that haven't
     * already been placed.
     */
    private function maybeFulfillExternalOrder($orderId, $statusId, $previousStatus = null): void
    {
        if ((int) $statusId !== Order::STATUS_APPROVED || (int) $previousStatus === Order::STATUS_APPROVED) {
            return;
        }

        $order = Order::find($orderId);
        if (!$order || $order->external_order_id) {
            return; // already fulfilled
        }

        if (YassenOrderFulfillment::isExternal($order)) {
            FulfillYassenOrderJob::dispatch($order->id);
        }
    }

    private function updateUserCredits($orderId, $statusId, $previousStatus = null)
    {
        // No action if status did not change
        if ($previousStatus == $statusId) {
            return;
        }

        return DB::transaction(function () use ($orderId, $statusId, $previousStatus) {
            $order = Order::find($orderId);
            if (!$order) {
                return;
            }
            $user = User::where('id', $order->users_id)->lockForUpdate()->first();
            if (!$user) {
                return;
            }

            // 1. If changed from pending to rejected, refund
            if ($previousStatus == Order::STATUS_PENDING && $statusId == Order::STATUS_REJECTED) {
                $user->credits_balance += $order->total_price;
                $user->total_purchases -= $order->total_price;
                $user->save();
                return $order->total_price;
            }

            // 2. If changed from pending to approved, do nothing
            if ($previousStatus == Order::STATUS_PENDING && $statusId == Order::STATUS_APPROVED) {
                return;
            }

            // 3. If changed from rejected to pending or approved, reduce amount again
            if ($previousStatus == Order::STATUS_REJECTED && ($statusId == Order::STATUS_PENDING || $statusId == Order::STATUS_APPROVED)) {
                $user->credits_balance -= $order->total_price;
                $user->total_purchases += $order->total_price;
                $user->save();
                return $order->total_price;
            }

            // 4. If changed from approved to rejected, refund
            if ($previousStatus == Order::STATUS_APPROVED && $statusId == Order::STATUS_REJECTED) {
                $user->credits_balance += $order->total_price;
                $user->total_purchases -= $order->total_price;
                $user->save();
                return $order->total_price;
            }

            // 5. If changed from approved to pending, do nothing
            if ($previousStatus == Order::STATUS_APPROVED && $statusId == Order::STATUS_PENDING) {
                return;
            }
        });
    }
}
