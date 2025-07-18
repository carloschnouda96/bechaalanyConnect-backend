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

    public $with = ['subcategory.category'];

    public $appends = ['full_path'];

    public function getFullPathAttribute()
    {
        $image = Storage::url($this->image);
        return compact('image');
    }

    /* End custom functions */
}
