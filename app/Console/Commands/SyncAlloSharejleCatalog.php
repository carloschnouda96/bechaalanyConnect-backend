<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Connectors\AlloSharejleConnector;

/**
 * Imports / refreshes the Allo Sharejle catalog and keeps prices in sync.
 *   php artisan allosharejle:sync              # discover categories + sync products & prices
 *   php artisan allosharejle:sync --categories # only refresh the supplier category list
 */
class SyncAlloSharejleCatalog extends SyncSupplierCatalogCommand
{
    protected $signature = 'allosharejle:sync {--categories : Only discover/refresh supplier categories}';

    protected $description = 'Sync the Allo Sharejle catalog and prices into the local product tables';

    protected function supplierKey(): string
    {
        return AlloSharejleConnector::KEY;
    }
}
