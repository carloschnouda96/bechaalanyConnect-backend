<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Connectors\YassenConnector;

/**
 * Imports / refreshes the Yassen catalog and keeps prices in sync.
 *   php artisan yassen:sync              # discover categories + sync products & prices
 *   php artisan yassen:sync --categories # only refresh the supplier category list
 *
 * Signature unchanged; the work runs through the generic SupplierCatalogSync.
 */
class SyncYassenCatalog extends SyncSupplierCatalogCommand
{
    protected $signature = 'yassen:sync {--categories : Only discover/refresh supplier categories}';

    protected $description = 'Sync the Yassen-Card catalog and prices into the local product tables';

    protected function supplierKey(): string
    {
        return YassenConnector::KEY;
    }
}
