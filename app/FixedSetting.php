<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class FixedSetting extends Model  implements TranslatableContract
{
    use Translatable;

    protected $table = 'fixed_settings';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["create_account_button", "login_button", "footer_copyright", "categories_label", "homepage_label", "back_button_label", "amount", "quantity", "total", "related_products", "user_id_label", "user_id_placeholder", "phone_number_label", "phone_number_placeholder", "buy_now_button", "logout_button"];

    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('fixed_settings.cms_draft_flag', '!=', 1);
        });
    }

    /* Start custom functions */

    // Below the marker so a hellotree CMS page-schema save cannot rewrite it away.
    //
    // HasFullPath is used for its publicImageUrl() helper. This class defines its
    // own getFullPathAttribute() (two images, not one), and a method declared in the
    // class takes precedence over the trait's — no conflict.
    use \App\Concerns\HasFullPath;

    public $appends = ['full_path'];

    /**
     * The single settings row, or an empty instance when it is missing.
     *
     * `FixedSetting::first()->admin_email` appears in OrderController,
     * CreditsController and ContactController, and every one of them fatals with
     * "Attempt to read property on null" when the row is absent — which is not
     * hypothetical: the model carries a cms_draft_flag global scope, so an admin
     * saving Fixed Settings as a draft makes first() return null and takes down
     * order placement, credit requests and the contact form at once.
     *
     * Memoised per request; reads are frequent and the table holds exactly one row.
     */
    public static function current(): self
    {
        static $cached = null;

        return $cached ??= static::first() ?: new static();
    }

    /**
     * Admin notification address, or null when unset — so callers can skip sending
     * rather than crash.
     */
    public static function adminEmail(): ?string
    {
        $email = static::current()->admin_email;

        return blank($email) ? null : $email;
    }

    public function getFullPathAttribute()
    {
        // publicImageUrl() rather than Storage::url(): Storage::url(null) returns the
        // non-empty string "<APP_URL>/storage/", which the frontend cannot tell apart
        // from a real path and renders as a broken <img>.
        return [
            'logo' => self::publicImageUrl($this->logo),
            'dark_mode_logo' => self::publicImageUrl($this->dark_mode_logo),
        ];
    }

    /* End custom functions */
}
