<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Connectors\SwiftConnector;

/**
 * Imports / refreshes the SwiftServices catalog and keeps prices in sync.
 *   php artisan swift:sync              # discover categories + sync products & prices
 *   php artisan swift:sync --categories # only refresh the supplier category list
 */
class SyncSwiftCatalog extends SyncSupplierCatalogCommand
{
    protected $signature = 'swift:sync {--categories : Only discover/refresh supplier categories}';

    protected $description = 'Sync the SwiftServices catalog and prices into the local product tables';

    protected function supplierKey(): string
    {
        return SwiftConnector::KEY;
    }
}
