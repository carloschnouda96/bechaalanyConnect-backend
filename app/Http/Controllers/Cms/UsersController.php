<?php

namespace App\Http\Controllers\Cms;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Hellotreedigital\Cms\Controllers\CmsPageController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\NotificationController;
use App\Models\User;

class UsersController extends Controller
{
    /** @var CmsPageController */
    protected $cmsPageController;

    public function __construct(CmsPageController $cmsPageController)
    {
        $this->cmsPageController = $cmsPageController;
    }

    public function update(Request $request, $id)
    {
        $user = User::find($id);
        $previousStatus = $user ? $user->verification_statuses_id : null;

        $this->cmsPageController->update($request, $id, 'users');

        $newStatus = (int) $request->verification_statuses_id;

        // Notify the user when the admin approves or rejects their KYC documents
        if ($user && $previousStatus != $newStatus && in_array($newStatus, [User::VERIFICATION_APPROVED, User::VERIFICATION_REJECTED])) {
            $user->refresh();

            $view = $newStatus == User::VERIFICATION_APPROVED ? 'emails.kyc-approved' : 'emails.kyc-rejected';
            $subjectKey = $newStatus == User::VERIFICATION_APPROVED ? 'emails.subjects.kyc_approved' : 'emails.subjects.kyc_rejected';

            try {
                Mail::send($view, compact('user'), function ($message) use ($user, $subjectKey) {
                    $message->to($user->email)->subject(__($subjectKey));
                });
            } catch (\Exception $e) {
                Log::error('KYC status email failed: ' . $e->getMessage());
            }

            NotificationController::createKycNotification($user->id, $newStatus, $previousStatus);
        }

        return url(config('hellotree.cms_route_prefix') . '/users');
    }
}
