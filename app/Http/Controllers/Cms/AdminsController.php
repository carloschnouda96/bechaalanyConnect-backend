<?php

namespace App\Http\Controllers\Cms;

use Hellotreedigital\Cms\Controllers\AdminsController as VendorAdminsController;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Keeps super admins out of reach of role admins.
 *
 * The vendor hides them from the list (whereNotNull('admin_role_id')) and nowhere else, so a role
 * with rights on the Admins page could open, overwrite or delete one by id. Saving one also forces
 * a role onto it, which demoted it on the spot.
 */
class AdminsController extends VendorAdminsController
{
    public function show($id)
    {
        $this->refuseIfSuperAdmin($id);

        return parent::show($id);
    }

    public function edit($id)
    {
        $this->refuseIfSuperAdmin($id);

        return parent::edit($id);
    }

    public function update(Request $request, $id)
    {
        $this->refuseIfSuperAdmin($id);

        return parent::update($request, $id);
    }

    public function destroy($id)
    {
        $this->refuseIfSuperAdmin($id);

        return parent::destroy($id);
    }

    /** $id can be a comma-separated list: the vendor's bulk delete uses the same route. */
    private function refuseIfSuperAdmin($id): void
    {
        if (Auth::guard('admin')->user()->admin_role_id === null) {
            return;
        }

        if (Admin::whereIn('id', explode(',', (string) $id))->whereNull('admin_role_id')->exists()) {
            abort(403, 'Only a super admin can change a super admin.');
        }
    }
}
