<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Connectors\UmanageConnector;

/**
 * Imports / refreshes the U-Manage catalog (data bundles, SMS credits, SMS gifts,
 * telecom vouchers and direct recharge) and keeps prices in sync.
 *   php artisan umanage:sync              # discover categories + sync products & prices
 *   php artisan umanage:sync --categories # only refresh the supplier category list
 */
class SyncUmanageCatalog extends SyncSupplierCatalogCommand
{
    protected $signature = 'umanage:sync {--categories : Only discover/refresh supplier categories}';

    protected $description = 'Sync the U-Manage bundle / SMS / telecom catalog and prices into the local product tables';

    protected function supplierKey(): string
    {
        return UmanageConnector::KEY;
    }
}
