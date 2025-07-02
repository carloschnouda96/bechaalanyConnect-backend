<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            return response()->json(['error' => 'Your Email or Password is incorrect.'], 401);
        }
        $user = Auth::user();
        if (! $user->email_verified) {
            return response()->json(['error' => 'Please verify your email.'], 403);
        }
        $token = $user->createToken('api-token')->plainTextToken;
        return response()->json(['token' => $token, 'user' => $user], 200);
    }

    public function destroy()
    {
        $user = Auth::user();
        if ($user) {
            $user->tokens()->delete(); // deletes all tokens for the user
        }
        Auth::logout();
        return response()->json(['message' => 'Logged out successfully.'], 200);
    }
}
