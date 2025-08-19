<?php

namespace App\Http\Controllers\Cms;

use Illuminate\Http\Request;
use Hellotreedigital\Cms\Controllers\CmsPageController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Order;

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
            $profit = Order::where('statuses_id', 1)
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

        // Redirect to the orders page
        return url(config('hellotree.cms_route_prefix') . '/orders');
    }

    private function updateUserCredits($orderId, $statusId, $previousStatus = null)
    {
        $order = Order::find($orderId);
        if (!$order) {
            return;
        }
        // Status IDs: 1 = approved, 3 = pending, 2 = rejected
        $user = User::find($order->users_id);
        if (!$user) {
            return;
        }

        // No action if status did not change
        if ($previousStatus == $statusId) {
            return;
        }

        // 1. If changed from pending to rejected, refund
        if ($previousStatus == 3 && $statusId == 2) {
            $user->credits_balance += $order->total_price;
            $user->total_purchases -= $order->total_price;
            $user->save();
            return $order->total_price;
        }

        // 2. If changed from pending to approved, do nothing
        if ($previousStatus == 3 && $statusId == 1) {
            return;
        }

        // 3. If changed from rejected to pending or approved, reduce amount again
        if ($previousStatus == 2 && ($statusId == 3 || $statusId == 1)) {
            $user->credits_balance -= $order->total_price;
            $user->total_purchases += $order->total_price;
            $user->save();
            return $order->total_price;
        }

        // 4. If changed from approved to rejected, refund
        if ($previousStatus == 1 && $statusId == 2) {
            $user->credits_balance += $order->total_price;
            $user->total_purchases -= $order->total_price;
            $user->save();
            return $order->total_price;
        }

        // 5. If changed from approved to pending, do nothing
        if ($previousStatus == 1 && $statusId == 3) {
            return;
        }
    }
}
