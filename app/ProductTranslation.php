<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;



class ProductTranslation extends Model
{
    use \App\Concerns\HasFullPath;


    protected $table = 'products_translations';

    protected $guarded = ['id'];

    

	

    /* Start custom functions */

    public $appends = ['full_path'];

    /* End custom functions */
}