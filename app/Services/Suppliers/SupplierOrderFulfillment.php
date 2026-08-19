<?php

namespace App\Services\Suppliers;

use App\CreditLedgerEntry;
use App\Http\Controllers\NotificationController;
use App\Models\User;
use App\Order;
use App\ProductsVariation;
use App\Services\Credits\CreditLedger;
use App\Services\Credits\DuplicateLedgerEntryException;
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
        try {
            $refunded = DB::transaction(function () use ($order) {
            $locked = Order::withoutGlobalScope('cms_draft_flag')->where('id', $order->id)->lockForUpdate()->first();
            if (!$locked || (int) $locked->statuses_id === Order::STATUS_REJECTED) {
                return null;
            }

            $user = User::where('id', $locked->users_id)->lockForUpdate()->first();
            if ($user) {
                // Through the ledger rather than `$user->credits_balance += …`:
                // that was float arithmetic with no audit record, so a supplier
                // failure silently changed a balance with nothing to show for it.
                // The idempotency key makes a re-run of this job a no-op instead of
                // a second refund.
                CreditLedger::record(
                    $user,
                    (float) $locked->total_price,
                    CreditLedgerEntry::REASON_SUPPLIER_REFUND,
                    $locked,
                    [
                        'external_source' => $locked->external_source,
                        'external_status' => $locked->external_status,
                    ],
                    "order:{$locked->id}:supplier_refund",
                    CreditLedgerEntry::ACTOR_SYSTEM
                );

                DB::table('users')->where('id', $user->id)->update([
                    'total_purchases' => DB::raw('GREATEST(total_purchases - ' . number_format((float) $locked->total_price, 4, '.', '') . ', 0)'),
                ]);
            }

            $locked->statuses_id = Order::STATUS_REJECTED;
            // Keep the ledger's view of this order in step, so a later CMS status
            // change does not think it still owes a refund.
            $locked->credits_applied_status = Order::STATUS_REJECTED;
            $locked->save();

            $order->statuses_id = Order::STATUS_REJECTED;

                return $locked;
            });
        } catch (DuplicateLedgerEntryException $e) {
            // This refund was already applied — the job retried, or the order-status
            // poll and a CMS rejection raced. The transaction rolled back, so nothing
            // is half-done and the customer was refunded exactly once.
            Log::info('Supplier refund already applied, skipping', [
                'order_id' => $order->id,
                'key' => $e->idempotencyKey,
            ]);

            return;
        }

        // Notify the customer (in-app) that the supplier cancelled their order and
        // the credits were returned. Only fires on the real APPROVED→REJECTED transition.
        if ($refunded) {
            try {
                NotificationController::createOrderNotification(
                    $refunded->users_id,
                    $refunded->id,
                    $refunded->total_price,
                    'supplier_cancelled',
                );
            } catch (\Throwable $e) {
                Log::error('Order cancellation notification failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
