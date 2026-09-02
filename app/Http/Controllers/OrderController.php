<?php

namespace App\Http\Controllers;

use App\CreditLedgerEntry;
use App\FixedSetting;
use App\Models\User;
use App\Order;
use App\ProductsVariation;
use App\Services\Credits\CreditLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
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
            return response()->json(['message' => 'Order not found.', 'code' => 'not_found'], 404);
        }

        return response()->json($order);
    }

    public function saveOrder(Request $request)
    {
        $requestedLocale = app()->getLocale();

        $validatedData = $request->validate([
            // Rule::exists scoped to is_active. The plain `exists` rule accepted any
            // variation id, so a product the supplier sync had deactivated (withdrawn
            // upstream, or excluded by an admin) stayed orderable indefinitely — the
            // user was charged and the order could never be fulfilled.
            'product_variation_id' => [
                'required',
                'integer',
                Rule::exists('products_variations', 'id')->where('is_active', 1),
            ],
            // `max` added: quantity was unbounded, so a single request could debit an
            // arbitrarily large amount and place an absurd order at the supplier.
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'recipient_user' => ['nullable', 'string', 'max:255'],
            // Suppliers require a real MSISDN; this previously accepted any string.
            'recipient_phone_number' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
        ], [
            'product_variation_id.exists' => 'This product is no longer available.',
            'recipient_phone_number.regex' => 'Enter a valid phone number (7-15 digits, optional leading +).',
        ]);

        $user = $request->user();

        if ($user->verification_status !== 'approved') {
            return response()->json([
                'message' => 'Your account must be verified before placing orders.',
                'code' => 'kyc_required',
            ], 403);
        }

        $variation = ProductsVariation::with(['priceVariations', 'product'])
            ->where('is_active', 1)
            ->findOrFail($validatedData['product_variation_id']);

        // A variation can be active while its parent product is not — the sync
        // deactivates the product on exclusion, and is_active on the variation is
        // set independently.
        if (!$variation->product || !$variation->product->is_active) {
            return response()->json([
                'message' => 'This product is no longer available.',
                'code' => 'product_unavailable',
            ], 422);
        }

        // Recipient requirements mirror the storefront purchase form and what the
        // supplier connectors actually need:
        //   type 1 Direct Recharge   → account/player id  (recipient_user)
        //   type 3 phone-based       → MSISDN             (recipient_phone_number)
        // Enforced server-side too, because a missing recipient means the supplier
        // call fails after the user has already been debited.
        $productType = (int) ($variation->product->product_type_id ?? 0);

        if ($productType === 3 && blank($validatedData['recipient_phone_number'] ?? null)) {
            return response()->json([
                'message' => 'A phone number is required for this product.',
                'code' => 'recipient_phone_required',
            ], 422);
        }

        if ($productType === 1 && blank($validatedData['recipient_user'] ?? null)) {
            return response()->json([
                'message' => 'A user ID is required for this product.',
                'code' => 'recipient_user_required',
            ], 422);
        }

        // Price is determined server-side: user-type price variation, else base price
        $unitPrice = $variation->price;
        if ($user->user_types_id) {
            $match = $variation->priceVariations->firstWhere('user_types_id', $user->user_types_id);
            if ($match) {
                $unitPrice = $match->price;
            }
        }
        $totalPrice = $unitPrice * $validatedData['quantity'];

        $shortfall = null;

        $order = DB::transaction(function () use ($user, $validatedData, $totalPrice, &$shortfall) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();

            if ($lockedUser->credits_balance < $totalPrice) {
                // Captured under the lock so the figures reported to the user are the
                // ones the decision was actually made on.
                $shortfall = [
                    'balance' => round((float) $lockedUser->credits_balance, 2),
                    'required' => round((float) $totalPrice, 2),
                    'missing' => round((float) $totalPrice - (float) $lockedUser->credits_balance, 2),
                ];

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
            // Credits are debited below for exactly this status, so the ledger and
            // the order agree from the moment it is created.
            $order->credits_applied_status = Order::STATUS_PENDING;
            $order->save();

            // Through the ledger rather than `$lockedUser->credits_balance -= …`.
            // That was float arithmetic on a value that is now DECIMAL, and it left
            // no record of why a balance changed. The arithmetic now happens in SQL
            // and produces an auditable row.
            CreditLedger::record(
                $lockedUser,
                -$totalPrice,
                CreditLedgerEntry::REASON_ORDER_PLACED,
                $order,
                ['quantity' => $order->quantity, 'variation_id' => $order->product_variation_id],
                "order:{$order->id}:place",
                CreditLedgerEntry::ACTOR_USER,
                $lockedUser->id
            );

            DB::table('users')->where('id', $lockedUser->id)->update([
                'total_purchases' => DB::raw('total_purchases + ' . number_format($totalPrice, 4, '.', '')),
            ]);

            return $order;
        });

        if (!$order) {
            // Machine-readable `code` plus the actual figures, so the storefront can
            // say "you need $3.50 more" with an Add-credits link instead of toasting
            // a bare English sentence with no context (and no way to act on it).
            return response()->json([
                'message' => 'Not enough credits to place this order.',
                'code' => 'insufficient_credits',
                'balance' => $shortfall['balance'] ?? null,
                'required' => $shortfall['required'] ?? null,
                'missing' => $shortfall['missing'] ?? null,
            ], 400);
        }

        $admin_email = $this->getAdminEmail();

        //Send email to the admin to approve the order
        if ($admin_email) {
            try {
                Mail::send('emails.order-request', compact('order', 'requestedLocale'), function ($message) use ($admin_email) {
                    $message->to($admin_email)->subject(__('emails.subjects.new_order'));
                });
            } catch (\Throwable $e) {
                // The order is committed and the user is already debited. An SMTP
                // failure must not surface as "order failed" — that would push the
                // user to retry and pay twice. Logged for the admin instead.
                Log::error('Order notification email failed', [
                    'order_id' => $order->id,
                    'exception' => $e,
                ]);
            }
        }

        return response()->json($order, 201);
    }
}
