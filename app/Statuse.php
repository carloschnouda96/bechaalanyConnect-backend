<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;



class Statuse extends Model 
{
	

    protected $table = 'statuses';

    protected $guarded = ['id'];

    

	protected static function booted(){static::addGlobalScope('cms_draft_flag', function (Builder $builder) {$builder->where('statuses.cms_draft_flag', '!=', 1);});}

    /* Start custom functions */



    /* End custom functions */
}