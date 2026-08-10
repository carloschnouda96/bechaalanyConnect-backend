<?php

namespace App\Services\Suppliers\Contracts;

/**
 * Implemented by connectors whose catalog is assembled from several independent
 * upstream endpoints ("families"). When one family's endpoint fails, the
 * connector returns a PARTIAL catalog — and SupplierCatalogSync::deactivateStale()
 * would otherwise read the missing family's absence as "no longer offered" and
 * deactivate every one of its products.
 *
 * catalogScopes() reports the `external_id` prefixes the last fetchCatalog() call
 * actually managed to fetch, so deactivation can be limited to those. Returning
 * null means "the whole catalog was fetched" — the normal single-family case, and
 * what every non-implementing connector (Yassen, Swift, 1xpanel) implies.
 */
interface PartialCatalogAware
{
    /**
     * External-id prefixes vouched for by the last fetchCatalog() call, e.g.
     * ['quota:']. An empty array means nothing could be verified this run, so
     * nothing should be deactivated. Null means the catalog is complete.
     *
     * @return string[]|null
     */
    public function catalogScopes(): ?array;
}
