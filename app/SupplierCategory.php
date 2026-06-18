<?php

namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A category discovered on a supplier (currently Yassen). Rows are created and
 * refreshed by `yassen:sync`; admins flip `import_enabled` in the CMS to choose
 * which categories' products get imported into the local catalog. `category_id`
 * / `subcategory_id` hold the local tree mapping the sync creates.
 */
class SupplierCategory extends Model
{
    protected $table = 'supplier_categories';

    protected $guarded = ['id'];

    protected $casts = [
        'import_enabled' => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('supplier_categories.cms_draft_flag', '!=', 1);
        });
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class, 'subcategory_id');
    }
}
