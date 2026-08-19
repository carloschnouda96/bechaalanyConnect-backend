<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Http\Controllers\NotificationController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * A dedicated review queue for identity verification.
 *
 * Two problems this solves.
 *
 * 1. There was no queue. KYC lived as three image fields buried in the general Users
 *    edit form, so finding who was waiting meant scrolling the whole user list and
 *    opening records one by one. Nothing showed how many were pending.
 *
 * 2. More urgently: the documents moved to the private disk (they are government ID
 *    photos and selfies that were previously served unauthenticated from
 *    public/storage/kyc/). Nothing in the CMS could render them any more, because the
 *    vendor's image field calls Storage::url() on the default disk. Without the
 *    document() action below, KYC review is impossible.
 */
class KycController extends Controller
{
    /** slot name => user column */
    private const SLOTS = [
        'id-front' => 'id_front_image',
        'id-back' => 'id_back_image',
        'selfie' => 'selfie_image',
    ];

    /** Pending first — that is the whole point of the page. */
    public function index(Request $request)
    {
        $status = $request->get('status', (string) User::VERIFICATION_PENDING);

        $users = User::query()
            ->when($status !== 'all', fn ($q) => $q->where('verification_statuses_id', (int) $status))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%' . addcslashes($request->get('q'), '%_\\') . '%';
                $q->where(fn ($inner) => $inner
                    ->where('username', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->orderByRaw('FIELD(verification_statuses_id, ?, ?, ?, ?)',
                [User::VERIFICATION_PENDING, User::VERIFICATION_REJECTED, User::VERIFICATION_UNSUBMITTED, User::VERIFICATION_APPROVED])
            ->orderBy('updated_at', 'desc')
            ->paginate(25)
            ->withQueryString();

        return view('cms::pages/kyc-queue/index', [
            'users' => $users,
            'status' => $status,
            'q' => $request->get('q'),
            'counts' => [
                User::VERIFICATION_PENDING => User::where('verification_statuses_id', User::VERIFICATION_PENDING)->count(),
                User::VERIFICATION_APPROVED => User::where('verification_statuses_id', User::VERIFICATION_APPROVED)->count(),
                User::VERIFICATION_REJECTED => User::where('verification_statuses_id', User::VERIFICATION_REJECTED)->count(),
                User::VERIFICATION_UNSUBMITTED => User::where('verification_statuses_id', User::VERIFICATION_UNSUBMITTED)->count(),
            ],
        ]);
    }

    /** Side-by-side view of the three documents for one applicant. */
    public function show($id)
    {
        $user = User::findOrFail($id);

        return view('cms::pages/kyc-queue/show', [
            'user' => $user,
            'slots' => self::SLOTS,
        ]);
    }

    /**
     * Stream one document from the PRIVATE disk.
     *
     * Authorisation is the `admin` middleware on the route group — these files are
     * never reachable without an admin session. The `public` fallback covers
     * installations where `php artisan uploads:privatize` has not been run yet.
     */
    public function document($id, string $slot)
    {
        abort_unless(isset(self::SLOTS[$slot]), 404);

        $user = User::findOrFail($id);
        $path = $user->{self::SLOTS[$slot]};

        abort_if(blank($path), 404, 'No document uploaded for this slot.');

        foreach (['private', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->response($path, null, [
                    // Identity documents must never be cached by an intermediary.
                    'Cache-Control' => 'private, no-store, max-age=0',
                ]);
            }
        }

        abort(404, 'Document file is missing from storage.');
    }

    /**
     * Approve or reject, with a reason.
     *
     * Deliberately shares the notification + email path with the Users page
     * (Cms\UsersController) rather than duplicating it, so an applicant gets exactly
     * the same message however the admin acted.
     */
    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'verification_statuses_id' => ['required', Rule::in([
                User::VERIFICATION_APPROVED,
                User::VERIFICATION_REJECTED,
                User::VERIFICATION_PENDING,
            ])],
            // Required when rejecting: a rejection with no reason is what made the
            // old email useless — the applicant just resubmitted the same documents.
            'rejection_reason' => [
                Rule::requiredIf(fn () => (int) $request->input('verification_statuses_id') === User::VERIFICATION_REJECTED),
                'nullable', 'string', 'max:2000',
            ],
        ], [
            'rejection_reason.required' => 'Tell the applicant what was wrong, so they know what to fix.',
        ]);

        $user = User::findOrFail($id);
        $previous = (int) $user->verification_statuses_id;
        $new = (int) $data['verification_statuses_id'];

        $user->verification_statuses_id = $new;
        $user->rejection_reason = $new === User::VERIFICATION_REJECTED
            ? $data['rejection_reason']
            : null;
        $user->save();

        if ($previous !== $new) {
            self::notifyDecision($user, $new, $previous);
        }

        return redirect(config('hellotree.cms_route_prefix') . '/kyc-queue')
            ->with('success', 'Verification updated for ' . $user->username . '.');
    }

    /**
     * Shared by this queue and Cms\UsersController so both produce identical
     * notifications. Extracted rather than copied precisely so the two cannot drift.
     */
    public static function notifyDecision(User $user, int $newStatus, ?int $previousStatus): void
    {
        if (!in_array($newStatus, [User::VERIFICATION_APPROVED, User::VERIFICATION_REJECTED], true)) {
            return;
        }

        $user->refresh();

        $view = $newStatus === User::VERIFICATION_APPROVED ? 'emails.kyc-approved' : 'emails.kyc-rejected';
        $subjectKey = $newStatus === User::VERIFICATION_APPROVED
            ? 'emails.subjects.kyc_approved'
            : 'emails.subjects.kyc_rejected';

        if (filled($user->email)) {
            try {
                Mail::send($view, ['user' => $user], function ($message) use ($user, $subjectKey) {
                    $message->to($user->email)->subject(__($subjectKey));
                });
            } catch (\Throwable $e) {
                // The decision is already saved; a mail failure must not undo it.
                Log::error('KYC status email failed', ['user_id' => $user->id, 'exception' => $e]);
            }
        }

        try {
            NotificationController::createKycNotification($user->id, $newStatus, $previousStatus);
        } catch (\Throwable $e) {
            Log::error('KYC notification failed', ['user_id' => $user->id, 'exception' => $e]);
        }
    }
}
