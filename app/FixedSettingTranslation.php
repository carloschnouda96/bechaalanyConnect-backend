<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;



class FixedSettingTranslation extends Model 
{
	

    protected $table = 'fixed_settings_translations';

    protected $guarded = ['id'];

    

	

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