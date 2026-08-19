<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class ProductsVariation extends Model  implements TranslatableContract
{
    use Translatable;

    protected $table = 'products_variations';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["name", "description", "unit_label"];

    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('products_variations.cms_draft_flag', '!=', 1);
        });
    }
    public function product()
    {
        return $this->belongsTo('App\Product');
    }

    /* Start custom functions */

    // Everything below the marker is preserved when the hellotree CMS regenerates
    // this file on a page-schema save; everything above it is rewritten from
    // src/stubs/model.stub. `use`, `$casts`, `$hidden`, hasMany relations and
    // static helpers must therefore live down here — see App\Concerns\HidesExtraAttributes.

    use \App\Concerns\HasFullPath;
    use \App\Concerns\HidesExtraAttributes;

    /**
     * Merged into the regenerated `$hidden = ['translations']`.
     *
     * cost_price is the supplier's unit cost. Leaving it exposed published our
     * margin on the public storefront: ProductController::SingleProduct returns
     * variations unauthenticated, so anyone could derive the markup on every product.
     * external_qty_values stays visible — the storefront renders it as preset amounts.
     */
    protected $extraHidden = [
        'external_id',
        'external_price',
        'external_type',
        'cost_price',
    ];

    // NOTE: money casts (price => decimal:2) are deliberately NOT added here yet.
    // A decimal cast serialises to a JSON string ("12.50"), which the storefront
    // would feed straight into arithmetic. They land in the money migration,
    // together with the frontend number normaliser.
    protected $casts = [
        'unit_amount' => 'integer',
        'external_qty_values' => 'array',
    ];

    /**
     * All price variations (one per user type typically).
     */
    public function priceVariations()
    {
        return $this->hasMany(ProductPriceVariation::class, 'products_variations_id');
    }

    /**
     * Single source of truth for the supplier markup formula:
     * selling price = cost * (1 + profit% / 100), rounded to 2 decimals.
     */
    public static function computeSellingPrice(float $cost, float $profitPercentage): float
    {
        return round($cost * (1 + ($profitPercentage / 100)), 2);
    }

    /*
     | `current_price` removed from $appends.
     |
     | The accessor calls auth()->user() during serialization, which makes every
     | product response depend on who is asking — so nothing downstream can cache a
     | product payload, and a queued job or console command serialising a variation
     | resolves a different value than a web request would.
     |
     | It also had no consumer: the storefront resolves the tier price itself from
     | the serialised `price_variations` relation ([productId].tsx:93), and
     | OrderController::saveOrder recomputes it server-side from priceVariations,
     | which is the authoritative path for what a user is actually charged.
     |
     | The accessor is kept for callers that want it explicitly.
     */
    public $appends = ['full_path'];

    /**
     * Kept: the storefront reads the serialised `price_variations` key to render
     * business-tier pricing, so this relation must be present on every variation
     * payload. (Eloquent snake_cases relation keys on serialisation, which is why
     * `priceVariations` arrives as `price_variations`.)
     */
    protected $with = ['priceVariations'];

    /**
     * Expose the price for the currently authenticated user's user type.
     * If no auth user or matching variation, returns null.
     *
     * Assumes column names: user_types_id, price on product_price_variations table.
     */
    public function getCurrentPriceAttribute()
    {
        try {
            $user = auth()->user();
        } catch (\Throwable $e) {
            $user = null;
        }
        if (!$user || !isset($user->user_types_id)) {
            return null;
        }

        // If relationship already loaded use collection in memory; else do a focused query.
        if ($this->relationLoaded('priceVariations')) {
            $match = $this->priceVariations->firstWhere('user_types_id', $user->user_types_id);
            return $match ? $match->price : null;
        }
        $match = $this->priceVariations()->forUserType($user->user_types_id)->first();
        return $match ? $match->price : null;
    }

    /* End custom functions */
}
