<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

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
        'received_amount'
    ];

    public $with = ['orders', 'credits', 'user_types.priceVariations'];

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
