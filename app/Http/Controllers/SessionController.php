<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\CredentialRevocation;
use App\Support\ClientIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Timebox;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function create()
    {
        return response()->json(['message' => 'Login endpoint. Use POST to login.'], 200);
    }

    public function store()
    {
        $attributes = request()->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);
        // Exact match first: the column's collation would otherwise accept an accented
        // look-alike of the address, and each look-alike gets its own rate-limit bucket.
        $account = User::findByExactEmail($attributes['email']);

        // Failures only, per account and source, and counted for unknown addresses too.
        $failureKey = CredentialRevocation::loginFailureKey($attributes['email'], ClientIp::of(request()));
        if (RateLimiter::tooManyAttempts($failureKey, CredentialRevocation::LOGIN_FAILURES_PER_HOUR)) {
            return response()->json(['message' => __('auth.too_many_failures')], 429);
        }

        if (! $account) {
            // Pays for the same hash a real account does: the time difference named which
            // addresses have accounts.
            // In the same timebox the guard gives a real account, or the floor itself tells them apart.
            app(Timebox::class)->call(
                fn () => Hash::check($attributes['password'], self::dummyHash()),
                (int) config('auth.timebox_duration', 200000)
            );
            $this->recordLoginFailure($failureKey, $attributes['email']);

            return response()->json(['message' => __('auth.failed')], 401);
        }

        // Same answer for an unverified row as for no account: a distinct one, after
        // registering an address with a password of your choosing, told a stranger whether
        // that address was already taken.
        if (! Auth::attempt(['email' => $account->email, 'password' => $attributes['password']]) || ! Auth::user()->email_verified) {
            $this->recordLoginFailure($failureKey, $attributes['email']);

            return response()->json(['message' => __('auth.failed')], 401);
        }
        /** @var \App\Models\User $user */
        $user = Auth::user();
        RateLimiter::clear($failureKey);
        $token = $user->createToken('api-token')->plainTextToken;
        // Reload relations so translatable fields (like user_types.title) resolve in the current locale
        $user->loadRecentActivity()->loadMissing(['user_types.priceVariations']);
        return response()->json(['token' => $token, 'user' => $user], 200);
    }

    private function recordLoginFailure(string $failureKey, string $email): void
    {
        RateLimiter::hit($failureKey, 3600);

        // Many sources converging on one account. Staff are told; the owner is not refused.
        $signalKey = CredentialRevocation::loginFailureSignalKey($email);
        if (RateLimiter::hit($signalKey, 3600) === CredentialRevocation::LOGIN_FAILURE_ALERT_THRESHOLD) {
            Log::critical('Many failed logins on one address from many sources', ['address_hash' => sha1(strtolower(trim($email)))]);
        }
    }

    public static function dummyHashKey(): string
    {
        return 'login:dummy-hash:' . config('hashing.bcrypt.rounds');
    }

    /**
     * Same cost as a stored password. Cached, because making one is a second bcrypt, which
     * would make an unknown address slower than a real one.
     */
    private static function dummyHash(): string
    {
        return Cache::rememberForever(self::dummyHashKey(), fn () => bcrypt(\Illuminate\Support\Str::random(32)));
    }

    public function destroy(Request $request)
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if ($user) {
            // Revoke ONLY the token this request authenticated with. This used to be
            // $user->tokens()->delete(), which signed the user out of every device
            // and browser they had ever logged in from — logging out on a phone
            // silently killed a desktop session mid-checkout.
            $current = $user->currentAccessToken();

            if ($current) {
                $current->delete();
            } else {
                // Session-guard fallback (no bearer token on the request).
                $user->tokens()->delete();
            }
        }

        // Auth::logout() is a no-op for the token guard; kept for the session guard.
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        return response()->json(['message' => __('api.session.logged_out')], 200);
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => __('auth.unauthenticated')], 401);
        }

        // Business fields and user_types_id are intentionally not updatable here:
        // only an admin can promote a user to a business user type (special pricing)
        $validatedData = $request->validate([
            // Only a changed name is held to the handle rule: Google-created accounts can
            // hold a display name with spaces, and must still be able to save their profile.
            'username' => [
                'sometimes', 'required', 'string', 'min:3', 'max:191',
                \Illuminate\Validation\Rule::when($request->input('username') !== $user->username, ['regex:' . User::USERNAME_PATTERN]),
            ],
            'country' => 'sometimes|nullable|string|max:255',
            'phone_number' => 'sometimes|nullable|string|max:20',
        ]);

        // Update user with validated data
        $user->update($validatedData);

        // Reload relations so translatable fields (like user_types.title) resolve in the current locale
        $user->loadRecentActivity()->loadMissing(['user_types.priceVariations']);

        return response()->json(['message' => __('api.session.profile_updated'), 'user' => $user], 200);
    }

    public function changePassword(Request $request)
    {
        // Set locale first, before any validation or processing
        $requestedLocale = request()->route('locale');
        if ($requestedLocale && in_array($requestedLocale, ['en', 'ar'])) {
            app()->setLocale($requestedLocale);
        }

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => __('auth.unauthenticated')], 401);
        }

        $validatedData = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|different:current_password|confirmed',
        ]);

        // Check if current password matches
        if (!Hash::check($validatedData['current_password'], $user->password)) {
            return response()->json(['message' => __('auth.password_incorrect')], 400);
        }

        // Update password
        $user->password = Hash::make($validatedData['new_password']);
        $user->save();

        // Changing a password is how a user reacts to a suspected compromise, so it
        // has to actually evict whoever else is holding a token. Every other session
        // is revoked; the caller's own token survives so they are not logged out of
        // the device they just did this on.
        $current = $user->currentAccessToken();
        $user->tokens()
            ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
            ->delete();

        $apiAccessRevoked = CredentialRevocation::afterPasswordChange($user);

        try {
            Mail::send('emails.password-changed', compact('user', 'apiAccessRevoked'), function ($message) use ($user) {
                $message->to($user->email)->subject(__('emails.subjects.password_changed'));
            });
        } catch (\Exception $e) {
            // The password IS already changed; a failed notice must not report an error.
            Log::error('Password-changed notification failed', ['exception' => $e]);
        }

        return response()->json(['message' => __('auth.password_changed')], 200);
    }
}
