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

        // Delegated to Cms\KycController::notifyDecision rather than duplicated, so a
        // decision made here and a decision made in the KYC review queue send the
        // applicant exactly the same email and notification. This copy also swallowed
        // nothing on failure and did not carry the rejection reason.
        if ($user && $previousStatus != $newStatus) {
            KycController::notifyDecision($user, $newStatus, $previousStatus === null ? null : (int) $previousStatus);
        }

        return url(config('hellotree.cms_route_prefix') . '/users');
    }
}
