<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract; use Astrotomic\Translatable\Translatable;

class ContactDetail extends Model  implements TranslatableContract
{
	use Translatable;

    protected $table = 'contact_details';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["branch_name","address"];

	protected static function booted(){static::addGlobalScope('cms_draft_flag', function (Builder $builder) {$builder->where('contact_details.cms_draft_flag', '!=', 1);});}

    /* Start custom functions */



    /* End custom functions */
}