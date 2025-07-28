<?php

namespace App\Http\Controllers\auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class RegisteredUserController extends Controller
{
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
            'username' => ['required', 'string', 'min:3'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(6)],
        ]);

        $request['verification_token'] = $email_confirmation_token;
        $request['email_verified'] = 0;

        $user = User::create([
            'username' => $request['username'],
            'email' => $request['email'],
            'password' => bcrypt($request['password']),
            'country' => $request['country'],
            'phone_number' => $request['phone'],
            'verification_token' => $email_confirmation_token,
            'account_verification_code' => $account_verification_code,
            'email_verified' => 0,
            'is_business_user' => $request['isBusiness'] ?? 0,
            'business_name' => $request['storeName'] ?? null,
            'business_location' => $request['location'] ?? null,
            'user_types_id' => $request['userType'] ?? null,
            'credits_balance' => 0,
        ]);
        $confirm_email_url = env('APP_FRONT_URL') . '/email-verification/' . $user->email . '/' . $email_confirmation_token;
        try {
            Mail::send('emails.verify-email', compact('account_verification_code', 'request'), function ($message) use ($request) {
                $message->to($request['email'])->subject('Email Confirmation');
            });
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to send verification email.'], 500);
        }
        return response()->json([
            'message' => 'Registration successful. Please verify your email.',
            'verification_token' => $email_confirmation_token
        ], 201);
    }

    public function verifyEmail()
    {
        $attributes = request()->all();
        $email = $attributes['email'];
        $token = $attributes['token'];
        $code = $attributes['code'];
        $user = \App\Models\User::where('email', $email)->first();
        if ($user && ($user->verification_token == $token) && ($user->account_verification_code == $code)) {
            $user->update([
                'email_verified' => 1,
                'verification_token' => null,
                'account_verification_code' => null
            ]);
            $token = $user->createToken('api-token')->plainTextToken;
            return response()->json(['message' => 'Email verified successfully.', 'token' => $token, 'user' => $user], 200);
        }
        return response()->json(['error' => 'Email verification failed.'], 400);
    }

    public function verifyEmailSendNewCode(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255']
        ]);
        $user = User::where('email', $request['email'])->first();
        if ($user && $user->account_verification_code !== null) {
            return response()->json(['error' => 'You already have a verification code. Please check your email.'], 404);
        } else {
            $account_verification_code = random_int(100000, 999999);
            $user->account_verification_code = $account_verification_code;
            $user->save();
            try {
                Mail::send('emails.verify-email', compact('account_verification_code', 'request'), function ($message) use ($request) {
                    $message->to($request['email'])->subject('Email Confirmation');
                });
            } catch (\Exception $e) {
            }
            return response()->json([
                'message' => 'New verification code sent.',
                'verification_token' => $user->verification_token,
            ], 200);
        }
    }

    public function forgotPassword()
    {
        return response()->json(['message' => 'Forgot password endpoint. Use POST to send email.'], 200);
    }

    public function forgotPasswordSendEmail()
    {
        $attributes = request()->validate([
            'email' => ['required', 'email', 'max:255']
        ]);
        $user = User::where('email', $attributes['email'])->first();
        if ($user) {
            $password_reset_token = bin2hex(random_bytes(16));
            $user->password_reset_token = $password_reset_token;
            $user->save();
            $reset_password_url = env('APP_URL') . '/reset-password/' . $attributes['email'] . '/' . $password_reset_token;
            try {
                Mail::send('emails.forgot-password', compact('reset_password_url', 'attributes'), function ($message) use ($attributes) {
                    $message->to($attributes['email'])->subject('Reset Password');
                });
            } catch (\Exception $e) {
                return response()->json(['error' => 'Failed to send reset email.'], 500);
            }
            return response()->json(['message' => 'Reset password email sent.'], 200);
        } else {
            return response()->json(['error' => 'This Email is not registered.'], 404);
        }
    }

    public function resetPassword($email, $token)
    {
        $user = User::where('email', $email)->first();
        if ($user && ($user->password_reset_token == $token)) {
            return response()->json(['message' => 'Token valid. You can reset your password.', 'email' => $email, 'token' => $token], 200);
        }
        return response()->json(['error' => 'Password reset failed.'], 400);
    }

    public function resetPasswordSendEmail()
    {
        $attributes = request()->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(6)]
        ]);
        $user = User::where('email', $attributes['email'])->first();
        if ($user) {
            $user->update([
                'password' => bcrypt($attributes['password']),
                'password_reset_token' => null
            ]);
            $token = $user->createToken('api-token')->plainTextToken;
            return response()->json(['message' => 'Password reset successfully.', 'token' => $token, 'user' => $user], 200);
        }
        return response()->json(['error' => 'Password reset failed.'], 400);
    }
}
