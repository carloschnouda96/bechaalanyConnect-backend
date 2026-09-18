<?php

namespace Tests\Concerns;

use App\Category;
use App\Product;
use App\ProductsVariation;
use App\Subcategory;

/**
 * Builds the minimum catalog chain an order needs.
 *
 * Since `orders.product_variation_id` gained a foreign key, a test can no longer
 * invent an id — which is the constraint doing its job. This creates a real
 * category → subcategory → product → variation so tests exercise the same
 * referential integrity production does.
 *
 * Translations are inserted directly rather than through the translatable trait so
 * the helper does not depend on a configured locale.
 */
trait CreatesCatalog
{
    /**
     * @param string|null $supplierStatus One of Product::SUPPLIER_* to build a
     *     supplier-managed row (also sets a dummy `external_source`), or null
     *     for a plain platform row (the default — matches every existing
     *     caller). See Product::scopeSellable().
     */
    protected function createVariation(
        float $price = 5.00,
        int $productTypeId = 2,
        bool $active = true,
        ?string $supplierStatus = null
    ): ProductsVariation {
        $suffix = uniqid();

        $category = Category::create([
            'slug' => 'cat-' . $suffix,
            'is_active' => 1,
        ]);

        $subcategory = Subcategory::create([
            'slug' => 'sub-' . $suffix,
            'category_id' => $category->id,
            'is_active' => 1,
        ]);

        $product = Product::create([
            'slug' => 'prod-' . $suffix,
            'subcategory_id' => $subcategory->id,
            'product_type_id' => $productTypeId,
            'is_active' => $active ? 1 : 0,
            'external_source' => $supplierStatus !== null ? 'test-supplier' : null,
            'external_id' => $supplierStatus !== null ? 'ext-' . $suffix : null,
            'supplier_status' => $supplierStatus,
        ]);

        return ProductsVariation::create([
            'slug' => 'var-' . $suffix,
            'product_id' => $product->id,
            'price' => $price,
            'cost_price' => round($price / 2, 2),
            'is_active' => $active ? 1 : 0,
            'supplier_status' => $supplierStatus,
        ]);
    }
}
