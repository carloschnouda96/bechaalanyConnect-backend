<?php

namespace App\Http\Controllers;

use App\FixedSetting;
use App\Models\User;
use App\Order;
use App\ProductsVariation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    function getAdminEmail()
    {
        $adminEmail = FixedSetting::first()->admin_email;
        return $adminEmail ? $adminEmail : null;
    }

    public function getUserOrders(Request $request)
    {
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 10);

        $orders = Order::where('users_id', auth()->id())
            ->with([
                'product_variation.product',
                'users'
            ])
            ->orderBy('created_at', 'desc')
            ->paginate($limit);

        return response()->json([
            'orders' => $orders->items(),
            'total' => $orders->total(),
            'current_page' => $orders->currentPage(),
            'per_page' => $orders->perPage(),
            'last_page' => $orders->lastPage()
        ]);
    }


    public function saveOrder(Request $request)
    {
        $requestedLocale = app()->getLocale();

        $validatedData = $request->validate([
            'product_variation_id' => 'required|exists:products_variations,id',
            'quantity' => 'required|integer|min:1',
            'recipient_user' => 'nullable|string|max:255',
            'recipient_phone_number' => 'nullable|string|max:15',
        ]);

        $user = $request->user();

        if ($user->verification_status !== 'approved') {
            return response()->json(['message' => 'Your account must be verified before placing orders.'], 403);
        }

        $variation = ProductsVariation::with('priceVariations')->findOrFail($validatedData['product_variation_id']);

        // Price is determined server-side: user-type price variation, else base price
        $unitPrice = $variation->price;
        if ($user->user_types_id) {
            $match = $variation->priceVariations->firstWhere('user_types_id', $user->user_types_id);
            if ($match) {
                $unitPrice = $match->price;
            }
        }
        $totalPrice = $unitPrice * $validatedData['quantity'];

        $order = DB::transaction(function () use ($user, $validatedData, $totalPrice) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();

            if ($lockedUser->credits_balance < $totalPrice) {
                return null;
            }

            $order = new Order;
            $order->product_variation_id = $validatedData['product_variation_id'];
            $order->quantity = $validatedData['quantity'];
            $order->users_id = $lockedUser->id;
            $order->total_price = $totalPrice;
            $order->recipient_user = $validatedData['recipient_user'] ?? null;
            $order->recipient_phone_number = $validatedData['recipient_phone_number'] ?? null;
            $order->statuses_id = Order::STATUS_PENDING;
            $order->save();

            $lockedUser->credits_balance -= $totalPrice;
            $lockedUser->total_purchases += $totalPrice;
            $lockedUser->save();

            return $order;
        });

        if (!$order) {
            return response()->json(['message' => 'Not enough credits to place order'], 400);
        }

        $admin_email = $this->getAdminEmail();

        //Send email to the admin to approve the order
        if ($admin_email) {
            Mail::send('emails.order-request', compact('order', 'requestedLocale'), function ($message) use ($admin_email) {
                $message->to($admin_email)->subject(__('emails.subjects.new_order'));
            });
        }

        return response()->json($order, 201);
    }
}
