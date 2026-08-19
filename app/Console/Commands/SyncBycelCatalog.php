<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Connectors\BycelConnector;

/**
 * Imports / refreshes the Bycel (Power Group) catalog — Alfa and Touch products,
 * each sellable as a voucher and/or as a direct recharge.
 *   php artisan bycel:sync              # discover categories + sync products & prices
 *   php artisan bycel:sync --categories # only refresh the supplier category list
 */
class SyncBycelCatalog extends SyncSupplierCatalogCommand
{
    protected $signature = 'bycel:sync {--categories : Only discover/refresh supplier categories}';

    protected $description = 'Sync the Bycel voucher & direct-recharge catalog and prices into the local product tables';

    protected function supplierKey(): string
    {
        return BycelConnector::KEY;
    }
}
