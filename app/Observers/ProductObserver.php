<?php

namespace App\Observers;

use App\Product;

/**
 * Locks a supplier product's placement the moment anyone but the catalog sync
 * moves it to another subcategory (`manual_subcategory`), so an admin can group a
 * mixed supplier category by brand without the next sync moving it back.
 *
 * A save that changes the subcategory AND explicitly unticks the lock is left
 * unlocked: that is the admin handing placement back to the sync.
 */
class ProductObserver
{
    public function updating(Product $product): void
    {
        if (Product::isApplyingSystemPlacement()
            || blank($product->external_source)
            || !$product->isDirty('subcategory_id')) {
            return;
        }

        if ($product->isDirty('manual_subcategory') && !$product->manual_subcategory) {
            return;
        }

        $product->manual_subcategory = true;
    }
}
