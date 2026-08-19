<?php

namespace App\Services\Cms;

use App\CreditsTransfer;
use App\Models\User;
use App\Order;
use App\Services\Suppliers\SupplierRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Numbers for the CMS home page.
 *
 * The CMS landing page was blank (`hellotree.home_content` is an empty string), so an
 * admin signing in had no idea whether anything needed their attention: no pending
 * order count, no pending KYC queue, no revenue, no indication that a supplier order
 * had failed to place. Everything had to be discovered by opening each page in turn.
 *
 * Each figure is cached briefly — the page is reloaded often and none of these need
 * to be second-accurate — and each is individually fault-tolerant, so one broken
 * query cannot blank the whole dashboard.
 */
class DashboardMetrics
{
    private const CACHE_SECONDS = 60;

    public function all(): array
    {
        return [
            'queues' => $this->queues(),
            'revenue' => $this->revenue(),
            'health' => $this->health(),
        ];
    }

    /** Things waiting on a human. These are the reason to open the CMS at all. */
    public function queues(): array
    {
        return $this->remember('queues', fn () => [
            'pending_orders' => Order::where('statuses_id', Order::STATUS_PENDING)->count(),
            'pending_topups' => CreditsTransfer::where('statuses_id', CreditsTransfer::STATUS_PENDING)->count(),
            'pending_kyc' => User::where('verification_statuses_id', User::VERIFICATION_PENDING)->count(),
        ]);
    }

    /**
     * Revenue and profit for today and month-to-date, over approved orders.
     *
     * Only meaningful now that orders.total_price is DECIMAL: while it was LONGTEXT,
     * MySQL coerced any non-numeric row to 0 inside SUM() and quietly under-reported.
     */
    public function revenue(): array
    {
        return $this->remember('revenue', function () {
            $row = function ($from) {
                $result = Order::where('orders.statuses_id', Order::STATUS_APPROVED)
                    ->when($from, fn ($q) => $q->where('orders.created_at', '>=', $from))
                    ->leftJoin('products_variations', 'orders.product_variation_id', '=', 'products_variations.id')
                    ->selectRaw('COUNT(*) AS orders_count')
                    ->selectRaw('COALESCE(SUM(orders.total_price), 0) AS revenue')
                    ->selectRaw('COALESCE(SUM(orders.total_price - (COALESCE(products_variations.cost_price,0) * COALESCE(orders.quantity,1))), 0) AS profit')
                    ->first();

                return [
                    'orders' => (int) ($result->orders_count ?? 0),
                    'revenue' => (float) ($result->revenue ?? 0),
                    'profit' => (float) ($result->profit ?? 0),
                ];
            };

            return [
                'today' => $row(now()->startOfDay()),
                'month' => $row(now()->startOfMonth()),
                'all_time' => $row(null),
            ];
        });
    }

    /** Things that are quietly wrong and would otherwise go unnoticed. */
    public function health(): array
    {
        return $this->remember('health', function () {
            return [
                // Balances the ledger cannot explain: money moved somewhere that did
                // not record itself.
                'ledger_drift' => $this->ledgerDrift(),

                // Supplier orders an admin approved that were never actually placed.
                // The documented cause is U-Manage returning 429: the job's 3 retries
                // at 30s are exhausted long before the API's requested 1h backoff, so
                // the order sits Approved-but-unplaced with nothing surfacing it.
                'unplaced_supplier_orders' => Order::where('statuses_id', Order::STATUS_APPROVED)
                    ->whereNotNull('external_source')
                    ->whereNull('external_order_id')
                    ->count(),

                // Supplier orders the supplier itself reported as failed.
                'failed_supplier_orders' => Order::where('external_status', 'failed')->count(),

                // decimal(12,2) tops out at 999,999,999.99; flag anyone approaching a
                // point where cumulative totals start to look suspicious.
                'balances_near_ceiling' => User::where('credits_balance', '>=', 900000)->count(),

                'queued_jobs' => $this->queuedJobs(),
                'suppliers' => $this->supplierStates(),
            ];
        });
    }

    private function ledgerDrift(): int
    {
        try {
            return DB::table('users')
                ->leftJoin('credit_ledger_entries', 'credit_ledger_entries.user_id', '=', 'users.id')
                ->whereNull('users.deleted_at')
                ->groupBy('users.id', 'users.credits_balance')
                ->havingRaw('ABS(users.credits_balance - COALESCE(SUM(credit_ledger_entries.amount), 0)) > 0.0001')
                ->select('users.id')
                ->get()
                ->count();
        } catch (\Throwable $e) {
            Log::warning('Dashboard: ledger drift check failed', ['exception' => $e]);

            return 0;
        }
    }

    private function queuedJobs(): ?int
    {
        try {
            return DB::table('jobs')->count();
        } catch (\Throwable $e) {
            return null; // table absent when QUEUE_CONNECTION is still sync
        }
    }

    /**
     * Which suppliers are switched on. Wallet balances are deliberately NOT fetched
     * here — they are live HTTP calls to third parties and belong on the supplier
     * health page, where a slow or hanging API delays only that page.
     */
    private function supplierStates(): array
    {
        try {
            return collect(app(SupplierRegistry::class)->enabled())
                ->map(fn ($connector) => $connector->key())
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('Dashboard: supplier registry unavailable', ['exception' => $e]);

            return [];
        }
    }

    /**
     * Cache each group separately and never let one failure take down the page —
     * a blank dashboard is worse than a dashboard with one missing tile.
     */
    private function remember(string $key, callable $callback): array
    {
        try {
            return Cache::remember("cms:dashboard:{$key}", self::CACHE_SECONDS, $callback);
        } catch (\Throwable $e) {
            Log::error("Dashboard metric '{$key}' failed", ['exception' => $e]);

            return [];
        }
    }
}
