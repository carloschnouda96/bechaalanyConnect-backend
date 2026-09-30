<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Shared body for the per-supplier `*:sync` commands. Subclasses only declare
 * their own signature/description and return their supplier key; the connector
 * is resolved from the registry and run through the generic SupplierCatalogSync.
 */
abstract class SyncSupplierCatalogCommand extends Command
{
    /**
     * How long a run may hold its lock before another may start anyway (a crashed
     * process never releases it). Well above the longest observed sync.
     */
    public const LOCK_SECONDS = 3600;

    public static function lockName(string $key): string
    {
        return 'supplier-sync:' . $key;
    }

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

        // One run per supplier at a time. Three things start this command — the
        // scheduler, the HTTP cron URL (CronController, which has no overlap guard of its
        // own) and the CMS "Sync now" button — and two concurrent runs of one catalog
        // would race each other's creates and withdrawals.
        $lock = Cache::lock(self::lockName($key), self::LOCK_SECONDS);

        if (!$lock->get()) {
            $this->warn(ucfirst($key) . ' sync is already running — skipped.');
            return self::SUCCESS;
        }

        $categoriesOnly = (bool) $this->option('categories');
        $this->info($categoriesOnly ? "Discovering {$key} categories…" : "Syncing {$key} catalog & prices…");

        try {
            $summary = $sync->sync($connector, $categoriesOnly);
        } catch (\Throwable $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            return self::FAILURE;
        } finally {
            $lock->release();
        }

        $this->table(array_keys($summary), [array_values($summary)]);

        return self::SUCCESS;
    }
}
