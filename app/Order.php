<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;



class Order extends Model
{
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 2;
    const STATUS_PENDING = 3;

    protected $table = 'orders';

    protected $guarded = ['id'];

    protected $casts = [
        'external_response' => 'array',
    ];



    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('orders.cms_draft_flag', '!=', 1);
        });
    }
    public function users()
    {
        return $this->belongsTo('App\User');
    }
    public function product_variation()
    {
        return $this->belongsTo('App\ProductsVariation');
    }
    public function statuses()
    {
        return $this->belongsTo('App\Statuse');
    }

    /* Start custom functions */

    protected $appends = ['coins'];

    /**
     * For Coin Recharge products, the total coins ordered = quantity (blocks) ×
     * the variation's coins-per-block. Null for non-coin products so the CMS
     * orders view can show "30,000 coins" instead of just the block count.
     */
    public function getCoinsAttribute()
    {
        $variation = $this->relationLoaded('product_variation')
            ? $this->product_variation
            : $this->product_variation()->first();

        if (!$variation || !$variation->unit_amount) {
            return null;
        }

        return $this->quantity * $variation->unit_amount;
    }

    /* End custom functions */
}
