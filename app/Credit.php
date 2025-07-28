<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;



class Credit extends Model 
{
	

    protected $table = 'credits';

    protected $guarded = ['id'];

    

	protected static function booted(){static::addGlobalScope('cms_draft_flag', function (Builder $builder) {$builder->where('credits.cms_draft_flag', '!=', 1);});}public function users() { return $this->belongsTo('App\User'); } public function credits_types() { return $this->belongsTo('App\CreditsType'); } public function statuses() { return $this->belongsTo('App\Statuse'); } 

    /* Start custom functions */



    /* End custom functions */
}