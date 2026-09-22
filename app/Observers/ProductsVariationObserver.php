<?php

namespace App\Observers;

use App\ProductsVariation;

/**
 * Auto-locks a supplier-sourced variation's Price the moment anyone other than
 * the sync engine changes it.
 *
 * SupplierCatalogSync::upsertVariation() and Product::recalculateSupplierPrices()
 * are the only two places allowed to keep pushing `cost x profit%` into `price`.
 * Both wrap their own writes in ProductsVariation::applyingSystemPricing(), which
 * flips a static guard for the duration of that save(). Any OTHER update that
 * leaves `price` dirty — a CMS edit on the Products Variation page, tinker, a
 * future admin tool — trips this observer instead, and `manual_price` is set so
 * the sync stops touching that row's price from then on (it still keeps
 * `cost_price`/`external_price`/`supplier_status` current).
 *
 * Deliberately `updating`, not `saving`: a brand new row's initial Price (set by
 * the sync on first import, by a CSV import, or by a fixture/test creating a
 * supplier-sourced variation directly) must not count as an "edit" — only a
 * change to an already-persisted row should lock it. Using `saving` here would
 * lock every supplier variation from birth and break bulk repricing.
 *
 * Registered here rather than as a booted() hook: the hellotree CMS regenerates
 * every model file above its custom-function markers on a page-schema save,
 * which would silently drop a hook declared inside the model.
 */
class ProductsVariationObserver
{
    public function updating(ProductsVariation $variation): void
    {
        if ($variation->isDirty('price') && !ProductsVariation::isApplyingSystemPricing()) {
            $variation->manual_price = 1;
        }
    }
}
