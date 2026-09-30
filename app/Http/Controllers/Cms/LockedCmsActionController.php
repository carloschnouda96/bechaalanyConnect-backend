<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Hard stop for vendor CRUD actions that must never run on certain CMS pages.
 *
 * `cms_pages.add` / `.delete` only hide the buttons, and only for role admins — a super
 * admin still sees them (faded) and the vendor route still executes. These routes are
 * registered in routes/cms.php over the vendor's identical URIs, which is what makes the
 * refusal real for everyone:
 *
 * - Orders cannot be created in the CMS: an order made there debits no credits and has
 *   no ledger entry, so approving it would hand out a product for free.
 * - Statuses and Product types cannot be created or deleted: the code hardcodes their ids
 *   (Order::STATUS_* 1/2/3, product_type_id 1–4). Renaming a title is still allowed.
 */
class LockedCmsActionController extends Controller
{
    private const REASONS = [
        'orders' => 'Orders are placed by customers from the storefront, which debits their credits. An order created here would have no payment behind it.',
        'statuses' => 'Statuses are system data — the code relies on their ids (1 Approved, 2 Rejected, 3 Pending). You can rename them, but not add or delete.',
        'product-type' => 'Product types are system data — the storefront purchase form is chosen by their ids. You can rename them, but not add or delete.',
    ];

    // Read from the route rather than declared as a parameter: controller arguments bind
    // positionally, and on DELETE /{page}/{id} the first one would be the id.
    public function refuse(Request $request)
    {
        abort(403, self::REASONS[$request->route('page')] ?? 'This action is disabled for this page.');
    }
}
