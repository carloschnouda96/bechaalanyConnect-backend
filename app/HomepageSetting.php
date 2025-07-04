<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class HomepageSetting extends Model  implements TranslatableContract
{
    use Translatable;

    protected $table = 'homepage_settings';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["whatsapp_text", "categories_section_title", "view_all_button_label", "featured_products_section_title", "latest_products_section_title", "whatsapp_channel_button_text"];

    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('homepage_settings.cms_draft_flag', '!=', 1);
        });
    }
    public function featured_products()
    {
        return $this->belongsToMany('App\Product', 'featured_product_homepage_setting', 'homepage_setting_id', 'product_id')->where('products.is_active', 1)->orderBy('featured_product_homepage_setting.ht_pos')->take(4);
    }

    public $with = ['featured_products'];

    /* Start custom functions */



    /* End custom functions */
}
