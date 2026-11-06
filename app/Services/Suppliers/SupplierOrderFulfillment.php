<?php

namespace App\Services\Suppliers;

use App\CreditLedgerEntry;
use App\Http\Controllers\NotificationController;
use App\Models\User;
use App\Order;
use App\ProductsVariation;
use App\Services\Credits\CreditLedger;
use App\Services\Credits\DuplicateLedgerEntryException;
use App\Services\Reseller\OrderWebhooks;
use App\Services\Suppliers\Contracts\ParksBeforeWrite;
use Illuminate\Support\Facades\Cache;
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
    /** Longer than the slowest supplier POST (60 s), so a held lock means a live attempt. */
    private const FULFILL_LOCK_SECONDS = 300;

    public function __construct(private SupplierRegistry $registry)
    {
    }

    /** The supplier that owns this order's variation, whether or not it was placed yet. */
    public static function sourceOf(Order $order): ?string
    {
        return ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->with(['product' => fn ($q) => $q->withoutGlobalScope('cms_draft_flag')])
            ->find($order->product_variation_id)
            ?->supplierSource();
    }

    /** Whether this local order is for a product from a registered supplier. */
    public static function isExternal(Order $order): bool
    {
        $variation = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->with(['product' => fn ($q) => $q->withoutGlobalScope('cms_draft_flag')])
            ->find($order->product_variation_id);

        if (!$variation || !$variation->external_id) {
            return false;
        }

        // From the variation itself: a mapped supplier category puts supplier
        // variations under an admin-made product with no external_source.
        $source = $variation->supplierSource();

        return $source !== null && app(SupplierRegistry::class)->has($source);
    }

    /**
     * Place the supplier order. Idempotent: a second call for an order that
     * already has a supplier order id is a no-op.
     */
    public function fulfill(Order $order): void
    {
        // A worker and a Retry click can both get here, and most suppliers have no dedupe key,
        // so the check below has to happen once at a time and against the stored row.
        $lock = Cache::lock('supplier-fulfill:' . $order->id, self::FULFILL_LOCK_SECONDS);
        if (!$lock->get()) {
            return; // another worker is placing this order right now
        }

        try {
            $stored = Order::withoutGlobalScope('cms_draft_flag')->whereKey($order->id)->value('external_order_id');
            if ($order->external_order_id || $stored) {
                return; // already placed
            }

            $this->place($order);
        } finally {
            $lock->release();
        }
    }

    private function place(Order $order): void
    {
        $variation = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->with(['product' => fn ($q) => $q->withoutGlobalScope('cms_draft_flag')])
            ->find($order->product_variation_id);

        $source = $variation ? $variation->supplierSource() : null;
        // A supplier id on the variation (any mapped/imported row) or, as before,
        // on the parent product.
        if (!$source || (!$variation->external_id && !optional($variation->product)->external_id)) {
            return; // not a supplier product
        }

        $connector = $this->registry->get($source);
        if (!$connector) {
            return; // unknown supplier
        }

        // Persist the dedupe uuid + source up front so a retry reuses them.
        if (!$order->external_order_uuid) {
            $order->external_order_uuid = (string) Str::uuid();
        }
        $order->external_source = $source;

        $parked = $connector instanceof ParksBeforeWrite;
        if ($parked) {
            $order->external_order_id = SupplierOrderResult::PARKED_ID_PREFIX . $order->external_order_uuid;
            $order->external_status = SupplierOrderResult::PENDING;
        }
        $order->save();

        try {
            $result = $connector->placeOrder($order, $variation);
        } catch (SupplierApiException $e) {
            // A definite refusal: nothing was bought, so a retry may send it. Anything else
            // leaves the parked id in place, because the request may have gone through.
            if ($parked) {
                $order->external_order_id = null;
                $order->external_status = null;
                $order->save();
            }

            Log::error('Supplier placeOrder failed', [
                'order_id' => $order->id,
                'source' => $source,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $previous = $order->external_status;
        $order->external_order_id = $result->externalOrderId ?: null;
        $order->external_status = $result->status;
        $order->external_response = $result->raw;
        $order->save();

        $this->applyStatus($order, $previous);
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
        $previous = $order->external_status;
        // Delivered stays delivered. Back to pending would remove the guard that refuses
        // rejecting (and refunding) an order the customer already received.
        if (!($previous === SupplierOrderResult::COMPLETED && $result->status === SupplierOrderResult::PENDING)) {
            $order->external_status = $result->status ?: $order->external_status;
        }
        $order->external_response = $result->raw;
        $order->save();

        $this->applyStatus($order, $previous);
    }

    /**
     * React to a freshly stored supplier status: refund on FAILED, and tell a
     * reseller (order webhook) the first time it turns COMPLETED. For a supplier
     * order that is the moment it is really delivered — the CMS/API approval
     * before it only handed it to the supplier.
     */
    private function applyStatus(Order $order, ?string $previous = null): void
    {
        if ($order->external_status === SupplierOrderResult::FAILED
            && (int) $order->statuses_id !== Order::STATUS_REJECTED) {
            $this->refund($order);

            return;
        }

        if ($order->external_status === SupplierOrderResult::COMPLETED
            && $previous !== SupplierOrderResult::COMPLETED
            && (int) $order->statuses_id === Order::STATUS_APPROVED) {
            OrderWebhooks::orderFinalized((int) $order->id);
        }

        // The supplier delivered something the customer has already been refunded for. The code
        // stays hidden from them (App\Order::toArray), but someone needs to reconcile this.
        if ($order->external_status === SupplierOrderResult::COMPLETED
            && $previous !== SupplierOrderResult::COMPLETED
            && (int) $order->statuses_id !== Order::STATUS_APPROVED) {
            Log::critical('Supplier order completed after the order was rejected — reconcile manually', [
                'order_id' => $order->id,
                'source' => $order->external_source,
                'external_order_id' => $order->external_order_id,
            ]);
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

            // An admin can have set this Approved after it was rejected and refunded, and the
            // ledger has not settled that yet. The money is already back, so only the status
            // moves. A NULL applied status reads as Pending, meaning held.
            $alreadyRefunded = (int) $locked->credits_applied_status === Order::STATUS_REJECTED;

            $user = $alreadyRefunded ? null : User::withTrashed()->where('id', $locked->users_id)->lockForUpdate()->first();
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
                    CreditLedger::occurrenceKey("order:{$locked->id}:supplier_refund"),
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
            OrderWebhooks::orderFinalized((int) $refunded->id);

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
