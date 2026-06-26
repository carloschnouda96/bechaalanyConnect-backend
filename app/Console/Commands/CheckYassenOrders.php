<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Connectors\YassenConnector;

/**
 * Polls Yassen for the status of placed orders still pending and applies the
 * result locally (refund + reject when the supplier rejects).
 *   php artisan yassen:check-orders
 *
 * Signature unchanged; the work runs through the generic SupplierOrderFulfillment.
 */
class CheckYassenOrders extends CheckSupplierOrdersCommand
{
    protected $signature = 'yassen:check-orders {--limit=100 : Max orders to poll per run}';

    protected $description = 'Poll pending Yassen supplier orders and update their status';

    protected function supplierKey(): string
    {
        return YassenConnector::KEY;
    }
}
