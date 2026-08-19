<?php

namespace App\Observers;

use App\Product;

/**
 * Keeps supplier-sourced variation prices in step with the product's markup.
 *
 * This logic used to be a `static::updated(...)` closure inside Product::booted().
 * booted() sits above the custom-function markers, and the hellotree CMS rewrites
 * everything above those markers from its model stub on every page-schema save —
 * emitting only the cms_draft_flag global scope. The hook was therefore one admin
 * "Save" away from vanishing, after which editing profit_percentage in the CMS
 * would appear to work while variation prices silently stopped changing until the
 * next supplier sync.
 *
 * An observer lives outside the model file, so the CMS cannot touch it.
 */
class ProductObserver
{
    /**
     * When an admin edits the markup of a supplier product in the CMS, push the new
     * selling price down to its variations immediately rather than waiting for the
     * next supplier sync. Only supplier-sourced products are touched, so manually
     * priced products are never overwritten.
     */
    public function updated(Product $product): void
    {
        if ($product->external_source && $product->wasChanged('profit_percentage')) {
            $product->recalculateSupplierPrices();
        }
    }
}
