<?php

namespace App\Services\Suppliers;

use App\Models\User;
use App\Order;
use App\ProductsVariation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Places and tracks supplier orders for any local order whose product is
 * supplier-sourced. The right adapter is resolved from the order's
 * `external_source` via SupplierRegistry, so the same engine fulfils Yassen,
 * Swift, and future suppliers. Shared by FulfillSupplierOrderJob (placement on
 * approval) and the per-supplier check-orders commands (status polling).
 *
 * On a FAILED supplier outcome the customer's credits are refunded and the local
 * order is moved to REJECTED, mirroring the approved→rejected refund in
 * Cms\OrdersController::updateUserCredits.
 */
class SupplierOrderFulfillment
{
    public function __construct(private SupplierRegistry $registry)
    {
    }

    /** Whether this local order is for a product from a registered supplier. */
    public static function isExternal(Order $order): bool
    {
        $variation = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->with(['product' => fn ($q) => $q->withoutGlobalScope('cms_draft_flag')])
            ->find($order->product_variation_id);

        if (!$variation || !$variation->external_id || !$variation->product) {
            return false;
        }

        $source = $variation->product->external_source;

        return $source !== null && app(SupplierRegistry::class)->has($source);
    }

    /**
     * Place the supplier order. Idempotent: a second call for an order that
     * already has a supplier order id is a no-op.
     */
    public function fulfill(Order $order): void
    {
        if ($order->external_order_id) {
            return; // already placed
        }

        $variation = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->with(['product' => fn ($q) => $q->withoutGlobalScope('cms_draft_flag')])
            ->find($order->product_variation_id);

        if (!$variation || !$variation->product || !$variation->product->external_id) {
            return; // not a supplier product
        }

        $source = $variation->product->external_source;
        $connector = $this->registry->get($source);
        if (!$connector) {
            return; // unknown supplier
        }

        // Persist the dedupe uuid + source up front so a retry reuses them.
        if (!$order->external_order_uuid) {
            $order->external_order_uuid = (string) Str::uuid();
        }
        $order->external_source = $source;
        $order->save();

        try {
            $result = $connector->placeOrder($order, $variation);
        } catch (SupplierApiException $e) {
            Log::error('Supplier placeOrder failed', [
                'order_id' => $order->id,
                'source' => $source,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $order->external_order_id = $result->externalOrderId ?: null;
        $order->external_status = $result->status;
        $order->external_response = $result->raw;
        $order->save();

        $this->applyStatus($order);
    }

    /** Poll the supplier for the current status of an already-placed order. */
    public function refreshStatus(Order $order): void
    {
        $connector = $this->registry->get($order->external_source);
        if (!$connector) {
            return;
        }
        if (!$order->external_order_uuid && !$order->external_order_id) {
            return;
        }

        $result = $connector->checkOrder($order);
        $order->external_status = $result->status ?: $order->external_status;
        $order->external_response = $result->raw;
        $order->save();

        $this->applyStatus($order);
    }

    /** React to a freshly stored supplier status (refund on FAILED). */
    private function applyStatus(Order $order): void
    {
        if ($order->external_status === SupplierOrderResult::FAILED
            && (int) $order->statuses_id !== Order::STATUS_REJECTED) {
            $this->refund($order);
        }
    }

    private function refund(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::withoutGlobalScope('cms_draft_flag')->where('id', $order->id)->lockForUpdate()->first();
            if (!$locked || (int) $locked->statuses_id === Order::STATUS_REJECTED) {
                return;
            }

            $user = User::where('id', $locked->users_id)->lockForUpdate()->first();
            if ($user) {
                $user->credits_balance += $locked->total_price;
                $user->total_purchases -= $locked->total_price;
                $user->save();
            }

            $locked->statuses_id = Order::STATUS_REJECTED;
            $locked->save();

            $order->statuses_id = Order::STATUS_REJECTED;
        });
    }
}
