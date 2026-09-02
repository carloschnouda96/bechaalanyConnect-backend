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

    protected $hidden = ['translations'];

    public $translatedAttributes = ["name", "description"];

    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('products.cms_draft_flag', '!=', 1);
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

    /* Start custom functions */

    // Everything below the marker survives a hellotree CMS page-schema save;
    // everything above it is regenerated from src/stubs/model.stub.
    //
    // The "recalculate variation prices when an admin edits profit_percentage"
    // hook used to live in booted() above. booted() IS regenerated (the stub emits
    // only the cms_draft_flag scope), so that hook was one CMS save away from
    // silently disappearing — supplier prices would then quietly stop tracking the
    // markup. It now lives in App\Observers\ProductObserver, registered in
    // AppServiceProvider, i.e. outside this file entirely.

    use \App\Concerns\HasFullPath;
    use \App\Concerns\HidesExtraAttributes;

    /** Merged into the regenerated `$hidden = ['translations']`. Keeps supplier linkage + our margin out of public API responses. */
    protected $extraHidden = [
        'external_source',
        'external_id',
        'profit_percentage',
        'import_excluded',
    ];

    protected $casts = [
        'profit_percentage' => 'decimal:2',
        'import_excluded' => 'boolean',
    ];

    public function variations()
    {
        return $this->hasMany(ProductsVariation::class, 'product_id');
    }

    public $with = ['subcategory.category'];

    public $appends = ['full_path'];

    /**
     * The markup % to apply to this product's supplier cost, falling back to the
     * global default in Fixed Settings when no per-product value is set.
     */
    public function effectiveProfitPercentage(): float
    {
        if ($this->profit_percentage !== null) {
            return (float) $this->profit_percentage;
        }
        $default = FixedSetting::current()->default_profit_percentage;
        return (float) ($default ?? 0);
    }

    /**
     * Recompute every SUPPLIER-SOURCED variation's selling price from its stored
     * supplier cost (cost_price) and this product's effective profit %. Writes only
     * when the price actually changes. Returns the number of variations updated.
     *
     * Scoped to variations that carry an `external_id` because a grouped supplier
     * product (supplier_categories.group_as_single_product) can hold hand-added
     * variations alongside the imported ones. Those are priced by an admin, often
     * with a cost_price of their own, and editing the product's markup must not
     * silently overwrite them.
     */
    public function recalculateSupplierPrices(): int
    {
        $pct = $this->effectiveProfitPercentage();
        $updated = 0;

        $variations = $this->variations()
            ->withoutGlobalScope('cms_draft_flag')
            ->whereNotNull('external_id')
            ->get();
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
