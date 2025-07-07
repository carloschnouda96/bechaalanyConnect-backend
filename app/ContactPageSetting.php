<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class ContactPageSetting extends Model  implements TranslatableContract
{
    use Translatable;

    protected $table = 'contact_page_settings';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["title", "description", "name_label", "email_label", "phone_label", "subject_label", "message_label", "button_text", "contact_title", "follow_us_title"];

    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('contact_page_settings.cms_draft_flag', '!=', 1);
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
