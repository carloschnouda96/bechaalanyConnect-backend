<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /**
     * SoftDeletes must be on the AUTH model too, not just on App\User.
     *
     * Both classes map to `users`. If only the CMS-facing App\User soft-deleted,
     * a "deleted" account would still be found by Auth::attempt() and by Sanctum's
     * token resolution — i.e. deleting a user would hide them from the admin while
     * leaving them able to sign in and spend their balance. The global scope added
     * here is what actually locks them out.
     */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    // KYC verification statuses (verification_statuses table)
    const VERIFICATION_UNSUBMITTED = 1;
    const VERIFICATION_PENDING = 2;
    const VERIFICATION_APPROVED = 3;
    const VERIFICATION_REJECTED = 4;

    const VERIFICATION_STATUS_SLUGS = [
        self::VERIFICATION_UNSUBMITTED => 'unsubmitted',
        self::VERIFICATION_PENDING => 'pending',
        self::VERIFICATION_APPROVED => 'approved',
        self::VERIFICATION_REJECTED => 'rejected',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username',
        'email',
        'password',
        'country',
        'verification_token',
        'email_verified',
        'password_reset_token',
        'google_id',
        'account_verification_code',
        'country',
        'phone_number',
        'is_business_user',
        'business_name',
        'business_location',
        'user_types_id',
        'credits_balance',
        'total_purchases',
        'received_amount',
        'id_front_image',
        'id_back_image',
        'selfie_image',
        'verification_statuses_id',
    ];

    /*
     | `public $with = ['orders', 'credits', 'user_types.priceVariations']` used to
     | live here. This is the model the auth guard resolves, so it ran on EVERY
     | authenticated request: resolving auth()->user() pulled the user's entire
     | unpaginated order history and entire credit-transfer history, and then
     | cascaded — Order eager-loads product_variation.product, Product eager-loads
     | subcategory.category, ProductsVariation eager-loads priceVariations. Four
     | levels of $with, growing without bound as a customer places more orders, on
     | requests that only needed the user's id.
     |
     | It also fired inside OrderController::saveOrder's DB::transaction while
     | holding a lockForUpdate row lock on that user, lengthening the window in
     | which concurrent orders block.
     |
     | The relations are still loaded explicitly where they are actually wanted:
     |   routes/api.php  /user/profile   → $request->user()->orders / ->credits
     |   SessionController::store        → loadMissing(...)
     |   SessionController::updateProfile→ loadMissing(...)
     */

    protected $appends = ['verification_status'];

    public function getVerificationStatusAttribute()
    {
        return self::VERIFICATION_STATUS_SLUGS[$this->verification_statuses_id] ?? 'unsubmitted';
    }

    public function orders()
    {
        return $this->hasMany('App\Order', 'users_id')->with(['product_variation.product'])->orderBy('created_at', 'desc');
    }

    public function credits()
    {
        return $this->hasMany('App\CreditsTransfer', 'users_id')->with(['credits_types'])->orderBy('created_at', 'desc');
    }

    public function user_types()
    {
        return $this->belongsTo('App\UserType');
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // Credential-equivalent: anyone holding these can complete a password reset
        // or an email verification for this account without knowing the password.
        // They were previously serialised by GET /api/user, GET /{locale}/user/profile
        // and by the login and register responses.
        'verification_token',
        'password_reset_token',
        'account_verification_code',
        'google_id',
        // KYC document paths. Not secret in themselves, but they point at government
        // ID scans and a selfie, so they stay server-side.
        'id_front_image',
        'id_back_image',
        'selfie_image',
        'cms_draft_flag',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
}
