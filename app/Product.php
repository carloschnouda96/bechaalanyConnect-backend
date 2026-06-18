<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class Product extends Model  implements TranslatableContract
{
    use Translatable;

    protected $table = 'products';

    protected $guarded = ['id'];

    // Keep supplier linkage + our margin out of the public API responses.
    protected $hidden = ['translations', 'external_source', 'external_id', 'profit_percentage'];

    public $translatedAttributes = ["name", "description"];

    protected $casts = [
        'profit_percentage' => 'decimal:2',
    ];

    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('products.cms_draft_flag', '!=', 1);
        });

        // When an admin edits the markup of a supplier product in the CMS, push
        // the new selling price down to its variations immediately (no need to
        // wait for the next supplier sync). Only supplier-sourced products are
        // touched so manually-priced products are never overwritten.
        static::updated(function (Product $product) {
            if ($product->external_source && $product->wasChanged('profit_percentage')) {
                $product->recalculateSupplierPrices();
            }
        });
    }
    public function subcategory()
    {
        return $this->belongsTo('App\Subcategory');
    }
    public function related_products()
    {
        return $this->belongsToMany('App\Product', 'related_product_product', 'product_id', 'other_product_id')->orderBy('related_product_product.ht_pos');
    }
    public function product_type()
    {
        return $this->belongsTo('App\ProductType');
    }
    public function variations()
    {
        return $this->hasMany(ProductsVariation::class, 'product_id');
    }

    /* Start custom functions */

    public $with = ['subcategory.category'];

    public $appends = ['full_path'];

    public function getFullPathAttribute()
    {
        $image = Storage::url($this->image);
        return compact('image');
    }

    /**
     * The markup % to apply to this product's supplier cost, falling back to the
     * global default in Fixed Settings when no per-product value is set.
     */
    public function effectiveProfitPercentage(): float
    {
        if ($this->profit_percentage !== null) {
            return (float) $this->profit_percentage;
        }
        $default = optional(FixedSetting::first())->default_profit_percentage;
        return (float) ($default ?? 0);
    }

    /**
     * Recompute every variation's selling price from its stored supplier cost
     * (cost_price) and this product's effective profit %. Writes only when the
     * price actually changes. Returns the number of variations updated.
     */
    public function recalculateSupplierPrices(): int
    {
        $pct = $this->effectiveProfitPercentage();
        $updated = 0;

        $variations = $this->variations()->withoutGlobalScope('cms_draft_flag')->get();
        foreach ($variations as $variation) {
            $cost = $variation->cost_price ?? $variation->external_price;
            if ($cost === null) {
                continue;
            }
            $newPrice = ProductsVariation::computeSellingPrice((float) $cost, $pct);
            if (abs((float) $variation->price - $newPrice) > 0.0001) {
                $variation->price = $newPrice;
                $variation->save();
                $updated++;
            }
        }

        return $updated;
    }

    /* End custom functions */
}
