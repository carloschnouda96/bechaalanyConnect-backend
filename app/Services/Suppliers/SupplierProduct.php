<?php

namespace App\Services\Suppliers;

/**
 * Normalised view of a single supplier product, produced by every connector's
 * fetchCatalog(). This is the one vocabulary SupplierCatalogSync understands —
 * each supplier's quirks (Yassen per-unit pricing vs Swift per-1000 rates,
 * `available` flags vs presence-implies-available, differing field names) are
 * absorbed by the connector before this DTO is built.
 */
class SupplierProduct
{
    public function __construct(
        /** Supplier's own product/service id (stored as external_id). */
        public string $externalId,
        public string $name,
        /** Supplier category id used to group/allow-list imports. */
        public string $categoryExternalId,
        public string $categoryName,
        public ?string $categoryImage,
        /** Cost for ONE unit, already normalised (e.g. Swift rate / 1000). */
        public float $unitCost,
        /** Whether the supplier currently offers this product. */
        public bool $available,
        /** Local product_type_id driving the storefront purchase form. */
        public int $productTypeId,
        /** Allowed amounts / {min,max} range for the quantity selector, or null. */
        public ?array $qtyValues = null,
        /** Supplier-native type label kept for reference (e.g. "package", "Default"). */
        public ?string $externalType = null,
    ) {
    }
}
