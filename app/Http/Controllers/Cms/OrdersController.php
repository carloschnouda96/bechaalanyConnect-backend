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
        $this->cmsPageController->update($request, $id, 'orders', 'App\Order', 'App\Http\Controllers\Cms\OrdersController');

        //Update User credits_balance and total_purchases if Order approved
        if ($request->statuses_id == 1) { // Assuming 1 is the ID for 'approved' status
            $this->updateUserCredits($id);
        }
        return url(config('hellotree.cms_route_prefix') . '/orders');
    }

    private function updateUserCredits($orderId)
    {
        $order = Order::find($orderId);
        if ($order) {
            $user = \App\Models\User::find($order->users_id);
            if ($user) {
                $user->credits_balance -= $order->total_price;
                $user->total_purchases += $order->total_price;
                $user->save();
            }
        }
    }


}
