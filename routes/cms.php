<?php

/*
|--------------------------------------------------------------------------
| CMS generated routes for signed in admins
|--------------------------------------------------------------------------
*/

Route::prefix(config('hellotree.cms_route_prefix'))->middleware(['admin'])->group(function () {

    /* Start admin route group */

    /*
     | Overrides the vendor's page-schema editor so App\Services\Cms\SchemaGuard runs
     | after every save. editDatabase() re-asserts column types from an argument-less
     | Blueprint call and forces every column NULLable, which silently narrows
     | decimal(20,8) supplier costs to decimal(8,2) and drops NOT NULL.
     |
     | Same method+URI as the vendor route; the later registration replaces it.
     */
    Route::put('/cms-pages/{id}', 'App\Http\Controllers\Cms\CmsPagesController@update');
    Route::post('/cms-pages', 'App\Http\Controllers\Cms\CmsPagesController@store');

    /*
     | Bulk approve / reject.
     |
     | MUST be registered before the `{id}` routes below: Laravel matches in
     | registration order, so `{id}` would otherwise swallow `bulk-status` and the
     | request would arrive at update() with $id = 'bulk-status'. This is the same
     | class of bug that made DELETE /user/notifications/delete-read unreachable.
     |
     | PUT, not POST: AdminMiddleware maps POST to the `add` permission and PUT to
     | `edit`, so POST would demand create rights to change a status.
     */
    /*
     | KYC review queue. A custom page (cms_pages.custom_page = 1), so the vendor's
     | generic CRUD routes are not generated for it.
     |
     | The `document` route is what makes KYC reviewable at all now that the ID photos
     | and selfies live on the private disk — the vendor's image field calls
     | Storage::url() on the default disk and cannot reach them.
     */
    Route::get('/kyc-queue', 'App\Http\Controllers\Cms\KycController@index');
    Route::get('/kyc-queue/{id}', 'App\Http\Controllers\Cms\KycController@show')->whereNumber('id');
    Route::get('/kyc-queue/{id}/document/{slot}', 'App\Http\Controllers\Cms\KycController@document')->whereNumber('id');
    Route::put('/kyc-queue/{id}', 'App\Http\Controllers\Cms\KycController@update')->whereNumber('id');

    // Supplier balances, plus a retry for orders that were charged and approved but
    // never reached the supplier. PUT so AdminMiddleware maps it to `edit` rights.
    Route::get('/supplier-health', 'App\Http\Controllers\Cms\SupplierHealthController@index');
    Route::put('/supplier-health/retry/{id}', 'App\Http\Controllers\Cms\SupplierHealthController@retry')->whereNumber('id');
    // Bycel gives no order id on purchase, so an admin sometimes has to pick which
    // report row belongs to an order. PUT (not POST): AdminMiddleware maps POST to
    // the `add` permission and PUT to `edit`.
    Route::put('/supplier-health/bycel/{purchase}/claim/{merchantPurchaseId}', 'App\Http\Controllers\Cms\SupplierHealthController@claimBycelPin')
        ->whereNumber('purchase')->whereNumber('merchantPurchaseId');
    Route::put('/supplier-health/bycel/{purchase}/abandon', 'App\Http\Controllers\Cms\SupplierHealthController@abandonBycelPurchase')
        ->whereNumber('purchase');

    Route::put('/orders/bulk-status', 'App\Http\Controllers\Cms\OrdersController@bulkStatus');
    Route::put('/credits-transfer/bulk-status', 'App\Http\Controllers\Cms\CreditsController@bulkStatus');

    /*
     | Records the CMS deleted but did not remove.
     |
     | orders, credits_transfer and users soft-delete (2026_08_10_000009), and the vendor
     | strips only the `cms_draft_flag` scope from its queries — never the SoftDeletingScope
     | — so a deleted row is invisible everywhere else while still holding every RESTRICT
     | foreign key pointed at it.
     |
     | PUT for restore and DELETE for purge, not POST: AdminMiddleware maps POST to the
     | `add` permission, PUT to `edit` and DELETE to `delete`.
     |
     | `{type}` is matched against a hardcoded whitelist in the controller; the `where`
     | here keeps a bad value from ever reaching it.
     */
    Route::get('/deleted-records', 'App\Http\Controllers\Cms\TrashController@index');
    Route::put('/deleted-records/{type}/{id}/restore', 'App\Http\Controllers\Cms\TrashController@restore')
        ->where('type', 'orders|credits-transfer|users')->whereNumber('id');
    Route::delete('/deleted-records/{type}/{id}', 'App\Http\Controllers\Cms\TrashController@purge')
        ->where('type', 'orders|credits-transfer|users')->whereNumber('id');

    /*
     | Guarded catalog deletes.
     |
     | Same method+URI as the vendor's generated delete routes, so the later registration
     | replaces them (routes/web.php documents the mechanism). `{id}` is deliberately NOT
     | whereNumber()'d: the vendor's bulk-delete JS posts a comma-separated list of ids.
     |
     | Without this, deleting a product or a variation that any order — including an
     | invisible soft-deleted one — points at fails with a raw SQLSTATE 1451.
     */
    Route::delete('/products/{id}', 'App\Http\Controllers\Cms\CatalogDeleteController@destroyProduct');
    Route::delete('/products-variations/{id}', 'App\Http\Controllers\Cms\CatalogDeleteController@destroyVariation');

    /*
     | Bulk catalog editing: export to CSV, edit in a spreadsheet, upload, review, apply.
     |
     | POST (not PUT) for both write steps: AdminMiddleware maps POST to the `add`
     | permission, and an import can create products, so `add` is the honest right to
     | require. `/preview` is registered before the bare POST so the two do not overlap.
     */
    Route::get('/catalog-pricing', 'App\Http\Controllers\Cms\CatalogPricingController@index');
    // PUT so AdminMiddleware maps it to `edit`: repricing changes existing records.
    Route::put('/catalog-pricing', 'App\Http\Controllers\Cms\CatalogPricingController@update');

    Route::get('/catalog-import', 'App\Http\Controllers\Cms\CatalogImportController@index');
    Route::get('/catalog-import/export', 'App\Http\Controllers\Cms\CatalogImportController@export');
    Route::post('/catalog-import/preview', 'App\Http\Controllers\Cms\CatalogImportController@preview');
    Route::post('/catalog-import', 'App\Http\Controllers\Cms\CatalogImportController@commit');

    /*
     | Streams a top-up receipt off the private disk.
     |
     | The same fix, for the same reason, as /kyc-queue/{id}/document/{slot} above: the
     | vendor's `image` field calls Storage::url() on the default (public) disk and
     | cannot reach a private file, so without this route every receipt in the CMS is a
     | broken image — on the only screen where a top-up is approved.
     |
     | Three segments, so it cannot collide with the vendor's GET /credits-transfer/{id}.
     */
    Route::get('/credits-transfer/{id}/receipt', 'App\Http\Controllers\Cms\CreditsController@receipt')
        ->whereNumber('id');

    Route::put('/orders/{id}', 'App\Http\Controllers\Cms\OrdersController@update')->whereNumber('id');
    Route::put('/credits-transfer/{id}', 'App\Http\Controllers\Cms\CreditsController@update')->whereNumber('id');
    Route::put('/users/{id}', 'App\Http\Controllers\Cms\UsersController@update')->whereNumber('id');


	/* End admin route group */

});
