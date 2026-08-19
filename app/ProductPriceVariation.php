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
    // This scope is called from ProductsVariation::getCurrentPriceAttribute(), so
    // losing it would fatal on every product page for a business-tier user.

    /**
     * Scope: limit price variations to a specific user type id.
     */
    public function scopeForUserType(Builder $query, $userTypeId)
    {
        return $query->where('user_types_id', $userTypeId);
    }

    /* End custom functions */
}
