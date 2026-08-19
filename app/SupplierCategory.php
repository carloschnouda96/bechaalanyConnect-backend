<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;



class SupplierCategory extends Model
{


    protected $table = 'supplier_categories';

    protected $guarded = ['id'];



    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('supplier_categories.cms_draft_flag', '!=', 1);
        });
    }



    /* Start custom functions */

    // Below the marker so a hellotree CMS page-schema save cannot rewrite it away.
    protected $casts = [
        'import_enabled' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class, 'subcategory_id');
    }
    /* End custom functions */
}
