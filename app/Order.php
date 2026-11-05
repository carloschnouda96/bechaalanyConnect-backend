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

    // Below the marker so a hellotree CMS page-schema save cannot rewrite it away.

    use \App\Concerns\HidesExtraAttributes;

    // Deleting an order must not erase the money that moved through it. The CMS
    // delete button calls $record->delete(), which this turns into an UPDATE.
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $casts = [
        'external_response' => 'array',
    ];

    /**
     * Orders are returned to their owner by OrderController::getUserOrders, so the
     * supplier-side bookkeeping must not ride along:
     *   external_response   raw supplier payload (upstream ids, pricing, internals)
     *   external_order_uuid our fulfillment idempotency key — a customer knowing it
     *                       gains nothing, and it is pure attack surface
     */
    protected $extraHidden = [
        'external_response',
        'external_order_uuid',
        'cms_draft_flag',
    ];

    /**
     * A delivered code is a bearer value, released only on an approved order. A supplier can
     * deliver after an order was rejected and refunded, and the React components were the only
     * thing hiding it from the owner's JSON. The column keeps the value for a re-approval.
     * Matches App\Services\Reseller\OrderPresenter.
     */
    public function toArray()
    {
        $array = parent::toArray();

        if (array_key_exists('code', $array) && (int) ($this->attributes['statuses_id'] ?? 0) !== self::STATUS_APPROVED) {
            $array['code'] = null;
        }

        return $array;
    }

    /*
     | `coins` is intentionally NOT in $appends.
     |
     | The accessor falls back to $this->product_variation()->first() whenever the
     | relation is not already loaded, so appending it meant one extra query per
     | order on any listing that did not eager-load product_variation — and nothing
     | actually reads it (no frontend consumer, and the CMS index blade only renders
     | registered table columns).
     |
     | The accessor is kept: call ->append('coins') where a coin total is wanted.
     */

    /**
     * Coins ordered, for a Coin Recharge product; null otherwise. Since 2026-10-09
     * `quantity` IS the coin count (the customer types it). Orders placed before
     * that stored blocks of `unit_amount` coins instead.
     */
    public function getCoinsAttribute()
    {
        $variation = $this->relationLoaded('product_variation')
            ? $this->product_variation
            : $this->product_variation()->first();

        if (!$variation || !$variation->isCoin()) {
            return null;
        }

        return (int) $this->quantity;
    }

    /* End custom functions */
}
