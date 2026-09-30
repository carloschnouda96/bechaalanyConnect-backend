<?php

namespace App\Observers;

use App\ProductPriceVariation;
use App\ProductsVariation;
use Illuminate\Validation\ValidationException;

/**
 * Keeps a user-type price in % mode equal to cost x (1 + profit %).
 *
 * `product_price_variations.price` is what OrderController::saveOrder charges and what
 * the storefront shows, so it must always hold the RESOLVED figure — the rule
 * (profit_percentage) is stored beside it rather than evaluated at read time. Every
 * write path goes through here: the Price matrix, the vendor Product Price Variations
 * page, tinker, and ProductsVariationObserver::updated() re-saving rows when a cost moves.
 *
 * A row in % mode whose variation has no cost is refused rather than saved: `price`
 * defaults to 0.00, so letting it through would sell that tier the product for free.
 *
 * Registered in AppServiceProvider, not as a booted() hook — the CMS regenerates the
 * model file above its custom-function markers on a page-schema save.
 */
class ProductPriceVariationObserver
{
    public function saving(ProductPriceVariation $row): void
    {
        if (!$row->isPercentMode()) {
            return;
        }

        $variation = ProductsVariation::withoutGlobalScope('cms_draft_flag')->find($row->products_variations_id);
        $price = $variation ? $row->priceFromCost($variation) : null;

        if ($price === null) {
            throw ValidationException::withMessages([
                'profit_percentage' => 'This variation has no cost price recorded, so a profit % cannot be applied. Set a fixed price instead, or add a cost first.',
            ]);
        }

        $row->price = $price;
    }
}
