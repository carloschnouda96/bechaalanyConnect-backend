<?php

namespace App\Services\Suppliers\Contracts;

use App\Order;
use App\ProductsVariation;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierProduct;

/**
 * A supplier adapter. One implementation per upstream API (YassenConnector,
 * SwiftConnector, …); a product's `external_source` selects which one handles it.
 *
 * The connector owns everything supplier-specific (auth, pricing units, field
 * names, status vocabulary) and exposes it through this normalised contract so
 * SupplierCatalogSync / SupplierOrderFulfillment stay supplier-agnostic.
 */
interface SupplierConnector
{
    /** Stable key stored in external_source (e.g. 'yassen', 'swift'). */
    public function key(): string;

    /** Master switch from config (SYNC_ENABLED). Sync/fulfillment no-op when false. */
    public function isEnabled(): bool;

    /** Whether the credentials/base URL needed to call the API are present. */
    public function isConfigured(): bool;

    /**
     * Pull the supplier catalog and normalise it.
     *
     * @return SupplierProduct[]
     */
    public function fetchCatalog(): array;

    /** Place the supplier order for a local order + its variation. */
    public function placeOrder(Order $order, ProductsVariation $variation): SupplierOrderResult;

    /** Poll the current status of an already-placed supplier order. */
    public function checkOrder(Order $order): SupplierOrderResult;

    /** Account balance, when the API exposes one (null on failure/unsupported). */
    public function balance(): ?float;
}
