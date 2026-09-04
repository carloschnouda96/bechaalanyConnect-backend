<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);
        if (! Auth::attempt($attributes)) {
            return response()->json(['message' => __('auth.failed')], 401);
        }
        /** @var \App\Models\User $user */
        $user = Auth::user();
        if (! $user->email_verified) {
            return response()->json(['message' => __('auth.unverified')], 403);
        }
        $token = $user->createToken('api-token')->plainTextToken;
        // Reload relations so translatable fields (like user_types.title) resolve in the current locale
        $user->loadMissing(['orders', 'credits', 'user_types.priceVariations']);
        return response()->json(['token' => $token, 'user' => $user], 200);
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
            'username' => 'sometimes|required|string|min:3',
            'country' => 'sometimes|nullable|string|max:255',
            'phone_number' => 'sometimes|nullable|string|max:20',
        ]);

        // Update user with validated data
        $user->update($validatedData);

        // Reload relations so translatable fields (like user_types.title) resolve in the current locale
        $user->loadMissing(['orders', 'credits', 'user_types.priceVariations']);

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

        return response()->json(['message' => __('auth.password_changed')], 200);
    }
}
