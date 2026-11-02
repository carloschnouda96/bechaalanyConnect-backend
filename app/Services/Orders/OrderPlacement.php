<?php

namespace App\Services\Orders;

use App\CreditLedgerEntry;
use App\FixedSetting;
use App\Http\Controllers\OrderController;
use App\Jobs\FulfillSupplierOrderJob;
use App\Models\User;
use App\Order;
use App\Product;
use App\ProductsVariation;
use App\Services\Credits\CreditLedger;
use App\Services\Suppliers\SupplierOrderFulfillment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The one way an order is placed — by the storefront (OrderController::saveOrder)
 * and by the reseller API (Reseller\OrdersController). Everything that decides
 * whether an order is allowed and what it costs lives here, so the two entry
 * points cannot drift apart on KYC, stock, recipient rules, quantity bounds or
 * tier pricing.
 *
 * Input is already shape-validated by the caller:
 *   product_variation_id, quantity, recipient_user?, recipient_phone_number?
 *
 * Options:
 *   source                 'storefront' (default) | 'api'
 *   client_order_id        reseller reference; a repeat returns the first order
 *   auto_approve_supplier  approve a supplier-fulfilled order straight away and
 *                          dispatch it to the supplier (reseller API). Manual
 *                          products still wait for an admin.
 */
class OrderPlacement
{
    public function place(User $user, array $input, array $options = []): PlacedOrder
    {
        $source = $options['source'] ?? 'storefront';
        $clientOrderId = $options['client_order_id'] ?? null;
        $autoApprove = (bool) ($options['auto_approve_supplier'] ?? false);

        // A retry of an order that already went through is answered before any
        // other check — the stock or the price may have changed since, and the
        // caller is asking "what happened to my order", not placing a new one.
        if ($clientOrderId !== null && ($existing = $this->findByClientOrderId($user, $clientOrderId))) {
            return new PlacedOrder($existing, false);
        }

        if ($user->verification_status !== 'approved') {
            throw new OrderPlacementException('kyc_required', __('api.orders.kyc_required'), 403);
        }

        // sellable() combines is_active with supplier stock on both the variation
        // and its parent product (Product::scopeSellable()) — a variation whose
        // own row is on but whose product is off (or out of stock, or withdrawn)
        // is caught in this one query instead of a separate follow-up check.
        $variation = ProductsVariation::sellable()
            ->with(['priceVariations', 'product'])
            ->find($input['product_variation_id']);

        if (!$variation) {
            throw new OrderPlacementException('product_unavailable', __('api.orders.unavailable'), 422);
        }

        // Recipient requirements mirror the storefront purchase form and what the
        // supplier connectors actually need:
        //   type 1 Direct Recharge   → account/player id  (recipient_user)
        //   type 3 phone-based       → MSISDN             (recipient_phone_number)
        //   type 4 Coin Recharge     → player id          (recipient_user)
        //   type 5 Social Media      → post/profile link  (recipient_user)
        // Enforced server-side too, because a missing recipient means the supplier
        // call fails after the user has already been debited.
        $productType = (int) ($variation->product->product_type_id ?? 0);

        if ($productType === Product::TYPE_PHONE && blank($input['recipient_phone_number'] ?? null)) {
            throw new OrderPlacementException('recipient_phone_required', __('api.orders.phone_required'), 422);
        }

        if (in_array($productType, [Product::TYPE_USER_ID, Product::TYPE_COIN, Product::TYPE_SOCIAL], true)
            && blank($input['recipient_user'] ?? null)) {
            throw new OrderPlacementException('recipient_user_required', __('api.orders.user_id_required'), 422);
        }

        // Supplier floors (e.g. Jawaker sells from 10,000 coins) — rejected here,
        // before any debit, instead of failing upstream after the user has paid.
        // Coin orders carry the real coin count, so both bounds read "10,000 Coins".
        $quantity = (int) $input['quantity'];
        $isCoin = $productType === Product::TYPE_COIN;
        $bound = fn (int $n) => $isCoin
            ? trim(number_format($n) . ' ' . ($variation->unit_label ?? ''))
            : (string) $n;

        $minQuantity = $variation->minOrderQuantity();
        if ($quantity < $minQuantity) {
            throw new OrderPlacementException(
                'below_min_quantity',
                __('api.orders.min_quantity', ['min' => $bound($minQuantity)]),
                422,
                ['min_quantity' => $minQuantity]
            );
        }

        $maxQuantity = $variation->maxOrderQuantity();
        if ($quantity > $maxQuantity) {
            throw new OrderPlacementException(
                'above_max_quantity',
                __('api.orders.max_quantity', ['max' => $bound($maxQuantity)]),
                422,
                ['max_quantity' => $maxQuantity]
            );
        }

        $totalPrice = self::totalFor($variation, $user, $quantity);

        $shortfall = null;
        $existing = null;

        $order = DB::transaction(function () use ($user, $input, $quantity, $totalPrice, $source, $clientOrderId, $autoApprove, &$shortfall, &$existing) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();

            // Re-checked under the user lock: two concurrent retries of the same
            // client_order_id serialise here, and the second sees the first's
            // order. The UNIQUE (users_id, client_order_id) index is the backstop.
            if ($clientOrderId !== null && ($existing = $this->findByClientOrderId($lockedUser, $clientOrderId))) {
                return null;
            }

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
            $order->product_variation_id = $input['product_variation_id'];
            $order->quantity = $quantity;
            $order->users_id = $lockedUser->id;
            $order->total_price = $totalPrice;
            $order->recipient_user = $input['recipient_user'] ?? null;
            $order->recipient_phone_number = $input['recipient_phone_number'] ?? null;
            $order->client_order_id = $clientOrderId;
            $order->source = $source;

            // An auto-approved order is debited once, here, exactly like a pending
            // one: the ledger's PENDING → APPROVED delta is 0, so recording the
            // order as APPROVED from the start keeps credits_applied_status and
            // the ledger in agreement without a second entry.
            $status = $autoApprove && SupplierOrderFulfillment::isExternal($order)
                ? Order::STATUS_APPROVED
                : Order::STATUS_PENDING;
            $order->statuses_id = $status;
            // Credits are debited below for exactly this status, so the ledger and
            // the order agree from the moment it is created.
            $order->credits_applied_status = $status;
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
                ['quantity' => $order->quantity, 'variation_id' => $order->product_variation_id, 'source' => $source],
                "order:{$order->id}:place",
                CreditLedgerEntry::ACTOR_USER,
                $lockedUser->id
            );

            DB::table('users')->where('id', $lockedUser->id)->update([
                'total_purchases' => DB::raw('total_purchases + ' . number_format($totalPrice, 4, '.', '')),
            ]);

            return $order;
        });

        if ($existing) {
            return new PlacedOrder($existing, false);
        }

        if (!$order) {
            throw new OrderPlacementException(
                'insufficient_credits',
                __('api.orders.insufficient_credits'),
                400,
                [
                    'balance' => $shortfall['balance'] ?? null,
                    'required' => $shortfall['required'] ?? null,
                    'missing' => $shortfall['missing'] ?? null,
                ]
            );
        }

        // After commit: the supplier call must never sit inside the money transaction.
        if ((int) $order->statuses_id === Order::STATUS_APPROVED) {
            FulfillSupplierOrderJob::dispatch($order->id);
        } else {
            $this->notifyAdmin($order);
        }

        return new PlacedOrder($order, true);
    }

    /**
     * What this user pays for `$quantity` of `$variation`: their tier's price when
     * one is set (product_price_variations.price always holds the resolved figure),
     * else the public price. A coin / social-media price is per pricingUnit() units
     * (e.g. $0.13 per 1,000) and the customer types any amount, so the total is
     * prorated and rounded up to the cent — the storefront displays the same formula.
     */
    public static function totalFor(ProductsVariation $variation, User $user, int $quantity): float
    {
        $unitPrice = self::unitPriceFor($variation, $user);

        return $variation->isProrated()
            ? ProductsVariation::coinTotal($unitPrice, $quantity, $variation->pricingUnit())
            : $unitPrice * $quantity;
    }

    public static function unitPriceFor(ProductsVariation $variation, User $user): float
    {
        $unitPrice = (float) $variation->price;
        if ($user->user_types_id) {
            $match = $variation->priceVariations->firstWhere('user_types_id', $user->user_types_id);
            if ($match) {
                $unitPrice = (float) $match->price;
            }
        }

        return $unitPrice;
    }

    private function findByClientOrderId(User $user, string $clientOrderId): ?Order
    {
        // withTrashed: an admin soft-deleting the order must not let a retry
        // through as a brand-new, second debit.
        return Order::withTrashed()
            ->where('users_id', $user->id)
            ->where('client_order_id', $clientOrderId)
            ->first();
    }

    private function notifyAdmin(Order $order): void
    {
        $adminEmail = FixedSetting::adminEmail();
        if (!$adminEmail) {
            return;
        }

        $requestedLocale = app()->getLocale();

        try {
            Mail::send('emails.order-request', compact('order', 'requestedLocale'), function ($message) use ($adminEmail) {
                $message->to($adminEmail)->subject(__('emails.subjects.new_order'));
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
}
