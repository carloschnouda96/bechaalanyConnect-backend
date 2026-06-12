<?php

namespace App\Http\Controllers\Cms;

use App\CreditsTransfer;
use Illuminate\Http\Request;
use Hellotreedigital\Cms\Controllers\CmsPageController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\NotificationController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        if ($previousStatus == $statusId) {
            return;
        }

        $result = DB::transaction(function () use ($creditsTransferId, $statusId, $previousStatus) {
            $creditsTransfer = CreditsTransfer::find($creditsTransferId);
            if (!$creditsTransfer) {
                return null;
            }
            // Statuses: 1 = approved, 2 = rejected, 3 = pending
            $user = User::where('id', $creditsTransfer->users_id)->lockForUpdate()->first();
            if (!$user) {
                return null;
            }
            // Remove previously credited amount if status changed away from approved
            if ($previousStatus == CreditsTransfer::STATUS_APPROVED && $statusId != CreditsTransfer::STATUS_APPROVED) {
                $user->credits_balance -= $creditsTransfer->amount;
                $user->received_amount -= $creditsTransfer->amount;
                $user->save();
                return ['action' => 'reverted', 'transfer' => $creditsTransfer, 'user' => $user];
            }
            // Credit the amount if status changed to approved
            if ($statusId == CreditsTransfer::STATUS_APPROVED && $previousStatus != CreditsTransfer::STATUS_APPROVED) {
                $user->credits_balance += $creditsTransfer->amount;
                $user->received_amount += $creditsTransfer->amount;
                $user->save();
                return ['action' => 'approved', 'transfer' => $creditsTransfer, 'user' => $user];
            }
            return null;
        });

        if ($result && $result['action'] === 'approved') {
            $user = $result['user'];
            Mail::send('emails.credits_approved', ['user' => $user, 'amount' => $result['transfer']->amount], function ($message) use ($user) {
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
        if ($previousStatus !== $newStatus && in_array($newStatus, [CreditsTransfer::STATUS_APPROVED, CreditsTransfer::STATUS_REJECTED])) {
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
