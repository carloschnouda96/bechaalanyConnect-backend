<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;



class CreditsType extends Model 
{
	

    protected $table = 'credits_types';

    protected $guarded = ['id'];

    

	protected static function booted(){static::addGlobalScope('cms_draft_flag', function (Builder $builder) {$builder->where('credits_types.cms_draft_flag', '!=', 1);});}

    /* Start custom functions */



    /* End custom functions */
}