<?php

namespace App\Http\Controllers\auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\CredentialRevocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class RegisteredUserController extends Controller
{
    /** Wrong codes tolerated per email before a new code must be requested. */
    private const MAX_VERIFICATION_ATTEMPTS = 5;

    /** Wrong codes per address per day. A resend resets the counter above, not this. */
    private const DAILY_VERIFICATION_ATTEMPTS = 20;

    /** How long an emailed 6-digit verification code stays valid. */
    private const VERIFICATION_TTL_MINUTES = 30;

    /** How long a password-reset link stays valid. */
    private const RESET_TTL_MINUTES = 60;

    /** A repeat request inside this window sends nothing and keeps the emailed link valid. */
    private const RESET_COOLDOWN_MINUTES = 5;

    /** One verification mail per inbox in this window, whatever asked for it. */
    private const VERIFICATION_MAIL_COOLDOWN_SECONDS = 60;

    private const GLOBAL_MAIL_KEY = 'verification-mail-global';

    /** Sign-up forms kept per address. At one a minute, a stranger needs this long to push one out. */
    private const MAX_PENDING_FORMS = 20;

    /** How long a sign-up waits for its address to be proven. */
    private const PENDING_SIGNUP_HOURS = 24;

    public function create()
    {
        return response()->json(['message' => 'Register endpoint. Use POST to register.'], 200);
    }

    /**
     * Holds a sign-up in the cache until its address is proven; no users row exists before
     * that. A row from the first POST let a stranger pre-set the password of someone else's
     * address, reserved usernames (which told anyone whether the row was created) and
     * blocked the real owner with an accented look-alike.
     */
    public function store(Request $request)
    {
        $request->validate([
            // Unique against real accounts only: a pending sign-up reserves nothing.
            'username' => ['required', 'string', 'regex:' . User::USERNAME_PATTERN, 'unique:users,username'],
            // No `unique` rule: a different answer for a taken address told anyone which
            // emails have accounts here. The owner gets a notice instead, below.
            // Plain ASCII because users.email is utf8mb4_unicode_ci, which equates accented
            // spellings with the real address.
            'email' => ['required', 'email', 'max:255', 'regex:/^[\x21-\x7E]+$/'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers()],
            // These two were read straight into User::create() with no rules at all.
            'country' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $email = strtolower(trim((string) $request['email']));

        // Hashed on every path, so a taken address answers no faster than a new one.
        $passwordHash = bcrypt($request['password']);

        // Identical for both paths, so the mail cooldown tells nobody which this was.
        $mayMail = Cache::add(self::mailCooldownKey($email), 1, now()->addSeconds(self::VERIFICATION_MAIL_COOLDOWN_SECONDS));
        if (!$mayMail) {
            return $this->registeredResponse($this->issueDecoyHandle($email));
        }

        $this->resetGuessCount($email);

        $existing = $this->blockingAccount($email);
        if ($existing) {
            return $this->answerForTakenAddress($existing, $email);
        }

        // Counted where a mail goes out, for sign-ups only, so nobody can use it up to stop an
        // owner's password reset, and a request that sends nothing spends none of it.
        if (RateLimiter::tooManyAttempts(self::GLOBAL_MAIL_KEY, (int) config('services.auth.mail_hourly_cap', 2000))) {
            Log::warning('Sign-up mail cap reached; no verification mail sent');

            return $this->registeredResponse($this->issueDecoyHandle($email));
        }
        RateLimiter::hit(self::GLOBAL_MAIL_KEY, 3600);

        // A repeat request must not invalidate what the owner was already mailed, or anyone who
        // knows the address could keep them from finishing. The link and a still-valid code
        // stay, and each form keeps its own handle.
        $pending = Cache::get(self::pendingKey($email));
        $codeIsFresh = $pending && $this->verificationCodeIsFresh($email);
        $linkToken = $pending['link_token'] ?? bin2hex(random_bytes(16));
        $code = $codeIsFresh ? (int) $pending['code'] : random_int(100000, 999999);
        $handle = bin2hex(random_bytes(16));

        $forms = $pending['forms'] ?? [];
        $forms[hash('sha256', $handle)] = [
            'username' => $request['username'],
            'password' => $passwordHash,
            'country' => $request['country'],
            'phone' => $request['phone'],
        ];

        Cache::put(self::pendingKey($email), [
            'email' => $email,
            'code' => (string) $code,
            'link_token' => $linkToken,
            'forms' => array_slice($forms, -self::MAX_PENDING_FORMS, null, true),
        ], now()->addHours(self::PENDING_SIGNUP_HOURS));

        // Starts the code's validity window; verifyEmail() checks it.
        if (!$codeIsFresh) {
            Cache::put(self::issuedAtKey($email), now(), self::issuedAtMarkerTtl());
        }

        // After the response, like every other pre-login mail: an SMTP round trip on only some
        // paths is a timing answer. A lost mail is logged, and resend recovers it.
        $this->sendAfterResponse(fn () => $this->mailVerification($email, $code, $linkToken), 'Verification email sending failed');

        // Not the token from the emailed link: this reply goes to whoever typed the address.
        // The handle lets the signup page finish verification, and still needs the code.
        Cache::put(self::handleKey($handle), ['email' => $email], now()->addHours(self::PENDING_SIGNUP_HOURS));

        return $this->registeredResponse($handle);
    }

    /**
     * The account that makes this address unavailable, if any. Collation-wide on purpose:
     * a verified look-alike keeps its address. An unverified look-alike proved nothing and
     * has no ledger, so it no longer stands in the way of the real address.
     */
    private function blockingAccount(string $email): ?User
    {
        $existing = User::withTrashed()->where('email', $email)->first();

        if ($existing && !$existing->email_verified && strtolower(trim($existing->email)) !== $email) {
            try {
                $existing->forceDelete();

                return null;
            } catch (\Illuminate\Database\QueryException $e) {
                Log::warning('Unverified look-alike account could not be removed', ['user_id' => $existing->id]);
            }
        }

        return $existing;
    }

    private function mailVerification(string $email, int $code, string $linkToken): void
    {
        $account_verification_code = $code;
        $confirm_email_url = config('app.front_url') . '/' . app()->getLocale() . '/email-verification/' . rawurlencode($email) . '/' . $linkToken;

        Mail::send('emails.verify-email', compact('account_verification_code', 'confirm_email_url'), function ($message) use ($email) {
            $message->to($email)->subject(__('emails.subjects.verify_email'));
        });
    }

    private function registeredResponse(string $handle)
    {
        return response()->json([
            'message' => __('api.auth.registered'),
            'verification_token' => $handle,
        ], 201);
    }

    /** Same reply as a new account, with a handle that verifies nothing. */
    private function answerForTakenAddress(User $existing, string $email)
    {
        if (!$existing->trashed()) {
            $this->sendAfterResponse(function () use ($existing) {
                Mail::send('emails.account-exists', ['user' => $existing], function ($message) use ($existing) {
                    $message->to($existing->email)->subject(__('emails.subjects.account_exists'));
                });
            }, 'Account-exists notice failed');
        }

        return $this->registeredResponse($this->issueDecoyHandle($email));
    }

    /** Counted like a real handle by verifyEmail(), so wrong codes answer the same. */
    private function issueDecoyHandle(string $email): string
    {
        $handle = bin2hex(random_bytes(16));
        Cache::put(self::handleKey($handle), ['email' => $email], now()->addHours(self::PENDING_SIGNUP_HOURS));

        return $handle;
    }

    private static function handleKey(string $handle): string
    {
        return 'verify-email:handle:' . hash('sha256', $handle);
    }

    private static function pendingKey(string $email): string
    {
        return 'verify-email:pending:' . sha1(strtolower(trim($email)));
    }

    private static function mailCooldownKey(string $email): string
    {
        return 'verify-email:mail-cooldown:' . sha1($email);
    }

    /** Google proved the address and made the account, so the sign-up has nothing left to do. */
    public static function forgetPendingSignup(string $email): void
    {
        Cache::forget(self::pendingKey($email));
    }

    private function resetGuessCount(string $email): void
    {
        Cache::forget('verify-email:attempts:' . sha1($email));
        Cache::forget('verify-email:link-attempts:' . sha1($email));
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
            // Blank is refused, not treated as absent: only the modal may keep the form's password.
            'password' => ['sometimes', 'required', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers()],
        ]);

        $email = strtolower(trim($attributes['email']));
        $token = (string) $attributes['token'];

        $pending = Cache::get(self::pendingKey($email));
        $user = $pending ? null : User::findByExactEmail($email);

        $handleEntry = Cache::get(self::handleKey($token));
        $holdsHandleForEmail = is_array($handleEntry) && hash_equals((string) ($handleEntry['email'] ?? ''), $email);

        $form = $pending['forms'][hash('sha256', $token)] ?? null;
        $viaHandle = $form !== null && $holdsHandleForEmail;
        $viaPendingLink = $pending && hash_equals($pending['link_token'], $token);
        // A row from before sign-ups were held in the cache. Only its emailed link works.
        $viaRowLink = $user
            && !$user->email_verified
            && filled($user->verification_token)
            && hash_equals((string) $user->verification_token, $token);

        // Attempt ceilings, independent of the per-route throttle. A row's link has its own
        // counter, because anyone can get a handle for free and spend the handle counter.
        // The link of a pending sign-up is exempt: its 128-bit token already proves the inbox,
        // and a stranger's guesses must not be able to stop the owner using it. A handle for a
        // taken address counts like a real one, so a lock-out reveals nothing.
        $countedAsHandle = !$viaPendingLink && !$viaRowLink && $holdsHandleForEmail;
        [$attemptKey, $dailyKey] = $viaRowLink
            ? ['verify-email:link-attempts:' . sha1($email), 'verify-email:link-daily:' . sha1($email)]
            : ['verify-email:attempts:' . sha1($email), 'verify-email:daily:' . sha1($email)];
        $counted = $countedAsHandle || $viaRowLink;

        if ($counted && (
            Cache::get($attemptKey, 0) >= self::MAX_VERIFICATION_ATTEMPTS
            || RateLimiter::tooManyAttempts($dailyKey, self::DAILY_VERIFICATION_ATTEMPTS)
        )) {
            return response()->json([
                'message' => __('api.auth.too_many_codes'),
                'code' => 'verification_locked',
            ], 429);
        }

        $storedCode = match (true) {
            (bool) ($viaHandle || $viaPendingLink) => $pending['code'],
            (bool) $viaRowLink => $user->account_verification_code,
            default => null,
        };

        $valid = $storedCode !== null
            && hash_equals((string) $storedCode, (string) $attributes['code'])
            && $this->verificationCodeIsFresh($email);

        if (!$valid) {
            if ($counted) {
                Cache::put($attemptKey, Cache::get($attemptKey, 0) + 1, now()->addMinutes(30));
                RateLimiter::hit($dailyKey, 86400);
            }

            return $this->verificationFailed();
        }

        // The emailed link proves the inbox, not who filled in the sign-up form, so nothing from
        // that form may become the account's on this path. The signup page's modal holds a handle
        // only its own request received and sends no password, so it keeps the form's. A password
        // typed anywhere always wins: a stranger can send the owner a page link with their handle.
        $newPassword = $attributes['password'] ?? null;
        if (($viaPendingLink || $viaRowLink) && blank($newPassword)) {
            return response()->json([
                'message' => __('api.auth.password_required'),
                'code' => 'password_required',
            ], 422);
        }

        if ($viaRowLink) {
            $user->tokens()->delete();
            $user->update([
                'email_verified' => 1,
                'verification_token' => null,
                'account_verification_code' => null,
                'password' => bcrypt($newPassword),
            ]);
        } else {
            // Only the modal's own request keeps its form.
            $account = ['email' => $email, 'username' => 'user_' . bin2hex(random_bytes(6)), 'country' => null, 'phone' => null];
            if ($viaHandle && blank($newPassword)) {
                $account = ['email' => $email] + $form;
            }

            $user = $this->createVerifiedAccount($account, filled($newPassword) ? bcrypt($newPassword) : $form['password']);
            if ($user instanceof \Illuminate\Http\JsonResponse) {
                return $user;
            }
        }

        Cache::forget($attemptKey);
        Cache::forget(self::issuedAtKey($email));
        Cache::forget(self::handleKey($token));
        Cache::forget(self::pendingKey($email));

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => __('api.auth.email_verified'),
            'token' => $token,
            'user' => $user,
        ], 200);
    }

    /** @return User|\Illuminate\Http\JsonResponse */
    private function createVerifiedAccount(array $pending, string $passwordHash)
    {
        // Another account took this address or username while the sign-up waited.
        if (User::withTrashed()->where('email', $pending['email'])->exists()) {
            Cache::forget(self::pendingKey($pending['email']));

            return $this->verificationFailed();
        }

        if (User::withTrashed()->where('username', $pending['username'])->exists()) {
            return response()->json([
                'message' => __('api.auth.username_taken'),
                'code' => 'username_taken',
            ], 422);
        }

        try {
            // All users register as regular users; an admin can promote them to a
            // business user type (special pricing) later from the admin panel.
            return User::create([
                'username' => $pending['username'],
                'email' => $pending['email'],
                'password' => $passwordHash,
                'country' => $pending['country'],
                'phone_number' => $pending['phone'],
                'verification_token' => null,
                'account_verification_code' => null,
                'email_verified' => 1,
                'business_name' => null,
                'business_location' => null,
                'user_types_id' => null,
                'credits_balance' => 0,
                'total_purchases' => 0,
                'received_amount' => 0,
                'verification_statuses_id' => User::VERIFICATION_UNSUBMITTED,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return $this->verificationFailed();
        }
    }

    /** Identical for an unknown email, a wrong token and a wrong code. */
    private function verificationFailed()
    {
        return response()->json([
            'message' => __('api.auth.verification_failed'),
            'code' => 'verification_failed',
        ], 400);
    }

    /**
     * A missing marker counts as expired: it used to count as fresh, and its cache TTL
     * equalled the window, so the marker vanished at the moment it should have started
     * refusing and no code ever expired. A resend issues a new code and a new marker.
     */
    private function verificationCodeIsFresh(string $email): bool
    {
        $issuedAt = Cache::get(self::issuedAtKey($email));

        return $issuedAt !== null && $issuedAt->copy()->addMinutes(self::VERIFICATION_TTL_MINUTES)->isFuture();
    }

    /** Outlives the window, so an expired code is refused by the check above. */
    private static function issuedAtMarkerTtl(): \DateTimeInterface
    {
        return now()->addMinutes(self::VERIFICATION_TTL_MINUTES * 2);
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
        $user = User::findByExactEmail($email);

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

        // Before any lookup, so an address with an account behaves like one without.
        if (!Cache::add(self::mailCooldownKey($email), 1, now()->addSeconds(self::VERIFICATION_MAIL_COOLDOWN_SECONDS))) {
            return $genericResponse;
        }

        // Reset the wrong-guess counter for every address. Doing it only for a real code
        // let a caller tell a taken address by when its lock-out lifted.
        $this->resetGuessCount($email);

        $pending = Cache::get(self::pendingKey($email));
        if ($pending) {
            // Same code while it is valid: a stranger's resend must not invalidate the mail
            // the owner is reading.
            if (!$this->verificationCodeIsFresh($email)) {
                $pending['code'] = (string) random_int(100000, 999999);
                Cache::put(self::issuedAtKey($email), now(), self::issuedAtMarkerTtl());
            }
            $code = (int) $pending['code'];
            Cache::put(self::pendingKey($email), $pending, now()->addHours(self::PENDING_SIGNUP_HOURS));
            $this->sendAfterResponse(fn () => $this->mailVerification($email, $code, $pending['link_token']), 'Verification code resend failed');

            return $genericResponse;
        }

        if (!$user || $user->email_verified) {
            return $genericResponse;
        }

        // Same code while it is valid, as above.
        $codeIsFresh = filled($user->account_verification_code) && $this->verificationCodeIsFresh($email);
        $account_verification_code = $codeIsFresh ? (int) $user->account_verification_code : random_int(100000, 999999);
        $user->account_verification_code = $account_verification_code;

        // A resend re-issues the emailed link token too when it is missing, so the
        // link in the new email is always usable.
        if (blank($user->verification_token)) {
            $user->verification_token = bin2hex(random_bytes(16));
        }

        $user->save();

        if (!$codeIsFresh) {
            Cache::put(self::issuedAtKey($email), now(), self::issuedAtMarkerTtl());
        }

        $linkToken = $user->verification_token;
        $target = $user->email;
        $this->sendAfterResponse(fn () => $this->mailVerification($target, $account_verification_code, $linkToken), 'Verification code resend failed');

        // The verification_token is NOT returned. It is the secret in the emailed
        // link; echoing it let anyone who knew an address complete that account's
        // verification without ever reading the inbox.
        return $genericResponse;
    }

    /**
     * After the response is on its way: an SMTP round trip only for a real address is a
     * timing answer to the question the generic reply refuses to answer. Failures are
     * logged, because reporting them would be the same answer.
     */
    private function sendAfterResponse(callable $send, string $failureMessage): void
    {
        $sent = false;

        app()->terminating(function () use ($send, $failureMessage, &$sent) {
            if ($sent) {
                return; // a long-lived application instance runs these once per request
            }
            $sent = true;

            try {
                $send();
            } catch (\Exception $e) {
                Log::error($failureMessage, ['exception' => $e]);
            }
        });
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
        $user = User::findByExactEmail($email);

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

        // A repeat request would rotate the token, so anyone knowing the address could
        // keep killing the link the owner was just sent, and could flood their inbox.
        if (!Cache::add(CredentialRevocation::resetCooldownKey($email), 1, now()->addMinutes(self::RESET_COOLDOWN_MINUTES))) {
            return $genericResponse;
        }

        // The expiry is part of the token and of the hash, so it needs no cache entry
        // and cannot be altered: a changed timestamp hashes to something else.
        $password_reset_token = now()->addMinutes(self::RESET_TTL_MINUTES)->timestamp . '-' . bin2hex(random_bytes(16));
        // Hashed at rest, so reading this column (a database dump, the CMS) yields nothing usable.
        $user->password_reset_token = hash('sha256', $password_reset_token);
        $user->save();

        // LocaleMiddleware has already set the locale from the {locale} segment.
        // This used an undefined $requestedLocale, which threw before Mail::send for
        // every registered address: no reset mail ever went out, and the 500 (vs the
        // generic 200 for unknown addresses) re-opened the membership oracle above.
        $reset_password_url = config('app.front_url') . '/' . app()->getLocale() . '/reset-password/' . $email . '/' . $password_reset_token;

        $this->sendAfterResponse(function () use ($reset_password_url, $attributes, $user) {
            Mail::send('emails.forgot-password', compact('reset_password_url', 'attributes'), function ($message) use ($user) {
                $message->to($user->email)->subject(__('emails.subjects.reset_password'));
            });
        }, 'Password reset email sending failed');

        return $genericResponse;
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

        $user = User::findByExactEmail($attributes['email']);

        // The reset token from the emailed link must match — otherwise anyone
        // knowing an email address could take over the account.
        $tokenMatches = $user
            && $user->password_reset_token
            && hash_equals($user->password_reset_token, hash('sha256', (string) $attributes['token']));

        // A token with no embedded expiry predates this format and reads as 0: refused,
        // and its owner requests a new link.
        $notExpired = (int) strstr((string) $attributes['token'], '-', true) > now()->timestamp;

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
            // The link went to the inbox, so this is proof of the address. It also replaces
            // whatever password an unverified row was registered with.
            'email_verified' => 1,
            'verification_token' => null,
            'account_verification_code' => null,
        ]);

        $apiAccessRevoked = CredentialRevocation::afterPasswordChange($user);

        // A reset is the response to "someone else may have my account". Evict every
        // existing session before issuing the new one.
        $user->tokens()->delete();

        $token = $user->createToken('api-token')->plainTextToken;

        try {
            Mail::send('emails.password-changed', compact('user', 'apiAccessRevoked'), function ($message) use ($user) {
                $message->to($user->email)->subject(__('emails.subjects.password_changed'));
            });
        } catch (\Exception $e) {
            // The password IS already changed; a failed courtesy email must not
            // turn a successful reset into an error for the user.
            Log::error('Password-changed notification failed', ['exception' => $e]);
        }

        return response()->json(['message' => __('api.auth.password_reset'), 'token' => $token, 'user' => $user], 200);
    }
}
