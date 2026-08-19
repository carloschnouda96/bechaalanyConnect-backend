<?php

namespace App\Http\Controllers;

use App\FixedSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

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

        // Stored on the PRIVATE disk. These are government ID photos and a live
        // selfie; on the `public` disk they sat under public/storage/kyc/ and were
        // served unauthenticated to anyone who had (or guessed, or found in a log)
        // the URL. They are now only reachable through an authorising controller.
        $previous = [$user->id_front_image, $user->id_back_image, $user->selfie_image];

        $user->id_front_image = $request->file('id_front')->store('kyc', 'private');
        $user->id_back_image = $request->file('id_back')->store('kyc', 'private');
        $user->selfie_image = $request->file('selfie')->store('kyc', 'private');
        $user->verification_statuses_id = User::VERIFICATION_PENDING;
        $user->save();

        // A rejected user resubmitting used to leave their previous three files
        // behind forever, so ID scans accumulated indefinitely with nothing pointing
        // at them. Only prune once the new paths are safely persisted.
        $this->deletePreviousDocuments($previous);

        $admin_email = FixedSetting::adminEmail();
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

    /**
     * Remove a previous submission's files. Tolerant of paths that were written to
     * the old `public` disk before the move, and of files that are already gone.
     *
     * @param  array<int, string|null>  $paths
     */
    private function deletePreviousDocuments(array $paths): void
    {
        foreach (array_filter($paths) as $path) {
            foreach (['private', 'public'] as $disk) {
                try {
                    if (Storage::disk($disk)->exists($path)) {
                        Storage::disk($disk)->delete($path);
                    }
                } catch (\Throwable $e) {
                    // Never let cleanup failure surface as a failed KYC submission —
                    // the documents are already saved and the user is already pending.
                    Log::warning('Could not delete superseded KYC document', [
                        'disk' => $disk,
                        'path' => $path,
                        'exception' => $e,
                    ]);
                }
            }
        }
    }
}
