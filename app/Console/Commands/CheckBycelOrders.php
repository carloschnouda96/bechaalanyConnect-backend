<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Connectors\BycelConnector;

/**
 * Re-runs PIN claiming for Bycel orders still pending.
 *
 * Unlike the other suppliers' check-orders commands this is not really a status
 * poll — Bycel has no order-status endpoint. It re-drives BycelPinResolver against
 * the persisted intent row, which is how a voucher bought moments before the PIN
 * materialised (or a purchase interrupted by a crash) eventually reaches the
 * customer.
 *   php artisan bycel:check-orders
 */
class CheckBycelOrders extends CheckSupplierOrdersCommand
{
    protected $signature = 'bycel:check-orders {--limit=40 : Max orders to resolve per run}';

    protected $description = 'Resolve pending Bycel orders by claiming their PIN from the purchase report';

    protected function supplierKey(): string
    {
        return BycelConnector::KEY;
    }
}
