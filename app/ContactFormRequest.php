<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;



class ContactFormRequest extends Model 
{
	

    protected $table = 'contact_form_request';

    protected $guarded = ['id'];

    

	protected static function booted(){static::addGlobalScope('cms_draft_flag', function (Builder $builder) {$builder->where('contact_form_request.cms_draft_flag', '!=', 1);});}

    /* Start custom functions */



    /* End custom functions */
}