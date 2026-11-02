<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Connectors\AlloSharejleConnector;

/**
 * Polls Allo Sharejle for the status of placed orders still pending and applies
 * the result locally (refund + reject when the supplier rejects).
 *   php artisan allosharejle:check-orders
 */
class CheckAlloSharejleOrders extends CheckSupplierOrdersCommand
{
    protected $signature = 'allosharejle:check-orders {--limit=100 : Max orders to poll per run}';

    protected $description = 'Poll pending Allo Sharejle supplier orders and update their status';

    protected function supplierKey(): string
    {
        return AlloSharejleConnector::KEY;
    }
}
