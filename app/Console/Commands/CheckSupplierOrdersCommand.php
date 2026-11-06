<?php

namespace App\Console\Commands;

use App\Order;
use App\Services\Suppliers\Connectors\YassenConnector;
use App\Services\Suppliers\SupplierOrderFulfillment;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Shared body for the per-supplier `*:check-orders` commands. Polls this
 * supplier's still-pending orders and applies the result locally (refund +
 * reject on a FAILED supplier outcome). Subclasses just supply the supplier key.
 */
abstract class CheckSupplierOrdersCommand extends Command
{
    /** Connector key from the SupplierRegistry (e.g. 'yassen', 'swift'). */
    abstract protected function supplierKey(): string;

    public function handle(SupplierRegistry $registry, SupplierOrderFulfillment $fulfillment): int
    {
        $key = $this->supplierKey();
        $connector = $registry->get($key);

        if (!$connector) {
            $this->error("Unknown supplier: {$key}.");
            return self::FAILURE;
        }
        if (!$connector->isEnabled()) {
            $this->warn(ucfirst($key) . ' sync is disabled (set ' . strtoupper($key) . '_SYNC_ENABLED=true to enable).');
            return self::SUCCESS;
        }
        if (!$connector->isConfigured()) {
            $this->error(ucfirst($key) . ' API is not configured (missing credentials).');
            return self::FAILURE;
        }

        $orders = Order::withoutGlobalScope('cms_draft_flag')
            ->where('external_source', $key)
            ->where('external_status', SupplierOrderResult::PENDING)
            ->where(function ($q) {
                $q->whereNotNull('external_order_uuid')->orWhereNotNull('external_order_id');
            })
            // A parked order is not polled: staff resolve it. Yassen's platform can be asked
            // by uuid, so its parked orders stay in.
            ->where(function ($q) use ($connector) {
                $q->whereNull('external_order_id')
                    ->orWhere('external_order_id', 'not like', SupplierOrderResult::PARKED_ID_PREFIX . '%')
                    ->when($connector instanceof YassenConnector, fn ($q) => $q->orWhereNotNull('external_order_uuid'));
            })
            // Whichever has waited longest. Sorting never-polled rows first let a burst of
            // new orders take every slot from orders already being checked.
            ->orderByRaw('COALESCE(last_polled_at, created_at), id')
            ->limit((int) $this->option('limit'))
            ->get();

        $this->reportStaleParkedOrders($key);

        $this->info("Checking {$orders->count()} pending {$key} order(s)…");

        $resolved = 0;
        foreach ($orders as $order) {
            try {
                $fulfillment->refreshStatus($order);
                if ($order->external_status !== SupplierOrderResult::PENDING) {
                    $resolved++;
                }
            } catch (\Throwable $e) {
                $this->error("Order #{$order->id}: " . $e->getMessage());
            }

            // Written directly: a poll is not a change to the order.
            Order::withoutGlobalScope('cms_draft_flag')->whereKey($order->id)->update(['last_polled_at' => now()]);
        }

        $this->info("Resolved {$resolved} order(s).");

        return self::SUCCESS;
    }

    /**
     * A parked id is written before the supplier call, so a worker that died mid-request
     * leaves one with no log line and no poll. Nothing else would ever surface it.
     */
    private function reportStaleParkedOrders(string $key): void
    {
        $ids = Order::withoutGlobalScope('cms_draft_flag')
            ->where('external_source', $key)
            ->where('external_status', SupplierOrderResult::PENDING)
            ->where('external_order_id', 'like', SupplierOrderResult::PARKED_ID_PREFIX . '%')
            // Rejecting is how staff settle one, and it must keep its id so it is never re-sent.
            ->where('statuses_id', Order::STATUS_APPROVED)
            ->where('created_at', '<', now()->subHour())
            ->orderBy('id')
            ->limit(50)
            ->pluck('id')
            ->all();

        if ($ids) {
            Log::critical('Supplier orders parked for over an hour: check them on Supplier health', [
                'source' => $key,
                'order_ids' => $ids,
            ]);
        }
    }
}
