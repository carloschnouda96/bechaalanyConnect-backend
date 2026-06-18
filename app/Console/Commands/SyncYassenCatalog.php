<?php

namespace App\Console\Commands;

use App\Services\Yassen\YassenCatalogSync;
use App\Services\Yassen\YassenClient;
use Illuminate\Console\Command;

/**
 * Imports / refreshes the Yassen catalog and keeps prices in sync.
 *   php artisan yassen:sync              # discover categories + sync products & prices
 *   php artisan yassen:sync --categories # only refresh the supplier category list
 */
class SyncYassenCatalog extends Command
{
    protected $signature = 'yassen:sync {--categories : Only discover/refresh supplier categories}';

    protected $description = 'Sync the Yassen-Card catalog and prices into the local product tables';

    public function handle(YassenClient $client, YassenCatalogSync $sync): int
    {
        if (!config('services.yassen.enabled')) {
            $this->warn('Yassen sync is disabled (set YASSEN_SYNC_ENABLED=true to enable).');
            return self::SUCCESS;
        }

        if (!$client->isConfigured()) {
            $this->error('Yassen API is not configured (missing YASSEN_API_TOKEN).');
            return self::FAILURE;
        }

        $categoriesOnly = (bool) $this->option('categories');
        $this->info($categoriesOnly ? 'Discovering Yassen categories…' : 'Syncing Yassen catalog & prices…');

        try {
            $summary = $sync->sync($categoriesOnly);
        } catch (\Throwable $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->table(array_keys($summary), [array_values($summary)]);

        return self::SUCCESS;
    }
}
