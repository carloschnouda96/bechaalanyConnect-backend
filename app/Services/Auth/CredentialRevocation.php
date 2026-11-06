<?php

namespace App\Services\Auth;

use App\ApiKey;
use App\ApiWebhook;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * What a password change or reset must cancel beyond the password itself.
 *
 * Both are how a customer reacts to "someone else may have my account", so anything
 * an intruder could have set up with a stolen session has to stop working: an API key
 * spends the balance and reads delivered codes, a webhook URL receives those codes,
 * and an unused reset link can replace the new password. Sanctum tokens are not here
 * because the two paths treat them differently (a change keeps the caller's own).
 */
class CredentialRevocation
{
    /** Per account and source. A shared count would let strangers lock the owner out. */
    public const LOGIN_FAILURES_PER_HOUR = 30;

    /** Across all sources this only raises a signal: nothing may refuse the owner on it. */
    public const LOGIN_FAILURE_ALERT_THRESHOLD = 300;

    public static function loginFailureKey(string $email, string $ip): string
    {
        return 'login-failures:' . sha1(strtolower(trim($email)) . '|' . $ip);
    }

    public static function loginFailureSignalKey(string $email): string
    {
        return 'login-failure-signal:' . sha1(strtolower(trim($email)));
    }

    public static function resetCooldownKey(string $email): string
    {
        return 'password-reset:recent:' . sha1(strtolower(trim($email)));
    }

    /**
     * @return bool whether API access was cut, so the owner can be told to issue a new
     *              key: this stops a reseller's integration until they do.
     */
    public static function afterPasswordChange(User $user): bool
    {
        $user->forceFill(['password_reset_token' => null])->save();
        Cache::forget(self::resetCooldownKey($user->email));
        RateLimiter::clear(self::loginFailureSignalKey($user->email));

        $keys = ApiKey::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $webhooks = ApiWebhook::where('user_id', $user->id)->delete();

        return $keys > 0 || $webhooks > 0;
    }
}
