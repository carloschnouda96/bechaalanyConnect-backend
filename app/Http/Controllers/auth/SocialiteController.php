<?php

namespace App\Http\Controllers\auth;


use App\Http\Controllers\Controller;
use App\Models\User;
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
                'received_amount' => 0
            ]);
        }

        // Issue token for API
        $token = $user->createToken('auth_token')->plainTextToken;

        // Redirect or return token as JSON
        return redirect("https://bechaalanyconnect.vercel.app/oauth-success?token=$token");
    }

    public function syncUser(Request $request)
    {

        $data = $request->validate([
            'email' => 'required|email',
            'username' => 'required|string',
            'google_id' => 'required|string',
        ]);

        $user = User::where('google_id', $data['google_id'])
            ->orWhere('email', $data['email'])
            ->first();

        if ($user) {
            // Update existing user
            $user->update([
                'username' => $data['username'],
                'google_id' => $data['google_id'],
                'email_verified' => 1,
            ]);
        } else {
            // Create new user
            $user = User::create([
                'username' => $data['username'],
                'email' => $data['email'],
                'google_id' => $data['google_id'],
                'password' => bcrypt(str()->random(24)),
                'email_verified' => 1,
                'credits_balance' => 0,
            ]);
        }

        // Issue Laravel token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }
}
