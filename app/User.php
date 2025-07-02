<?php

namespace App;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;


class User extends Model
{


    protected $table = 'users';

    protected $guarded = ['id'];


    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('menu_items.cms_draft_flag', '!=', 1);
        });
    }

    /* Start custom functions */



    /* End custom functions */
}
