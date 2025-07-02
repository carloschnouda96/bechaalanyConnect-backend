<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract; use Astrotomic\Translatable\Translatable;

class Product extends Model  implements TranslatableContract
{
	use Translatable;

    protected $table = 'products';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["name","description"];

	protected static function booted(){static::addGlobalScope('cms_draft_flag', function (Builder $builder) {$builder->where('products.cms_draft_flag', '!=', 1);});}public function category() { return $this->belongsTo('App\Category'); } 

    /* Start custom functions */



    /* End custom functions */
}