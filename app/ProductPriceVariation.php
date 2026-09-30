<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;



class ProductPriceVariation extends Model
{


    protected $table = 'product_price_variations';

    protected $guarded = ['id'];



    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('product_price_variations.cms_draft_flag', '!=', 1);
        });
    }
    public function products_variations()
    {
        return $this->belongsTo('App\ProductsVariation');
    }
    public function user_types()
    {
        return $this->belongsTo('App\UserType');
    }

    /* Start custom functions */

    // Below the marker so a hellotree CMS page-schema save cannot rewrite it away.

    /**
     * Scope: limit price variations to a specific user type id.
     */
    public function scopeForUserType(Builder $query, $userTypeId)
    {
        return $query->where('user_types_id', $userTypeId);
    }

    use \App\Concerns\HidesExtraAttributes;

    /**
     * These rows ship on the public, unauthenticated product route (ProductsVariation::$with).
     * Price and profit % together give away the cost, which ProductsVariation hides for
     * the same reason.
     */
    protected $extraHidden = ['profit_percentage'];

    protected $casts = [
        'profit_percentage' => 'decimal:2',
    ];

    /**
     * profit_percentage NULL = fixed mode (the admin owns `price`); set = % mode, where
     * `price` is derived from the variation's cost by ProductPriceVariationObserver.
     */
    public function isPercentMode(): bool
    {
        return $this->profit_percentage !== null;
    }

    /**
     * The price this row's profit % produces on the variation's cost, or null when
     * the row is in fixed mode or the variation has no cost to price from. Same
     * formula as the public price — ProductsVariation::computeSellingPrice().
     */
    public function priceFromCost(ProductsVariation $variation): ?float
    {
        $cost = $variation->cost_price ?? $variation->external_price;

        if (!$this->isPercentMode() || $cost === null) {
            return null;
        }

        return ProductsVariation::computeSellingPrice((float) $cost, (float) $this->profit_percentage);
    }

    /* End custom functions */
}
