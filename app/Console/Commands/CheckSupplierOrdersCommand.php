<?php

namespace App\Console\Commands;

use App\Order;
use App\Services\Suppliers\SupplierOrderFulfillment;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierRegistry;
use Illuminate\Console\Command;

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
            ->limit((int) $this->option('limit'))
            ->get();

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
        }

        $this->info("Resolved {$resolved} order(s).");

        return self::SUCCESS;
    }
}
