<?php

namespace App\Http\Controllers\auth;


use App\Http\Controllers\Controller;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;


class SocialiteController extends Controller
{

    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        $googleUser = Socialite::driver('google')->stateless()->user();

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
            ]);
        }

        // Issue token for API
        $token = $user->createToken('auth_token')->plainTextToken;

        // Redirect or return token as JSON
        return redirect("http://localhost:3000/oauth-success?token=$token");
    }
}
