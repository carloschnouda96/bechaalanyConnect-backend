<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Connectors\SwiftConnector;

/**
 * Polls SwiftServices for the status of placed orders still pending and applies
 * the result locally (refund + reject on a Canceled/Fail supplier outcome).
 *   php artisan swift:check-orders
 */
class CheckSwiftOrders extends CheckSupplierOrdersCommand
{
    protected $signature = 'swift:check-orders {--limit=100 : Max orders to poll per run}';

    protected $description = 'Poll pending SwiftServices supplier orders and update their status';

    protected function supplierKey(): string
    {
        return SwiftConnector::KEY;
    }
}
