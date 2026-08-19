<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;



class User extends Model
{


    protected $table = 'users';

    protected $guarded = ['id'];



    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('users.cms_draft_flag', '!=', 1);
        });
    }
    public function user_types()
    {
        return $this->belongsTo('App\UserType');
    }
    public function verification_statuses()
    {
        return $this->belongsTo('App\VerificationStatus');
    }

    /* Start custom functions */

    // Below the marker so a hellotree CMS page-schema save cannot rewrite it away.

    use \App\Concerns\HidesExtraAttributes;

    // Required, not merely nice to have: credit_ledger_entries.user_id is
    // ON DELETE RESTRICT, so a hard delete would now fail with a raw SQL error in
    // the CMS. SoftDeletes turns the delete button into an UPDATE and keeps the
    // user's order, top-up and ledger history intact.
    use \Illuminate\Database\Eloquent\SoftDeletes;

    /**
     * This model had NO $hidden at all, and it is the class Order::users() and
     * CreditsTransfer::users() resolve to. OrderController::getUserOrders eager-loaded
     * that relation, so every page of GET /{locale}/user/orders serialised the full
     * users row to the client — including the bcrypt password hash, the live
     * password_reset_token and the email verification code. A logged-in customer
     * could read another user's reset token from their own order list and take the
     * account over permanently.
     *
     * Kept in sync with App\Models\User::$hidden — both classes map to `users`.
     */
    protected $extraHidden = [
        'password',
        'remember_token',
        'verification_token',
        'password_reset_token',
        'account_verification_code',
        'google_id',
        'id_front_image',
        'id_back_image',
        'selfie_image',
        'cms_draft_flag',
    ];

    // `public $with = ['orders', 'user_types.priceVariations']` removed for the same
    // reason as on App\Models\User: this class is reached through Order::users() and
    // CreditsTransfer::users(), so eager-loading orders here meant every order in a
    // list dragged back its owner's complete order history.

    public function orders()
    {
        return $this->hasMany('App\Order', 'users_id');
    }

    /* End custom functions */
}
