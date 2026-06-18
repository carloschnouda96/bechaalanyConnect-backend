<?php

namespace App\Console\Commands;

use App\Order;
use App\Services\Yassen\YassenCatalogSync;
use App\Services\Yassen\YassenClient;
use App\Services\Yassen\YassenOrderFulfillment;
use Illuminate\Console\Command;

/**
 * Polls Yassen for the status of placed orders still in `wait` and applies the
 * result locally (refund + reject when the supplier rejects).
 *   php artisan yassen:check-orders
 */
class CheckYassenOrders extends Command
{
    protected $signature = 'yassen:check-orders {--limit=100 : Max orders to poll per run}';

    protected $description = 'Poll pending Yassen supplier orders and update their status';

    public function handle(YassenClient $client, YassenOrderFulfillment $fulfillment): int
    {
        if (!config('services.yassen.enabled')) {
            $this->warn('Yassen sync is disabled (set YASSEN_SYNC_ENABLED=true to enable).');
            return self::SUCCESS;
        }

        if (!$client->isConfigured()) {
            $this->error('Yassen API is not configured (missing YASSEN_API_TOKEN).');
            return self::FAILURE;
        }

        $orders = Order::withoutGlobalScope('cms_draft_flag')
            ->where('external_source', YassenCatalogSync::SOURCE)
            ->where('external_status', 'wait')
            ->whereNotNull('external_order_uuid')
            ->limit((int) $this->option('limit'))
            ->get();

        $this->info("Checking {$orders->count()} pending Yassen order(s)…");

        $resolved = 0;
        foreach ($orders as $order) {
            try {
                $fulfillment->refreshStatus($order);
                if ($order->external_status !== 'wait') {
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
