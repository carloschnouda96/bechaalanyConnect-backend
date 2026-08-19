<?php

namespace App\Http\Controllers\Cms;

use App\BycelPurchase;
use App\Http\Controllers\Controller;
use App\Jobs\FulfillSupplierOrderJob;
use App\Order;
use App\Services\Bycel\BycelClient;
use App\Services\Bycel\BycelPurchaseLedger;
use App\Services\Suppliers\SupplierOrderFulfillment;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Operational view of the supplier integrations.
 *
 * Three things were invisible before this page existed:
 *
 * 1. Wallet balances. Every connector implements `balance(): ?float`
 *    (SwiftConnector:112, UmanageConnector:576, PerfectPanelConnector:127,
 *    UsharezConnector:356) and NOTHING called any of them — not a controller, not a
 *    command, not a view. An admin approving orders had no way to know a supplier
 *    account was empty until fulfillment started failing.
 *
 * 2. Orders that were approved and charged but never actually reached the supplier.
 *    The documented case is U-Manage answering 429: FulfillSupplierOrderJob retries
 *    3× at 30s while the API asks for an hour, so the job gives up and the order sits
 *    Approved with a null external_order_id, silently, forever.
 *
 * 3. A way to retry those without editing the database by hand.
 */
class SupplierHealthController extends Controller
{
    /** Balances are live third-party HTTP calls; cache so a page refresh is not a stampede. */
    private const BALANCE_CACHE_SECONDS = 300;

    public function index(SupplierRegistry $registry)
    {
        $suppliers = [];

        foreach ($registry->enabled() as $connector) {
            $key = $connector->key();

            $suppliers[] = [
                'key' => $key,
                'configured' => $this->safely(fn () => $connector->isConfigured(), false),
                'balance' => $this->balanceFor($connector),
                'orders_pending' => Order::where('external_source', $key)->where('external_status', 'pending')->count(),
                'orders_unplaced' => Order::where('external_source', $key)
                    ->where('statuses_id', Order::STATUS_APPROVED)
                    ->whereNull('external_order_id')
                    ->count(),
                'orders_failed' => Order::where('external_source', $key)->where('external_status', 'failed')->count(),
            ];
        }

        // The actionable list: the customer has paid and the supplier never got it.
        $unplaced = Order::with('users:id,username,email')
            ->where('statuses_id', Order::STATUS_APPROVED)
            ->whereNotNull('external_source')
            ->whereNull('external_order_id')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        // Bycel purchases the resolver refused to auto-deliver. These are the only
        // orders in the system where a customer is waiting on a human decision.
        $bycelReview = BycelPurchase::with('order:id,users_id,total_price,created_at')
            ->where('state', BycelPurchase::STATE_AMBIGUOUS)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('cms::pages/supplier-health/index', compact('suppliers', 'unplaced', 'bycelReview'));
    }

    /**
     * Attach one specific Bycel report row to a purchase an admin has adjudicated.
     *
     * Bycel's buy_voucher returns no order id, so when two purchases land in the
     * same window the resolver refuses to guess (BycelPinResolver). This is the
     * human override. The UNIQUE index on merchant_purchase_id still applies, so
     * the same row can never back two orders even from here.
     */
    public function claimBycelPin(Request $request, $purchase, $merchantPurchaseId, BycelPurchaseLedger $ledger)
    {
        $record = BycelPurchase::find($purchase);
        if (!$record) {
            return back()->with('success', 'Bycel purchase not found.');
        }

        $chosen = null;
        foreach ($ledger->candidatesFor($record) as $candidate) {
            if ((string) ($candidate['MerchantPurchaseId'] ?? '') === (string) $merchantPurchaseId) {
                $chosen = $candidate;
                break;
            }
        }

        if (!$chosen) {
            return back()->with('success', "Row {$merchantPurchaseId} is not one of the recorded candidates.");
        }

        // The stored candidate has its PIN redacted, so re-read the live report to
        // get the real code rather than writing "***redacted***" to the order.
        try {
            $live = $this->findLiveReportRow((int) $merchantPurchaseId);
        } catch (\Throwable $e) {
            Log::error('Bycel manual claim could not read the purchase report', ['error' => $e->getMessage()]);
            return back()->with('success', 'Could not read the Bycel purchase report just now — try again.');
        }

        if (!$live) {
            return back()->with('success', "Row {$merchantPurchaseId} is no longer visible in the Bycel report.");
        }

        try {
            $ledger->claim($record, (int) $merchantPurchaseId, $live);
        } catch (\Throwable $e) {
            return back()->with('success', $e->getMessage());
        }

        $order = Order::withoutGlobalScope('cms_draft_flag')->find($record->order_id);
        if ($order) {
            $pin = trim((string) ($live['PinCode'] ?? ''));
            if ($pin !== '') {
                // orders.code must contain <li> elements or the storefront renders nothing.
                $order->code = '<ul><li>' . e($pin) . '</li></ul>'
                    . (!empty($live['Serial']) ? '<p>Serial: ' . e((string) $live['Serial']) . '</p>' : '');
            }
            $order->external_order_id = $record->family . ':' . $merchantPurchaseId;
            $order->external_status = SupplierOrderResult::COMPLETED;
            $order->save();
        }

        return back()->with('success', "Bycel row {$merchantPurchaseId} assigned to order #{$record->order_id}.");
    }

    /**
     * Declare that no purchase happened for this intent, so the order can be
     * refunded through the normal path. Use only after checking the Bycel app.
     */
    public function abandonBycelPurchase(Request $request, $purchase, BycelPurchaseLedger $ledger, SupplierOrderFulfillment $fulfillment)
    {
        $record = BycelPurchase::find($purchase);
        if (!$record) {
            return back()->with('success', 'Bycel purchase not found.');
        }

        $ledger->markAbandoned($record, 'abandoned via supplier health page');

        $order = Order::withoutGlobalScope('cms_draft_flag')->find($record->order_id);
        if ($order) {
            $order->external_status = SupplierOrderResult::FAILED;
            $order->save();
            // Routes the refund through the same engine path every supplier uses.
            $fulfillment->refreshStatus($order);
        }

        return back()->with('success', "Bycel purchase #{$record->id} abandoned; order #{$record->order_id} refunded.");
    }

    /** @return array<string,mixed>|null */
    private function findLiveReportRow(int $merchantPurchaseId): ?array
    {
        $client = app(BycelClient::class);

        foreach ([20, 50, 100, 200] as $size) {
            foreach ($client->lastPinReport($size) as $row) {
                if (is_array($row) && (int) ($row['MerchantPurchaseId'] ?? 0) === $merchantPurchaseId) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * Re-dispatch fulfillment for one order.
     *
     * Safe to press repeatedly: SupplierOrderFulfillment::fulfill() is idempotent on
     * orders.external_order_uuid, so an order that did reach the supplier on a previous
     * attempt will not be placed twice.
     */
    public function retry(Request $request, $id)
    {
        $order = Order::withoutGlobalScope('cms_draft_flag')->find($id);

        if (!$order) {
            return back()->with('success', 'Order not found.');
        }

        if ((int) $order->statuses_id !== Order::STATUS_APPROVED) {
            return back()->with('success', "Order #{$id} is not approved, so there is nothing to send.");
        }

        if (filled($order->external_order_id)) {
            return back()->with('success', "Order #{$id} has already been placed with the supplier.");
        }

        FulfillSupplierOrderJob::dispatch($order->id);

        return back()->with('success', "Order #{$id} queued for fulfillment.");
    }

    /**
     * A supplier API that is slow or down must not take the whole page with it, so
     * each balance is cached and individually guarded. `null` renders as "unavailable"
     * rather than as zero — showing $0.00 for an unreachable API would be worse than
     * showing nothing, because $0.00 looks like a real, actionable answer.
     */
    private function balanceFor($connector): ?float
    {
        return Cache::remember(
            'cms:supplier-balance:' . $connector->key(),
            self::BALANCE_CACHE_SECONDS,
            fn () => $this->safely(fn () => $connector->balance(), null)
        );
    }

    private function safely(callable $callback, $fallback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            Log::warning('Supplier health check failed', ['exception' => $e]);

            return $fallback;
        }
    }
}
