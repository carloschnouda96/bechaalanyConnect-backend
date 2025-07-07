<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract; use Astrotomic\Translatable\Translatable;

class ContactFormSubject extends Model  implements TranslatableContract
{
	use Translatable;

    protected $table = 'contact_form_subjects';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["title"];

	protected static function booted(){static::addGlobalScope('cms_draft_flag', function (Builder $builder) {$builder->where('contact_form_subjects.cms_draft_flag', '!=', 1);});}

    /* Start custom functions */



    /* End custom functions */
}