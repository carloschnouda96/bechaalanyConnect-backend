<?php

namespace App\Http\Controllers;

use App\CreditsTransfer;
use App\CreditsType;
use App\FixedSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class CreditsController extends Controller
{
    //Get all credits types
    public function getCredits(Request $request)
    {
        $credits_types = CreditsType::orderBy('ht_pos')->get();

        return response()->json([
            'credits_types' => $credits_types,
        ]);
    }
    //Get user credits
    public function getUserCredits(Request $request)
    {
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 10);
        $credits = CreditsTransfer::where('users_id', auth()->id())
            ->with(['credits_types'])
            ->orderBy('created_at', 'desc')
            ->paginate($limit);

        return response()->json([
            'credits' => $credits->items(),
            'total' => $credits->total(),
            'current_page' => $credits->currentPage(),
            'per_page' => $credits->perPage(),
            'last_page' => $credits->lastPage()
        ]);
    }

    //Get single credit type by slug
    public function getSingleCreditType($locale, $slug)
    {
        $credit_type = CreditsType::where('slug', $slug)->firstOrFail();

        return response()->json([
            'credit_type' => $credit_type,
        ]);
    }

    //Handle transfer credit request
    public function transferCreditRequest(Request $request)
    {
        // Null-safe: FixedSetting::first()->admin_email fataled when the settings row
        // was missing or draft-flagged, blocking every credit request.
        $admin_email = FixedSetting::adminEmail();

        //get Current User
        $user = $request->user();

        if ($user->verification_status !== 'approved') {
            return response()->json(['message' => __('api.credits.kyc_required')], 403);
        }

        // Validate the request data
        $validatedData = $request->validate([
            'amount' => 'required|numeric|min:1',
            'receipt_image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'credits_types_id' => 'required|exists:credits_types,id',
        ]);

        // PRIVATE disk: a payment receipt is a bank/wallet screenshot carrying the
        // customer's name, account or phone number and transfer reference. On the
        // `public` disk these were served straight off public/storage/receipts/ with
        // no authentication. Reachable now only via the owner-scoped route below
        // (or the admin panel).
        $receiptImagePath = $request->file('receipt_image')->store('receipts', 'private');

        $transferRequest = new CreditsTransfer();
        $transferRequest->users_id = $user->id;
        $transferRequest->amount = $validatedData['amount'];
        $transferRequest->receipt_image = $receiptImagePath;
        $transferRequest->credits_types_id = $validatedData['credits_types_id'];
        $transferRequest->statuses_id = CreditsTransfer::STATUS_PENDING;
        $transferRequest->save();

        if ($admin_email) {
            try {
                Mail::send('emails.admin-new-credit-transfer-request', compact('user', 'transferRequest'), function ($message) use ($user, $admin_email) {
                    $message->to($admin_email)->subject(__('emails.subjects.new_credit_request') . ' - ' . $user->username);
                });
            } catch (\Throwable $e) {
                // The request is already saved and visible to the admin in the CMS;
                // a mail failure must not tell the user their top-up did not register.
                Log::error('Credit transfer admin notification failed', ['exception' => $e]);
            }
        }

        return response()->json([
            'message' => __('api.credits.request_submitted'),
            'transfer_request' => $transferRequest,
        ]);
    }

    /**
     * Stream a payment receipt.
     *
     * Reached through a temporary signed URL (see routes/api.php `credits.receipt`),
     * because the storefront renders receipts in an <img> tag which cannot send an
     * Authorization header. Authorisation therefore lives in the signature: a URL is
     * only ever minted while serialising a transfer through an owner-scoped query
     * (CreditsController::getUserCredits filters on users_id), and the `signed`
     * middleware rejects any URL that was altered or has expired.
     */
    public function receipt(Request $request, $id)
    {
        $transfer = CreditsTransfer::find($id);

        if (!$transfer || blank($transfer->receipt_image)) {
            return response()->json(['message' => __('api.credits.receipt_not_found'), 'code' => 'not_found'], 404);
        }

        foreach (['private', 'public'] as $disk) {
            // `public` is checked as a fallback so receipts uploaded before the move
            // keep working until the migrate command has run.
            if (Storage::disk($disk)->exists($transfer->receipt_image)) {
                return Storage::disk($disk)->response(
                    $transfer->receipt_image,
                    null,
                    ['Cache-Control' => 'private, max-age=0, no-store']
                );
            }
        }

        return response()->json(['message' => __('api.credits.receipt_not_found'), 'code' => 'not_found'], 404);
    }
}
