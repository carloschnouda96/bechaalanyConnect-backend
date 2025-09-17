<?php

namespace App\Http\Controllers;

use App\CreditsTransfer;
use App\CreditsType;
use App\FixedSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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
        $admin_email = FixedSetting::first()->admin_email;

        //get Current User
        $user = $request->user();

        // Validate the request data
        $validatedData = $request->validate([
            'users_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:1',
            'receipt_image' => 'image|mimes:jpeg,png,jpg,gif,svg',
            'credits_types_id' => 'required|exists:credits_types,id',
            'statuses_id' => 'numeric'
        ]);

        $receiptImagePath = $request->file('receipt_image')->store('receipts', 'public');


        $transferRequest = new CreditsTransfer();
        $transferRequest->users_id = $validatedData['users_id'];
        $transferRequest->amount = $validatedData['amount'];
        $transferRequest->receipt_image = $receiptImagePath;
        $transferRequest->credits_types_id = $request->credits_types_id; // Assuming this is passed in the request
        $transferRequest->statuses_id = $validatedData['statuses_id'];
        $transferRequest->save();

        Mail::send('emails.admin-new-credit-transfer-request', compact('user', 'transferRequest'), function ($message) use ($user, $admin_email) {
            $message->to($admin_email)->subject('New Credit Transfer Request from ' . $user->username);
        });


        return response()->json([
            'message' => 'Transfer credit request submitted successfully.',
            'transfer_request' => $transferRequest,
        ]);
    }
}
