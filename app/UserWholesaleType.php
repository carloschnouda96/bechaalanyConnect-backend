<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;



class UserWholesaleType extends Model 
{
	

    protected $table = 'user_wholesale_types';

    protected $guarded = ['id'];

    

	protected static function booted(){static::addGlobalScope('cms_draft_flag', function (Builder $builder) {$builder->where('user_wholesale_types.cms_draft_flag', '!=', 1);});}

    /* Start custom functions */



    /* End custom functions */
}