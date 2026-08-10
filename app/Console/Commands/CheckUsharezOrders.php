<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Connectors\UsharezConnector;

/**
 * usharez has NO order-status endpoint — both purchase POSTs are synchronous, so
 * UsharezConnector::checkOrder() echoes the stored status without any HTTP call
 * and this command is effectively a no-op.
 *
 * It exists because CronController composes "{key}:check-orders" for EVERY
 * enabled supplier; without it, /api/cron/suppliers/check-orders would throw once
 * usharez is enabled. It doubles as a report: the only usharez orders it can find
 * are ones parked PENDING by an indeterminate POST, which need reconciling.
 *   php artisan usharez:check-orders
 */
class CheckUsharezOrders extends CheckSupplierOrdersCommand
{
    protected $signature = 'usharez:check-orders {--limit=100 : Max orders to poll per run}';

    protected $description = 'List usharez orders awaiting manual reconciliation (usharez exposes no status endpoint)';

    protected function supplierKey(): string
    {
        return UsharezConnector::KEY;
    }
}
