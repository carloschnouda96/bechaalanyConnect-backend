<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract; use Astrotomic\Translatable\Translatable;

class LoggingPagesSetting extends Model  implements TranslatableContract
{
	use Translatable;

    protected $table = 'logging_pages_settings';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["sign_in_title","sign_in_subtitle","login_button","google_button","sign_up_title","sign_up_subtitle","sign_up_button","username_placeholder","email_placeholder","country_placeholder","phone_number_placeholder","password_placeholder","confirm_password_placeholder","forget_password_label","register_business_user_label","user_type_placeholder","store_name_placeholder","store_location_placeholder"];

	protected static function booted(){static::addGlobalScope('cms_draft_flag', function (Builder $builder) {$builder->where('logging_pages_settings.cms_draft_flag', '!=', 1);});}

    /* Start custom functions */



    /* End custom functions */
}