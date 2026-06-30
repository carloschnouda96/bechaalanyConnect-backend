<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Connectors\OneXPanelConnector;

/**
 * Imports / refreshes the 1xpanel catalog and keeps prices in sync.
 *   php artisan 1xpanel:sync              # discover categories + sync products & prices
 *   php artisan 1xpanel:sync --categories # only refresh the supplier category list
 */
class SyncOneXPanelCatalog extends SyncSupplierCatalogCommand
{
    protected $signature = '1xpanel:sync {--categories : Only discover/refresh supplier categories}';

    protected $description = 'Sync the 1xpanel catalog and prices into the local product tables';

    protected function supplierKey(): string
    {
        return OneXPanelConnector::KEY;
    }
}
