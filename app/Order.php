<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;



class Order extends Model 
{
	

    protected $table = 'orders';

    protected $guarded = ['id'];

    

	protected static function booted(){static::addGlobalScope('cms_draft_flag', function (Builder $builder) {$builder->where('orders.cms_draft_flag', '!=', 1);});}public function users() { return $this->belongsTo('App\User'); } public function product_variation() { return $this->belongsTo('App\ProductsVariation'); } public function statuses() { return $this->belongsTo('App\Statuse'); } 

    /* Start custom functions */



    /* End custom functions */
}