<?php

namespace App\Http\Controllers;

use App\FixedSetting;
use App\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    function getAdminEmail()
    {
        $adminEmail = FixedSetting::first()->admin_email;
        return $adminEmail ? $adminEmail : null;
    }
    public function saveOrder(Request $request)
    {
        // Validate and process the order data
        $validatedData = $request->validate([
            'product_variation_id' => 'required|exists:products_variations,id',
            'quantity' => 'required|integer|min:1',
            'users_id' => 'required|exists:users,id',
            'total_price' => 'required|numeric|min:0',
            'recipient_user' => 'max:255',
            'recipient_phone_number' => 'max:15',
            'statuses_id' => 'numeric'
        ]);

        // Create the order
        $order = new Order;
        $order->product_variation_id = $validatedData['product_variation_id'];
        $order->quantity = $validatedData['quantity'];
        $order->users_id = $validatedData['users_id'];
        $order->total_price = $validatedData['total_price'];
        $order->recipient_user = $validatedData['recipient_user'];
        $order->recipient_phone_number = $validatedData['recipient_phone_number'];
        $order->statuses_id = $validatedData['statuses_id'];
        $order->save();

        $admin_email = $this->getAdminEmail();

        //Reduce user's credits balance
        $user = \App\Models\User::find($order->users_id);
        if ($user) {
            $user->credits_balance -= $order->total_price;
            $user->total_purchases += $order->total_price;
            $user->save();
        }

        //Send email to the admin to approve the order
        Mail::send('emails.order-request', compact('order'), function ($message) use ($admin_email) {
            $message->to($admin_email)->subject('New Order Request');
        });

        return response()->json($order, 201);
    }
}
