<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract; use Astrotomic\Translatable\Translatable;

class Subcategory extends Model  implements TranslatableContract
{
	use Translatable;

    protected $table = 'subcategories';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["title","description"];

	protected static function booted(){static::addGlobalScope('cms_draft_flag', function (Builder $builder) {$builder->where('subcategories.cms_draft_flag', '!=', 1);});}public function category() { return $this->belongsTo('App\Category'); } 

    /* Start custom functions */



    /* End custom functions */
}