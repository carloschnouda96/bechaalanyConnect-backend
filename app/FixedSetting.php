<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract; use Astrotomic\Translatable\Translatable;

class FixedSetting extends Model  implements TranslatableContract
{
	use Translatable;

    protected $table = 'fixed_settings';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["create_account_button","footer_copyright"];

	protected static function booted(){static::addGlobalScope('cms_draft_flag', function (Builder $builder) {$builder->where('fixed_settings.cms_draft_flag', '!=', 1);});}

    /* Start custom functions */



    /* End custom functions */
}