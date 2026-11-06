<?php

namespace App\Http\Middleware;

use Closure;
use Hellotreedigital\Cms\Controllers\ApisController;
use Illuminate\Http\Request;

/**
 * The vendor registers an unauthenticated POST /api/{page} for any CMS page flagged
 * apis = 1, and that action runs caller-chosen query builder calls. Nothing in this
 * product uses it, so the flag cannot turn it on: a checkbox is one save from an
 * anonymous SQL endpoint.
 */
class RefuseVendorCmsApi
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->route()?->getControllerClass() === ApisController::class) {
            abort(404);
        }

        return $next($request);
    }
}
