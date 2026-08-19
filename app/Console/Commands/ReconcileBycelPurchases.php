<?php

namespace App\Console\Commands;

use App\BycelPurchase;
use App\Order;
use App\Services\Bycel\BycelPurchaseLedger;
use App\Services\Suppliers\SupplierOrderFulfillment;
use Illuminate\Console\Command;

/**
 * Sweeps Bycel purchase intents that never reached an outcome.
 *
 * An intent is left open when the PIN had not materialised in time, when a POST
 * was indeterminate (dropped connection), or when the process died mid-purchase.
 * Re-running the resolver against the persisted watermark either claims the row —
 * delivering the customer's code late but correctly — or, once a LATER intent has
 * closed the window and a coverage-proven report still shows nothing, proves the
 * purchase never happened so the order can be refunded.
 *
 * Reports by default; only touches orders with --auto-fail, because the refund it
 * can trigger is irreversible.
 *   php artisan bycel:reconcile
 *   php artisan bycel:reconcile --minutes=15 --auto-fail
 */
class ReconcileBycelPurchases extends Command
{
    protected $signature = 'bycel:reconcile
        {--minutes=15 : Only sweep intents older than this}
        {--limit=50 : Max intents per run}
        {--auto-fail : Apply outcomes to orders (may refund); otherwise report only}';

    protected $description = 'Resolve or report Bycel purchases whose outcome was never settled';

    public function handle(BycelPurchaseLedger $ledger, SupplierOrderFulfillment $fulfillment): int
    {
        $open = $ledger->findOpenOlderThan((int) $this->option('minutes'), (int) $this->option('limit'));

        if ($open->isEmpty()) {
            $this->info('No unsettled Bycel purchases.');
            return self::SUCCESS;
        }

        $apply = (bool) $this->option('auto-fail');
        $this->info("Found {$open->count()} unsettled Bycel purchase(s)" . ($apply ? ' — applying outcomes.' : ' — reporting only (pass --auto-fail to apply).'));

        $rows = [];
        foreach ($open as $purchase) {
            $order = Order::withoutGlobalScope('cms_draft_flag')->find($purchase->order_id);
            $before = $purchase->state;

            if ($order && $apply) {
                try {
                    // Routes through the connector's checkOrder(), so a FAILED
                    // outcome refunds the customer via the normal engine path.
                    $fulfillment->refreshStatus($order);
                    $purchase->refresh();
                } catch (\Throwable $e) {
                    $this->warn("  purchase #{$purchase->id}: {$e->getMessage()}");
                }
            }

            $rows[] = [
                $purchase->id,
                $purchase->order_id,
                $purchase->family,
                $before,
                $apply ? $purchase->state : '—',
                $purchase->merchant_purchase_id ?: '—',
                mb_substr((string) $purchase->resolver_reason, 0, 42),
            ];
        }

        $this->table(['intent', 'order', 'family', 'state', 'after', 'claimed', 'reason'], $rows);

        $stuck = $open->filter(fn (BycelPurchase $p) => $p->state === BycelPurchase::STATE_AMBIGUOUS)->count();
        if ($stuck > 0) {
            $this->warn("{$stuck} purchase(s) are AMBIGUOUS and need a human to pick the right PIN.");
        }

        return self::SUCCESS;
    }
}
