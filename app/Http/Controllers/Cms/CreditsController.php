<?php

namespace App\Http\Controllers\Cms;

use App\CreditsTransfer;
use Illuminate\Http\Request;
use Hellotreedigital\Cms\Controllers\CmsPageController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\NotificationController;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class CreditsController extends Controller
{
    /** @var CmsPageController */
    protected $cmsPageController;

    public function __construct(CmsPageController $cmsPageController)
    {
        $this->cmsPageController = $cmsPageController;
    }

    public function update(Request $request, $id)
    {
        $requestedLocale = $request->get('lang') ?? $request->getPreferredLanguage(['en', 'ar']);
        if (in_array($requestedLocale, ['en', 'ar'])) {
            app()->setLocale($requestedLocale);
        }
        // Get the credits transfer before updating to check previous status
        $creditsTransfer = CreditsTransfer::find($id);
        $previousStatus = $creditsTransfer ? $creditsTransfer->statuses_id : null;

        $this->cmsPageController->update($request, $id, 'credits-transfer', 'App\CreditsTransfer', 'App\Http\Controllers\Cms\CreditsController');

        //Update User credits_balance and total_purchases if CreditsTransfer approved
        $this->updateUserCredits($id, $request->statuses_id, $previousStatus, $requestedLocale);

        // Create notification for status change
        $this->createStatusChangeNotification($id, $request->statuses_id, $previousStatus);

        // Redirect to the credits page
        return url(config('hellotree.cms_route_prefix') . '/credits-transfer');
    }

    private function updateUserCredits($creditsTransferId, $statusId, $previousStatus = null, $requestedLocale = null)
    {
        $creditsTransfer = CreditsTransfer::find($creditsTransferId);
        if (!$creditsTransfer) {
            return;
        }
        // Assuming: 1 = approved, 2 = pending, 3 = rejected
        $user = User::find($creditsTransfer->users_id);
        // Refund only if status changed from approved to pending or rejected
        if ($previousStatus == 1 && ($statusId == 2 || $statusId == 3)) {
            if ($user) {
                $user->credits_balance -= $creditsTransfer->amount;
                $user->received_amount -= $creditsTransfer->amount;
                $user->save();
            }
            return $creditsTransfer->amount;
        }
        // Deduct only if status changed to approved
        if ($statusId == 1 && $previousStatus != 1) {
            if ($user) {
                $user->credits_balance += $creditsTransfer->amount;
                $user->received_amount += $creditsTransfer->amount;
                $user->save();
            }

            Mail::send('emails.credits_approved', ['user' => $user, 'amount' => $creditsTransfer->amount], function ($message) use ($user) {
                $message->to($user->email);
                $message->subject('Your credits request has been approved');
            });
        }
    }

    /**
     * Create notification when credit status changes
     */
    private function createStatusChangeNotification($creditsTransferId, $newStatus, $previousStatus)
    {
        $creditsTransfer = CreditsTransfer::find($creditsTransferId);
        if (!$creditsTransfer) {
            return;
        }

        // Only create notification for significant status changes
        if ($previousStatus !== $newStatus && in_array($newStatus, [1, 3])) { // approved or rejected
            NotificationController::createCreditNotification(
                $creditsTransfer->users_id,
                $creditsTransferId,
                $newStatus,
                $previousStatus,
                $creditsTransfer->amount
            );
        }
    }
}
