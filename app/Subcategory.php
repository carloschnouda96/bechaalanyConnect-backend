<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class Subcategory extends Model  implements TranslatableContract
{
    use Translatable;

    protected $table = 'subcategories';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["title", "description"];

    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('subcategories.cms_draft_flag', '!=', 1);
        });
    }
    public function category()
    {
        return $this->belongsTo('App\Category');
    }

    /* Start custom functions */

    // Below the marker so a hellotree CMS page-schema save cannot rewrite it away.
    use \App\Concerns\HasFullPath;

    public $appends = ['full_path'];

    // Hides this level on the storefront: its products render on the parent
    // category page instead. NULL (the CMS re-nulls columns) reads as false.
    protected $casts = ['show_products_in_category' => 'boolean'];

    public function scopeShownAsLevel(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('subcategories.show_products_in_category')
            ->orWhere('subcategories.show_products_in_category', 0));
    }

    /* End custom functions */
}
