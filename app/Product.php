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
    // Markup (profit %) is per VARIATION, not per product — see
    // ProductsVariation::effectiveProfitPercentage() and ProductsVariationObserver.

    use \App\Concerns\HasFullPath;
    use \App\Concerns\HidesExtraAttributes;
    use \App\Concerns\AppendsToCmsOrder;

    /**
     * `supplier_status` values. NULL means "not supplier-managed" (a platform
     * product) and is treated the same as AVAILABLE by sellable().
     *
     * See SupplierCatalogSync's class docblock for the full ownership model:
     * `is_active` is admin-owned (never written by the sync past creation);
     * `supplier_status` is sync-owned (never written by the CMS — read-only
     * field); "sellable" is derived from both at read time and stored nowhere.
     */
    public const SUPPLIER_AVAILABLE = 'available';
    public const SUPPLIER_OUT_OF_STOCK = 'out_of_stock';
    public const SUPPLIER_WITHDRAWN = 'withdrawn';

    /**
     * Merged into the regenerated `$hidden = ['translations']`. Keeps supplier
     * linkage out of public API responses. `supplier_status` is
     * an internal sync signal, not something the storefront needs — filtering
     * on it happens server-side via sellable(), so it stays hidden too.
     */
    protected $extraHidden = [
        'external_source',
        'external_id',
        'supplier_status',
    ];

    /**
     * A product the storefront may show: the admin has switched it on AND (it
     * isn't supplier-managed OR the supplier currently has it in stock).
     *
     * This is the ONE place "can we sell this" is decided for products — every
     * public listing/search/detail query should use this instead of a bare
     * `is_active` check, so admin intent and supplier stock combine the same
     * way everywhere.
     */
    public function scopeSellable($query)
    {
        return $query->where('is_active', 1)
            ->where(function ($q) {
                $q->whereNull('supplier_status')
                    ->orWhere('supplier_status', self::SUPPLIER_AVAILABLE);
            });
    }

    /**
     * A product that belongs in a storefront LISTING: sellable() AND at least one of
     * its variations could be bought. Without the second half a product the admin had
     * created but not yet given any variation showed "Buy Now" in every list and
     * opened onto an empty "Coming Soon" page.
     *
     * The variation conditions are inlined rather than calling
     * ProductsVariation::sellable(), which itself does whereHas('product', sellable) —
     * the product half is already applied here.
     *
     * Listings only. The single-product route keeps using sellable(), so a direct link
     * to an empty product still renders its own "Coming Soon" state.
     */
    public function scopeListable($query)
    {
        return $query->sellable()->whereHas('variations', function ($q) {
            $q->where('is_active', 1)
                ->where(function ($inner) {
                    $inner->whereNull('supplier_status')
                        ->orWhere('supplier_status', self::SUPPLIER_AVAILABLE);
                });
        });
    }

    public function variations()
    {
        return $this->hasMany(ProductsVariation::class, 'product_id');
    }

    public $with = ['subcategory.category'];

    public $appends = ['full_path'];

    /* End custom functions */
}
