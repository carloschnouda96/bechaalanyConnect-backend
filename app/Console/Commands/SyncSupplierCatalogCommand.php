<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierRegistry;
use Illuminate\Console\Command;

/**
 * Shared body for the per-supplier `*:sync` commands. Subclasses only declare
 * their own signature/description and return their supplier key; the connector
 * is resolved from the registry and run through the generic SupplierCatalogSync.
 */
abstract class SyncSupplierCatalogCommand extends Command
{
    /** Connector key from the SupplierRegistry (e.g. 'yassen', 'swift'). */
    abstract protected function supplierKey(): string;

    public function handle(SupplierRegistry $registry, SupplierCatalogSync $sync): int
    {
        $key = $this->supplierKey();
        $connector = $registry->get($key);

        if (!$connector) {
            $this->error("Unknown supplier: {$key}.");
            return self::FAILURE;
        }
        if (!$connector->isEnabled()) {
            $this->warn(ucfirst($key) . ' sync is disabled (set ' . strtoupper($key) . '_SYNC_ENABLED=true to enable).');
            return self::SUCCESS;
        }
        if (!$connector->isConfigured()) {
            $this->error(ucfirst($key) . ' API is not configured (missing credentials).');
            return self::FAILURE;
        }

        $categoriesOnly = (bool) $this->option('categories');
        $this->info($categoriesOnly ? "Discovering {$key} categories…" : "Syncing {$key} catalog & prices…");

        try {
            $summary = $sync->sync($connector, $categoriesOnly);
        } catch (\Throwable $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->table(array_keys($summary), [array_values($summary)]);

        return self::SUCCESS;
    }
}
