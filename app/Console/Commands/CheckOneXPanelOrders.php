<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Connectors\OneXPanelConnector;

/**
 * Polls 1xpanel for the status of placed orders still pending and applies the
 * result locally (refund + reject on a Canceled/Fail supplier outcome).
 *   php artisan 1xpanel:check-orders
 */
class CheckOneXPanelOrders extends CheckSupplierOrdersCommand
{
    protected $signature = '1xpanel:check-orders {--limit=100 : Max orders to poll per run}';

    protected $description = 'Poll pending 1xpanel supplier orders and update their status';

    protected function supplierKey(): string
    {
        return OneXPanelConnector::KEY;
    }
}
