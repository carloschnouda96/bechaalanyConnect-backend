<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;



class User extends Model 
{
	

    protected $table = 'users';

    protected $guarded = ['id'];

    

	protected static function booted(){static::addGlobalScope('cms_draft_flag', function (Builder $builder) {$builder->where('users.cms_draft_flag', '!=', 1);});}public function user_wholesale_types() { return $this->belongsTo('App\UserWholesaleType'); } 

    /* Start custom functions */



    /* End custom functions */
}