<?php

namespace App\Http\Controllers\Cms;

use Illuminate\Http\Request;
use Hellotreedigital\Cms\Controllers\CmsPageController;
use App\Http\Controllers\Controller;
use App\Order;

class OrdersController extends Controller
{
    public function __construct(CmsPageController $cmsPageController)
    {
        $this->cmsPageController = $cmsPageController;
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
        // Assuming: 1 = approved, 2 = pending, 3 = rejected
        $user = \App\Models\User::find($order->users_id);
        // Refund only if status changed from approved to pending or rejected
        if ($previousStatus == 1 && ($statusId == 2 || $statusId == 3)) {
            if ($user) {
                $user->credits_balance += $order->total_price;
                $user->total_purchases -= $order->total_price;
                $user->save();
            }
            return $order->total_price;
        }
        // Deduct only if status changed to approved
        if ($statusId == 1 && $previousStatus != 1) {
            if ($user) {
                $user->credits_balance -= $order->total_price;
                $user->total_purchases += $order->total_price;
                $user->save();
            }
        }
    }
}
