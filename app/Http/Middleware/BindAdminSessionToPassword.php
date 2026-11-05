<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ends a CMS session once the admin's password has changed.
 *
 * The vendor's check compares reset_password_date with a cookie the client holds, so
 * leaving the cookie out skips it, /admin/profile issues a new one, and a change made
 * by another admin never sets the date. SessionGuard::login() already stores a hash of
 * the password in the server-side session; nothing compared it until this.
 *
 * Runs in the web group so it comes before the vendor `admin` middleware, which then
 * sees a logged-out guard and redirects to the login page. A session with no guard key
 * is not a logged-in session and is left alone.
 */
class BindAdminSessionToPassword
{
    public function handle(Request $request, Closure $next)
    {
        $guard = Auth::guard('admin');

        if (! $request->hasSession() || ! $request->session()->has($guard->getName())) {
            return $next($request);
        }

        $admin = $guard->user();
        if (! $admin) {
            return $next($request);
        }

        // The key SessionGuard::login() writes: its guard name, not getName(), which
        // is the session key of the logged-in id.
        $key = 'password_hash_admin';
        $stored = $request->session()->get($key);

        if (! is_string($stored) || ! hash_equals($guard->hashPasswordForCookie($admin->getAuthPassword()), $stored)) {
            $guard->logout();

            return $next($request);
        }

        $before = $admin->getAuthPassword();
        $response = $next($request);

        // An admin who changes their own password, on the profile page or the Admins
        // page, keeps this session. Every other session of theirs ends on its next request.
        if (! $request->isMethodSafe() && $guard->check()) {
            $current = $admin->fresh()?->getAuthPassword();
            if ($current !== null && $current !== $before) {
                $request->session()->put($key, $guard->hashPasswordForCookie($current));
            }
        }

        return $response;
    }
}
