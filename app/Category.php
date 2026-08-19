<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class Category extends Model  implements TranslatableContract
{
    use Translatable;

    protected $table = 'categories';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["title", "description"];

    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('categories.cms_draft_flag', '!=', 1);
        });
    }

    /* Start custom functions */

    // Below the marker so a hellotree CMS page-schema save cannot rewrite it away.
    // Without the trait, $appends still names full_path and the accessor is gone,
    // which fatals on every category listing.
    use \App\Concerns\HasFullPath;

    public $appends = ['full_path'];

    /* End custom functions */
}
