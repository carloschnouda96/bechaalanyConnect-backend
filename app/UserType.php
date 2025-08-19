<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class UserType extends Model  implements TranslatableContract
{
    use Translatable;

    protected $table = 'user_types';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["title"];

    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('user_types.cms_draft_flag', '!=', 1);
        });
    }

    /* Start custom functions */

    public function priceVariations()
    {
        return $this->hasMany('App\ProductPriceVariation', 'user_types_id');
    }

    /* End custom functions */
}
