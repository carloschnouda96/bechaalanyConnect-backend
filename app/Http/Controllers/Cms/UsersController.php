<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Support\CmsDraftFlag;
use Hellotreedigital\Cms\Controllers\CmsPageController;
use Illuminate\Http\Request;

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
        CmsDraftFlag::strip($request);

        // A KYC decision is made in the review queue, which checks the documents the
        // reviewer saw are still the current ones. A form save here wrote back whatever
        // status it had loaded, so it could approve unseen documents or undo a review.
        $request->request->remove('verification_statuses_id');
        $request->request->remove('rejection_reason');
        $request->query->remove('verification_statuses_id');
        $request->query->remove('rejection_reason');

        $this->cmsPageController->update($request, $id, 'users');

        return url(config('hellotree.cms_route_prefix') . '/users');
    }
}
