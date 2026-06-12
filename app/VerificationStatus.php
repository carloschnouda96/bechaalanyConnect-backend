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
}
