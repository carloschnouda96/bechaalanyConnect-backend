<?php

include 'cms.php';

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

/*
|--------------------------------------------------------------------------
| Throttled CMS admin login
|--------------------------------------------------------------------------
|
| The hellotree package registers POST {prefix}/login with no rate limiting, and
| CmsController::login has no lockout of its own — so the admin panel accepted
| unlimited password guesses. The `web` middleware group also ships without any
| ThrottleRequests entry, so nothing upstream capped it either.
|
| Re-registering the same method+URI here replaces the vendor's route:
| RouteCollection::addToCollections() keys on method+domain+uri, and application
| routes are registered from RouteServiceProvider's deferred `booted` callback,
| which runs after CmsServiceProvider::boot() has loaded the package routes.
|
| This must NOT live in routes/cms.php. That file's group applies the `admin`
| middleware, which only short-circuits for the route *named* `admin-login` (the
| GET form). An unauthenticated POST would be bounced back to the login form and
| signing in would become impossible.
|
| `web` is already applied to this file by RouteServiceProvider, so only the
| throttle is added here.
*/
Route::prefix(config('hellotree.cms_route_prefix'))
    ->middleware('throttle:cms-login')
    ->post('login', 'Hellotreedigital\Cms\Controllers\CmsController@login');

Route::get('/', function () {
    return view('welcome');
});
