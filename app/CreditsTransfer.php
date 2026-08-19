<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;



class CreditsTransfer extends Model
{
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 2;
    const STATUS_PENDING = 3;

    protected $table = 'credits_transfer';

    protected $guarded = ['id'];



    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('credits_transfer.cms_draft_flag', '!=', 1);
        });
    }
    public function users()
    {
        return $this->belongsTo('App\User');
    }
    public function credits_types()
    {
        return $this->belongsTo('App\CreditsType');
    }
    public function statuses()
    {
        return $this->belongsTo('App\Statuse');
    }

    /* Start custom functions */

    // Below the marker so a hellotree CMS page-schema save cannot rewrite it away.

    // A top-up is a financial record; deleting one must not remove the evidence.
    use \Illuminate\Database\Eloquent\SoftDeletes;

    public $appends = ['full_path'];

    /**
     * Receipts live on the private disk, so there is no public URL to hand out.
     * This returns the authenticated, owner-scoped route instead.
     *
     * The shape stays an object with a `receipt_image` key rather than collapsing to
     * null, because paymentRow.tsx dereferences `full_path.receipt_image` without
     * optional chaining and a null here would crash the payments page render.
     *
     * The previous implementation called Storage::url($this->receipt_image) directly.
     * That is the bug App\Concerns\HasFullPath exists to avoid: Storage::url(null)
     * returns the non-empty string "<APP_URL>/storage/", so a transfer with no
     * receipt rendered as a broken image rather than as "no receipt".
     */
    public function getFullPathAttribute(): array
    {
        $receipt_image = blank($this->receipt_image) || blank($this->id)
            ? null
            : URL::temporarySignedRoute('credits.receipt', now()->addMinutes(30), ['id' => $this->id]);

        return compact('receipt_image');
    }

    /* End custom functions */
}
