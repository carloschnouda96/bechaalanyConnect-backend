<?php

namespace App\Services\Auth;

use App\ApiKey;
use App\ApiWebhook;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

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

        $keys = ApiKey::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $webhooks = ApiWebhook::where('user_id', $user->id)->delete();

        return $keys > 0 || $webhooks > 0;
    }
}
