<?php

namespace App\Observers;

use App\Product;
use App\ProductsVariation;
use Illuminate\Validation\ValidationException;

/**
 * Locks a supplier variation to the product an admin moved it to
 * (`products_variations.manual_product`), so staff can gather supplier rows under
 * one product of their own (every Swift "YouTube …" row as a plan of "YouTube")
 * without SupplierCatalogSync moving each row back within the hour.
 *
 * A move is refused when it would break orders:
 *  - onto a product another supplier sync owns: that product's withdrawal sweep
 *    would mark this row withdrawn with it;
 *  - onto a product of a different type: the type decides which recipient field
 *    the storefront collects, so orders would reach the supplier without it.
 *
 * Moving a row back onto its own supplier product clears the lock, and so does a
 * save that explicitly unticks it. A hand-made variation (no external_id) moves
 * freely and is never locked.
 *
 * Its own class rather than part of ProductsVariationObserver::updating(), which
 * returns early in its profit-% branch: a save changing profit % and product
 * together would otherwise skip the lock.
 */
class VariationPlacementObserver
{
    public function updating(ProductsVariation $variation): void
    {
        if (Product::isApplyingSystemPlacement() || !$variation->isDirty('product_id')) {
            return;
        }

        if (blank($variation->external_id)) {
            return;
        }

        $from = Product::withoutGlobalScope('cms_draft_flag')->find($variation->getOriginal('product_id'));
        $source = $variation->external_source ?: optional($from)->external_source;

        if (blank($source)) {
            return; // not a supplier row
        }

        // Rows from before the column was backfilled: fulfilment resolves the
        // supplier through the variation once its parent is an admin product.
        if (blank($variation->external_source)) {
            $variation->external_source = $source;
        }

        $to = Product::withoutGlobalScope('cms_draft_flag')->find($variation->product_id);
        if (!$to) {
            return; // the foreign key will refuse it
        }

        if ($to->external_source === $source && (string) $to->external_id === (string) $variation->external_id) {
            $variation->manual_product = false; // back on its own supplier product

            return;
        }

        if (filled($to->external_source)) {
            throw ValidationException::withMessages([
                'product_id' => 'This product is imported from ' . $to->external_source
                    . '. Move supplier variations into a product you created instead.',
            ]);
        }

        $type = $from ? (int) $from->product_type_id : null;
        if ($type !== null && (int) $to->product_type_id !== $type) {
            throw ValidationException::withMessages([
                'product_id' => 'This variation needs a product of the same type as its own (product type #' . $type
                    . '), because the type decides what the customer is asked for at checkout.',
            ]);
        }

        if ($variation->isDirty('manual_product') && !$variation->manual_product) {
            return; // moved and handed back in the same save
        }

        $variation->manual_product = true;
    }
}
