<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class VerificationStatus extends Model
{
    protected $table = 'verification_statuses';

    protected $guarded = ['id'];

    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('verification_statuses.cms_draft_flag', '!=', 1);
        });
    }

    /* Start custom functions */

    // This file had no custom-function markers at all. The hellotree CMS keeps only
    // the text between them when it regenerates a model on a page-schema save, so
    // without this block any hand-written addition here would be silently erased.

    /* End custom functions */
}
