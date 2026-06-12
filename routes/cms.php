<?php

/*
|--------------------------------------------------------------------------
| CMS generated routes for signed in admins
|--------------------------------------------------------------------------
*/

Route::prefix(config('hellotree.cms_route_prefix'))->middleware(['admin'])->group(function () {

    /* Start admin route group */

    Route::put('/orders/{id}', 'App\Http\Controllers\Cms\OrdersController@update');
    Route::put('/credits-transfer/{id}', 'App\Http\Controllers\Cms\CreditsController@update');
    Route::put('/users/{id}', 'App\Http\Controllers\Cms\UsersController@update');


	/* End admin route group */

});
