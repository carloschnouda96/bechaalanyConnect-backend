<?php

namespace App\Http\Controllers\Cms;

use App\Services\Cms\SchemaGuard;
use Hellotreedigital\Cms\Controllers\CmsPagesController as VendorCmsPagesController;
use Illuminate\Http\Request;

/**
 * Wraps the vendor CMS page-schema editor so a save cannot leave the database in a
 * shape the application does not expect.
 *
 * The vendor's editDatabase() re-asserts every registered column's type from an
 * argument-less Blueprint call and forces every column NULLable. That silently
 * turns `decimal(20,8)` into `decimal(8,2)` (rounding sub-cent supplier costs to
 * zero) and drops NOT NULL. Nothing about that is configurable from the CMS UI, so
 * the fix is to let the vendor do its work and then put the truth back.
 *
 * Registered in routes/cms.php with the same method+URI as the vendor route.
 * RouteCollection::addToCollections() keys on method+domain+uri and application
 * routes register from RouteServiceProvider's deferred `booted` callback — after
 * CmsServiceProvider::boot() has loaded the package routes — so this one wins.
 * Same mechanism the existing PUT /admin/orders/{id} override already relies on.
 */
class CmsPagesController extends VendorCmsPagesController
{
    public function __construct(private SchemaGuard $guard)
    {
    }

    public function update(Request $request, $id)
    {
        $response = parent::update($request, $id);

        $this->repair($request->input('database_table'));

        return $response;
    }

    public function store(Request $request)
    {
        $response = parent::store($request);

        $this->repair($request->input('database_table'));

        return $response;
    }

    /**
     * Repairing must never break the admin's save.
     *
     * By the time this runs the cms_pages row and the table have already been
     * written, so throwing here would show a 500 for work that actually succeeded
     * and would leave the admin unsure what state anything is in. Failures are
     * logged by the guard itself; `php artisan cms:repair-schema --check` is the
     * out-of-band way to notice.
     */
    private function repair(?string $table): void
    {
        if (!$table) {
            return;
        }

        try {
            $this->guard->repair($table);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
