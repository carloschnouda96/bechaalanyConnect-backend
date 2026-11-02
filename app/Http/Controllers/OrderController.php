<?php

namespace App\Http\Controllers;

use App\FixedSetting;
use App\Order;
use App\Services\Orders\OrderPlacement;
use App\Services\Orders\OrderPlacementException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /** Most units a single order may hold (non-coin products). */
    public const MAX_QUANTITY = 100;

    /**
     * Most coins a single Coin Recharge order may hold when the supplier reports no
     * max of its own. Coin orders carry the real coin count in `quantity`.
     */
    public const MAX_COINS = 10000000;

    function getAdminEmail()
    {
        // Was FixedSetting::first()->admin_email, which fataled when the settings
        // row was missing or draft-flagged — taking down order placement entirely.
        return FixedSetting::adminEmail();
    }

    public function getUserOrders(Request $request)
    {
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 10);

        $orders = Order::where('users_id', auth()->id())
            ->with([
                'product_variation.product',
                // Column-restricted on purpose. Order::users() resolves to App\User,
                // which had no $hidden, so loading the whole row put the bcrypt
                // password hash and the live password_reset_token into every page of
                // this response. App\User now hides those, but the allow-list keeps
                // the payload minimal and survives someone editing $hidden later.
                // These are the fields my-orders.tsx puts on the PDF receipt, and
                // they belong to the caller themselves.
                'users:id,username,email,phone_number,country,business_location,business_name',
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


    /**
     * One order, scoped to its owner.
     *
     * Backs the storefront's order status page: after saveOrder() the customer was
     * dropped on the full My Orders list with no way to watch just the order they
     * placed, and My Orders itself only auto-refreshed every 3 minutes. This lets the
     * page poll a single row while it is pending instead of refetching the whole list.
     *
     * Scoped with where('users_id', ...) rather than findOrFail() + an authorization
     * check, so an order that exists but belongs to someone else 404s exactly like one
     * that does not exist at all — nothing about another customer's order is revealed.
     */
    public function getUserOrder($locale, $id)
    {
        $order = Order::where('users_id', auth()->id())
            ->where('id', $id)
            ->with([
                'product_variation.product',
                // Same allow-list as getUserOrders — this response feeds the same
                // receipt PDF, which is the only consumer of these customer fields.
                'users:id,username,email,phone_number,country,business_location,business_name',
            ])
            ->first();

        if (!$order) {
            return response()->json(['message' => __('api.orders.not_found'), 'code' => 'not_found'], 404);
        }

        return response()->json($order);
    }

    public function saveOrder(Request $request, OrderPlacement $placement)
    {
        $validatedData = $request->validate([
            // Just existence here — "is this actually sellable" (is_active AND
            // supplier stock, on both the variation and its product) is a combined
            // rule (ProductsVariation::scopeSellable()) that Rule::exists can't
            // express as a single `where`, so OrderPlacement checks it via
            // sellable() instead, with its own message/code.
            'product_variation_id' => [
                'required',
                'integer',
                Rule::exists('products_variations', 'id'),
            ],
            // Bounded by the largest per-product cap here; the real per-variation max
            // (100 units, or the coin max) is checked once the variation is loaded.
            'quantity' => ['required', 'integer', 'min:1', 'max:' . self::MAX_COINS],
            'recipient_user' => ['nullable', 'string', 'max:255'],
            // Suppliers require a real MSISDN; this previously accepted any string.
            'recipient_phone_number' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
        ], [
            'product_variation_id.exists' => __('api.orders.unavailable'),
            'recipient_phone_number.regex' => __('api.orders.invalid_phone'),
        ]);

        // KYC, stock, recipient rules, quantity bounds, tier pricing and the locked
        // debit all live in OrderPlacement, shared with the reseller API.
        try {
            $placed = $placement->place($request->user(), $validatedData);
        } catch (OrderPlacementException $e) {
            // Machine-readable `code` plus any figures (e.g. "you need $3.50 more"),
            // so the storefront can act on it instead of toasting a bare sentence.
            return response()->json(
                ['message' => $e->getMessage(), 'code' => $e->errorCode] + $e->extra,
                $e->status
            );
        }

        return response()->json($placed->order, 201);
    }
}
