<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Connectors\UsharezConnector;

/**
 * Polls usharez v2 quota jobs ("quota:{jobId}", GET /api/v2/quota/admin/status/{jobId})
 * until they are terminal: succeeded writes the admin number to orders.code,
 * rejected/failed refunds the customer and rejects the order (see
 * UsharezConnector::checkOrder for `stopped`).
 *
 * Gift purchases are synchronous and orders parked by an indeterminate POST
 * carry a synthetic "usharez-…" id; both are echoed without any HTTP call and
 * stay listed here until an admin resolves them.
 *   php artisan usharez:check-orders
 */
class CheckUsharezOrders extends CheckSupplierOrdersCommand
{
    protected $signature = 'usharez:check-orders {--limit=100 : Max orders to poll per run}';

    protected $description = 'Poll pending usharez quota jobs and apply their outcome';

    protected function supplierKey(): string
    {
        return UsharezConnector::KEY;
    }
}
