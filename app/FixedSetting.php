<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class FixedSetting extends Model  implements TranslatableContract
{
    use Translatable;

    protected $table = 'fixed_settings';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["create_account_button", "login_button", "footer_copyright", "categories_label", "homepage_label", "back_button_label", "amount", "quantity", "total", "related_products", "user_id_label", "user_id_placeholder", "phone_number_label", "phone_number_placeholder", "buy_now_button", "logout_button"];

    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('fixed_settings.cms_draft_flag', '!=', 1);
        });
    }

    /* Start custom functions */

    public $appends = ['full_path'];

    public function getFullPathAttribute()
    {
        $logo = Storage::url($this->logo);
        $dark_mode_logo = Storage::url($this->dark_mode_logo);
        return compact('logo', 'dark_mode_logo');
    }

    /* End custom functions */
}
