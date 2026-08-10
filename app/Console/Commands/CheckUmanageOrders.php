<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Connectors\UmanageConnector;

/**
 * Polls U-Manage for the status of placed orders still pending and applies the
 * result locally (refund + reject on a failed supplier outcome). Bundles and SMS
 * orders are genuinely asynchronous (`processing`/`pending` → `completed`), so
 * unlike usharez this poll does real work.
 *
 * NOTE the lower default limit: U-Manage allows 1000 requests/hour per API key,
 * and this command runs every 5 minutes. At the usual --limit=100 that would be
 * up to 1200 requests/hour on polling alone — over the cap. 40 keeps polling at
 * roughly 480/hour with headroom for the hourly catalog sync.
 *   php artisan umanage:check-orders
 */
class CheckUmanageOrders extends CheckSupplierOrdersCommand
{
    protected $signature = 'umanage:check-orders {--limit=40 : Max orders to poll per run (rate limit: 1000 req/hour)}';

    protected $description = 'Poll pending U-Manage supplier orders and update their status';

    protected function supplierKey(): string
    {
        return UmanageConnector::KEY;
    }
}
