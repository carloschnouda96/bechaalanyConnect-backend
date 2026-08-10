<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Connectors\UsharezConnector;

/**
 * Imports / refreshes the usharez catalog (quota bundles + Alfa Gifts) and keeps
 * prices in sync.
 *   php artisan usharez:sync              # discover categories + sync products & prices
 *   php artisan usharez:sync --categories # only refresh the supplier category list
 */
class SyncUsharezCatalog extends SyncSupplierCatalogCommand
{
    protected $signature = 'usharez:sync {--categories : Only discover/refresh supplier categories}';

    protected $description = 'Sync the usharez quota & Alfa Gifts catalog and prices into the local product tables';

    protected function supplierKey(): string
    {
        return UsharezConnector::KEY;
    }
}
