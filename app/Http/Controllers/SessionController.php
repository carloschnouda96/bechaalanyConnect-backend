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
        // Prefer locale from route {locale}, then ?lang, then Accept-Language
        $routeLocale = request()->route('locale');
        $requestedLocale = $routeLocale ?: (request()->get('lang') ?? request()->getPreferredLanguage(['en', 'ar']));
        if ($requestedLocale && in_array($requestedLocale, ['en', 'ar'])) {
            app()->setLocale($requestedLocale);
        }

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

    public function destroy()
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if ($user) {
            $user->tokens()->delete(); // deletes all tokens for the user
        }
        Auth::logout();
        return response()->json(['message' => 'Logged out successfully.'], 200);
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validatedData = $request->validate([
            'username' => 'sometimes|required|string|min:3',
            'country' => 'sometimes|nullable|string|max:255',
            'phone_number' => 'sometimes|nullable|string|max:20',
            'is_business_user' => 'sometimes|boolean',
            'business_name' => 'sometimes|nullable|string|max:255',
            'business_location' => 'sometimes|nullable|string|max:255',
            'user_types_id' => 'sometimes|nullable|exists:user_types,id',
        ]);

        // Update user with validated data
        $user->update($validatedData);

        // Reload relations so translatable fields (like user_types.title) resolve in the current locale
        $user->loadMissing(['orders', 'credits', 'user_types.priceVariations']);

        return response()->json(['message' => 'Profile updated successfully.', 'user' => $user], 200);
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

        return response()->json(['message' => __('auth.password_changed')], 200);
    }
}
