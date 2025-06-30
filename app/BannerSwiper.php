<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class BannerSwiper extends Model  implements TranslatableContract
{
    use Translatable;

    protected $table = 'banner_swiper';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["title", "subtitle", "description"];

    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('banner_swiper.cms_draft_flag', '!=', 1);
        });
    }

    /* Start custom functions */

    public $appends = ['full_path'];

    public function getFullPathAttribute()
    {
        $image = Storage::url($this->image);
        return compact('image');
    }

    /* End custom functions */
}
