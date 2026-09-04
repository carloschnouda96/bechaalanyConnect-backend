<?php

namespace App\Http\Controllers\auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class RegisteredUserController extends Controller
{
    /** Wrong codes tolerated per email before a new code must be requested. */
    private const MAX_VERIFICATION_ATTEMPTS = 5;

    /** How long an emailed 6-digit verification code stays valid. */
    private const VERIFICATION_TTL_MINUTES = 30;

    /** How long a password-reset link stays valid. */
    private const RESET_TTL_MINUTES = 60;

    public function create()
    {
        return response()->json(['message' => 'Register endpoint. Use POST to register.'], 200);
    }

    public function store(Request $request)
    {
        // dd($request);
        $email_confirmation_token = bin2hex(random_bytes(16));
        //generate random verification code integer 6 digits for email verification

        $account_verification_code = random_int(100000, 999999);
        $request->validate([
            // `username` has a UNIQUE index but was not validated against it, so a
            // collision surfaced as a raw SQL integrity error instead of a field
            // message. `max` added: the column is varchar(191).
            'username' => ['required', 'string', 'min:3', 'max:191', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers()],
            // These two were read straight into User::create() with no rules at all.
            'country' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $request['verification_token'] = $email_confirmation_token;
        $request['email_verified'] = 0;

        // All users register as regular users; an admin can promote them to a
        // business user type (special pricing) later from the admin panel.
        $user = User::create([
            'username' => $request['username'],
            'email' => $request['email'],
            'password' => bcrypt($request['password']),
            'country' => $request['country'],
            'phone_number' => $request['phone'],
            'verification_token' => $email_confirmation_token,
            'account_verification_code' => $account_verification_code,
            'email_verified' => 0,
            'is_business_user' => 0,
            'business_name' => null,
            'business_location' => null,
            'user_types_id' => null,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_UNSUBMITTED,
        ]);
        // Starts the code's validity window; verifyEmail() checks it.
        Cache::put(self::issuedAtKey(strtolower(trim($user->email))), now(), now()->addMinutes(self::VERIFICATION_TTL_MINUTES));

        $confirm_email_url = config('app.front_url') . '/' . app()->getLocale() . '/email-verification/' . $user->email . '/' . $email_confirmation_token;
        try {
            Mail::send('emails.verify-email', compact('account_verification_code', 'user', 'confirm_email_url'), function ($message) use ($request) {
                $message->to($request['email'])->subject(__('emails.subjects.verify_email'));
            });
        } catch (\Exception $e) {
            // The exception message can carry SMTP host, port and account detail —
            // it goes to the log, never to the client.
            Log::error('Verification email sending failed', ['exception' => $e]);

            return response()->json([
                'message' => __('api.auth.registered_no_email'),
                'code' => 'verification_email_failed',
            ], 500);
        }
        return response()->json([
            'message' => __('api.auth.registered'),
            'verification_token' => $email_confirmation_token
        ], 201);
    }

    /**
     * Confirm an email address with the 6-digit code (and the emailed token).
     *
     * Previously this read request()->all() and indexed 'email', 'token' and 'code'
     * directly, so a request missing any of them raised an undefined-key error — an
     * unauthenticated 500 that, with the shipped APP_DEBUG=true, returned a full
     * stack trace. It also compared the code with `==`, which is a loose comparison
     * against an integer column, and had no attempt limit or expiry, so the 6-digit
     * code could be brute-forced.
     */
    public function verifyEmail(Request $request)
    {
        $attributes = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:191'],
            'code' => ['required', 'digits:6'],
        ]);

        $email = strtolower(trim($attributes['email']));

        // Attempt ceiling, held in the cache so this stays a code-only change.
        // Independent of the per-route throttle: that one bounds request rate, this
        // one bounds guesses against a single account before the code is burned.
        $attemptKey = 'verify-email:attempts:' . sha1($email);
        if (Cache::get($attemptKey, 0) >= self::MAX_VERIFICATION_ATTEMPTS) {
            return response()->json([
                'message' => __('api.auth.too_many_codes'),
                'code' => 'verification_locked',
            ], 429);
        }

        $user = User::where('email', $email)->first();

        $valid = $user
            && $user->verification_token !== null
            && $user->account_verification_code !== null
            && hash_equals((string) $user->verification_token, (string) $attributes['token'])
            && hash_equals((string) $user->account_verification_code, (string) $attributes['code'])
            && $this->verificationCodeIsFresh($email);

        if (!$valid) {
            Cache::put($attemptKey, Cache::get($attemptKey, 0) + 1, now()->addMinutes(30));

            // Deliberately identical whether the email is unknown, the token is wrong
            // or the code is wrong — otherwise this endpoint reports which accounts exist.
            return response()->json([
                'message' => __('api.auth.verification_failed'),
                'code' => 'verification_failed',
            ], 400);
        }

        $user->update([
            'email_verified' => 1,
            'verification_token' => null,
            'account_verification_code' => null,
        ]);

        Cache::forget($attemptKey);
        Cache::forget(self::issuedAtKey($email));

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => __('api.auth.email_verified'),
            'token' => $token,
            'user' => $user,
        ], 200);
    }

    /**
     * Codes issued before this cache-expiry mechanism existed have no issued-at
     * marker; those are accepted so the change does not lock out users who
     * registered just before deploy. Newly issued codes always have one.
     */
    private function verificationCodeIsFresh(string $email): bool
    {
        $issuedAt = Cache::get(self::issuedAtKey($email));

        return $issuedAt === null || $issuedAt->addMinutes(self::VERIFICATION_TTL_MINUTES)->isFuture();
    }

    private static function issuedAtKey(string $email): string
    {
        return 'verify-email:issued:' . sha1($email);
    }

    public function verifyEmailSendNewCode(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255']
        ]);
        $email = strtolower(trim($request->input('email')));
        $user = User::where('email', $email)->first();

        // One response for every path.
        //
        // This used to 404 with "You already have a verification code" when a code
        // was outstanding, and to fatal on $user->account_verification_code when the
        // email was unknown ($user was null). Between them, the two behaviours told
        // an anonymous caller exactly which addresses are registered and which are
        // mid-verification. The refusal to reissue also left users stuck: if the
        // first email never arrived, there was no way to get another.
        $genericResponse = response()->json([
            'message' => __('api.auth.code_resent'),
        ], 200);

        if (!$user || $user->email_verified) {
            return $genericResponse;
        }

        $account_verification_code = random_int(100000, 999999);
        $user->account_verification_code = $account_verification_code;

        // A resend re-issues the emailed link token too when it is missing, so the
        // link in the new email is always usable.
        if (blank($user->verification_token)) {
            $user->verification_token = bin2hex(random_bytes(16));
        }

        $user->save();

        // Reset the expiry window and the wrong-guess counter for the new code.
        Cache::put(self::issuedAtKey($email), now(), now()->addMinutes(self::VERIFICATION_TTL_MINUTES));
        Cache::forget('verify-email:attempts:' . sha1($email));

        $confirm_email_url = config('app.front_url') . '/' . app()->getLocale() . '/email-verification/' . $user->email . '/' . $user->verification_token;

        try {
            Mail::send('emails.verify-email', compact('account_verification_code', 'user', 'confirm_email_url'), function ($message) use ($email) {
                $message->to($email)->subject(__('emails.subjects.verify_email'));
            });
        } catch (\Exception $e) {
            // Swallowed on purpose: reporting a send failure here would re-introduce
            // the enumeration signal. Logged instead.
            Log::error('Verification code resend failed', ['exception' => $e]);
        }

        // The verification_token is NOT returned. It is the secret in the emailed
        // link; echoing it let anyone who knew an address complete that account's
        // verification without ever reading the inbox.
        return $genericResponse;
    }

    public function forgotPassword()
    {
        return response()->json(['message' => 'Forgot password endpoint. Use POST to send email.'], 200);
    }

    public function forgotPasswordSendEmail()
    {
        $request = request();

        $attributes = $request->validate([
            'email' => ['required', 'email', 'max:255']
        ]);
        $email = strtolower(trim($attributes['email']));
        $user = User::where('email', $email)->first();

        // Identical response whether or not the address exists.
        //
        // This used to return 404 "This Email is not registered." for unknown
        // addresses, which turned the endpoint into a free membership oracle: feed it
        // a list of emails and it tells you which ones have accounts here. Those
        // accounts hold credit balances, so that list has direct value to an attacker.
        $genericResponse = response()->json([
            'message' => __('api.auth.reset_link_sent'),
        ], 200);

        if (!$user) {
            return $genericResponse;
        }

        $password_reset_token = bin2hex(random_bytes(16));
        $user->password_reset_token = $password_reset_token;
        $user->save();

        // Reset links were valid forever — a token from a year-old email still
        // worked. Recorded here and enforced in resetPasswordSendEmail().
        Cache::put(self::resetIssuedAtKey($email), now(), now()->addMinutes(self::RESET_TTL_MINUTES));

        $reset_password_url = config('app.front_url') . '/' . $requestedLocale . '/reset-password/' . $email . '/' . $password_reset_token;

        try {
            Mail::send('emails.forgot-password', compact('reset_password_url', 'attributes'), function ($message) use ($email) {
                $message->to($email)->subject(__('emails.subjects.reset_password'));
            });
        } catch (\Exception $e) {
            // Logged, not surfaced: a 500 here only for real addresses would leak
            // exactly what the generic response is designed to hide.
            Log::error('Password reset email sending failed', ['exception' => $e]);
        }

        return $genericResponse;
    }

    private static function resetIssuedAtKey(string $email): string
    {
        return 'password-reset:issued:' . sha1($email);
    }

    // public function resetPassword($email, $token)
    // {
    //     $user = User::where('email', $email)->first();
    //     if ($user && ($user->password_reset_token == $token)) {
    //         return response()->json(['message' => 'Token valid. You can reset your password.', 'email' => $email, 'token' => $token], 200);
    //     }
    //     return response()->json(['error' => 'Password reset failed.'], 400);
    // }

    public function resetPasswordSendEmail()
    {
        $request = request();
        $attributes = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:191'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers()],
        ]);

        $email = strtolower(trim($attributes['email']));
        $user = User::where('email', $email)->first();

        // The reset token from the emailed link must match — otherwise anyone
        // knowing an email address could take over the account.
        $tokenMatches = $user
            && $user->password_reset_token
            && hash_equals($user->password_reset_token, $attributes['token']);

        // Expiry. Tokens issued before this mechanism existed have no marker and are
        // still accepted once, so nobody mid-reset is stranded by the deploy.
        $issuedAt = Cache::get(self::resetIssuedAtKey($email));
        $notExpired = $issuedAt === null || $issuedAt->addMinutes(self::RESET_TTL_MINUTES)->isFuture();

        if (!$tokenMatches || !$notExpired) {
            return response()->json([
                'message' => __('api.auth.reset_link_invalid'),
                'code' => 'reset_token_invalid',
            ], 400);
        }

        $user->update([
            'password' => bcrypt($attributes['password']),
            // Consumed: the link cannot be replayed.
            'password_reset_token' => null,
        ]);

        Cache::forget(self::resetIssuedAtKey($email));

        // A reset is the response to "someone else may have my account". Evict every
        // existing session before issuing the new one.
        $user->tokens()->delete();

        $token = $user->createToken('api-token')->plainTextToken;

        try {
            Mail::send('emails.password-changed', compact('user'), function ($message) use ($email) {
                $message->to($email)->subject(__('emails.subjects.password_changed'));
            });
        } catch (\Exception $e) {
            // The password IS already changed; a failed courtesy email must not
            // turn a successful reset into an error for the user.
            Log::error('Password-changed notification failed', ['exception' => $e]);
        }

        return response()->json(['message' => __('api.auth.password_reset'), 'token' => $token, 'user' => $user], 200);
    }
}
