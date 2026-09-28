<?php

namespace App\Observers;

use App\ProductsVariation;

/**
 * Owns the two ways a variation's Price can change hands.
 *
 * 1. Profit % edited → Price is recomputed from Cost price x the new profit %
 *    and `manual_price` is cleared, handing Price back to the supplier sync.
 *    Profit wins even when Price was edited in the same save: the admin just
 *    told us which markup they want, and a Price that disagrees with it would
 *    be reverted by nothing and explained by nothing. A variation with no cost
 *    recorded (hand-priced) has nothing to derive from, so its Price is left
 *    alone and only the profit % is stored.
 *
 * 2. Price edited by anyone other than the sync engine → the row is locked.
 *    SupplierCatalogSync::upsertVariation() is the only place allowed to keep
 *    pushing `cost x profit%` into `price`, and it wraps its writes in
 *    ProductsVariation::applyingSystemPricing(), which flips a static guard for
 *    the duration of that save(). Any OTHER update that leaves `price` dirty — a
 *    CMS edit on the Products Variation page, tinker, a future admin tool — sets
 *    `manual_price` so the sync stops touching that row's price from then on (it
 *    still keeps `cost_price`/`external_price`/`supplier_status` current).
 *
 * The lock is deliberately `updating`-only: a brand new row's initial Price (set
 * by the sync on first import, by a CSV import, or by a fixture/test creating a
 * supplier-sourced variation directly) must not count as an "edit" — only a
 * change to an already-persisted row should lock it. Using `saving` there would
 * lock every supplier variation from birth and break bulk repricing.
 *
 * Registered in AppServiceProvider rather than as a booted() hook: the hellotree
 * CMS regenerates every model file above its custom-function markers on a
 * page-schema save, which would silently drop a hook declared inside the model.
 */
class ProductsVariationObserver
{
    /** A new row created with a cost and a profit % but no Price gets the derived one. */
    public function creating(ProductsVariation $variation): void
    {
        if ($variation->profit_percentage === null || $variation->price !== null) {
            return;
        }

        $price = $variation->priceFromCost();
        if ($price !== null) {
            $variation->price = $price;
        }
    }

    public function updating(ProductsVariation $variation): void
    {
        if ($variation->isDirty('profit_percentage')) {
            $price = $variation->priceFromCost();
            if ($price !== null) {
                $variation->price = $price;
                $variation->manual_price = false;

                return;
            }
        }

        if ($variation->isDirty('price') && !ProductsVariation::isApplyingSystemPricing()) {
            $variation->manual_price = 1;
        }
    }
}
