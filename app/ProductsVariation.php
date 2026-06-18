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

    // Hide supplier cost/linkage from the public API; external_qty_values stays
    // visible so the storefront can render preset amounts. cost_price remains as
    // it was before this integration.
    protected $hidden = ['translations', 'external_id', 'external_price', 'external_type'];

    public $translatedAttributes = ["name", "description", "unit_label"];

    protected $casts = [
        'unit_amount' => 'integer',
        'external_qty_values' => 'array',
    ];

    /**
     * Single source of truth for the supplier markup formula:
     * selling price = cost * (1 + profit% / 100), rounded to 2 decimals.
     */
    public static function computeSellingPrice(float $cost, float $profitPercentage): float
    {
        return round($cost * (1 + ($profitPercentage / 100)), 2);
    }

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

    /**
     * All price variations (one per user type typically).
     */
    public function priceVariations()
    {
        return $this->hasMany(ProductPriceVariation::class, 'products_variations_id');
    }

    /* Start custom functions */

    public $appends = ['full_path', 'current_price'];

    /**
     * Optionally eager load price variations to avoid N+1 when serializing.
     * Comment this out if payload becomes too large and instead load conditionally in queries.
     */
    protected $with = ['priceVariations'];

    public function getFullPathAttribute()
    {
        if ($this->image) {
            $image = Storage::url($this->image);
            return compact('image');
        }
        return null;
    }

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
