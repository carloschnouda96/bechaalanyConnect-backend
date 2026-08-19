<?php

namespace App\Http\Controllers\auth;


use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\GoogleIdTokenVerifier;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Http\Request;


class SocialiteController extends Controller
{

    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::where('google_id', $googleUser->id)
            ->orWhere('email', $googleUser->email)
            ->first();

        if ($user) {
            // Link Google ID if not already linked
            if (!$user->google_id) {
                $user->google_id = $googleUser->id;
                $user->email_verified = 1;
                $user->save();
            }
        } else {
            $user = User::create([
                'username' => $googleUser->name,
                'email' => $googleUser->email,
                'google_id' => $googleUser->id,
                'password' => bcrypt(str()->random(24)), // random password
                'email_verified' => 1,
                'credits_balance' => 0,
                'total_purchases' => 0,
                'received_amount' => 0,
                'verification_statuses_id' => User::VERIFICATION_UNSUBMITTED,
            ]);
        }

        // Issue token for API
        $token = $user->createToken('auth_token')->plainTextToken;

        // Redirect or return token as JSON
        return redirect(config('app.front_url') . "/oauth-success?token=$token");
    }

    /**
     * Exchange a Google ID token for a Sanctum token.
     *
     * Called server-to-server from the Next.js NextAuth `jwt` callback, which
     * already receives `account.id_token` from Google.
     *
     * SECURITY: the request body is NOT a source of identity. It previously was —
     * this endpoint accepted `email`, `username` and `google_id` as plain fields and
     * issued a working bearer token for whichever account matched, with no proof of
     * anything. Because the route is public, that let anyone take over any account
     * by POSTing its email address. Every identity value below is now read out of
     * the cryptographically verified token instead.
     */
    public function syncUser(Request $request, GoogleIdTokenVerifier $verifier)
    {
        $request->validate([
            'id_token' => 'required|string',
        ]);

        // Throws InvalidGoogleTokenException (renders 401) unless the signature,
        // issuer, audience, expiry and email_verified claim all check out.
        $claims = $verifier->verify($request->input('id_token'));

        // Match on the Google subject first: it is the stable, immutable account key.
        // Falling back to email covers a user who registered with a password and is
        // now signing in with Google for the first time — safe only because the token
        // carried email_verified = true.
        $user = User::where('google_id', $claims['sub'])
            ->orWhere('email', $claims['email'])
            ->first();

        if ($user) {
            $attributes = [
                'google_id' => $claims['sub'],
                'email_verified' => 1,
            ];

            // Don't clobber a username the user chose themselves with their Google
            // display name; only fill it if we have nothing.
            if (blank($user->username) && filled($claims['name'])) {
                $attributes['username'] = $claims['name'];
            }

            $user->update($attributes);
        } else {
            $user = User::create([
                'username' => $claims['name'] ?: strstr($claims['email'], '@', true),
                'email' => $claims['email'],
                'google_id' => $claims['sub'],
                'password' => bcrypt(str()->random(40)),
                'email_verified' => 1,
                'credits_balance' => 0,
                // These two were missing here while the OAuth redirect path set them,
                // leaving Google-created users with NULL lifetime totals.
                'total_purchases' => 0,
                'received_amount' => 0,
                'verification_statuses_id' => User::VERIFICATION_UNSUBMITTED,
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }
}
