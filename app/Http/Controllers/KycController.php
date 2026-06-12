<?php

namespace App\Http\Controllers;

use App\FixedSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class KycController extends Controller
{
    /**
     * Receive the user's identity documents (ID front/back + selfie),
     * mark the account as pending verification and notify the admin.
     */
    public function submit(Request $request)
    {
        $requestedLocale = app()->getLocale();

        /** @var User $user */
        $user = $request->user();

        if (in_array($user->verification_statuses_id, [User::VERIFICATION_PENDING, User::VERIFICATION_APPROVED])) {
            return response()->json(['message' => 'Your verification documents have already been submitted.'], 409);
        }

        $request->validate([
            'id_front' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
            'id_back' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
            'selfie' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $user->id_front_image = $request->file('id_front')->store('kyc', 'public');
        $user->id_back_image = $request->file('id_back')->store('kyc', 'public');
        $user->selfie_image = $request->file('selfie')->store('kyc', 'public');
        $user->verification_statuses_id = User::VERIFICATION_PENDING;
        $user->save();

        $admin_email = FixedSetting::first()->admin_email ?? null;
        if ($admin_email) {
            try {
                Mail::send('emails.kyc-submitted', compact('user', 'requestedLocale'), function ($message) use ($admin_email, $user) {
                    $message->to($admin_email)->subject(__('emails.subjects.kyc_submitted') . ' - ' . $user->username);
                });
            } catch (\Exception $e) {
                Log::error('KYC admin email failed: ' . $e->getMessage());
            }
        }

        return response()->json([
            'message' => 'Verification documents submitted successfully. Your account is pending approval.',
            'verification_status' => $user->verification_status,
        ], 200);
    }
}
