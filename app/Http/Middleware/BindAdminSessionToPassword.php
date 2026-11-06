<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

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

        $response = $next($request);

        // An admin who changes their own password, on the profile page or the Admins
        // page, keeps this session. Every other session of theirs ends on its next request.
        // Only that request may adopt the new hash: comparing before and after let any
        // request in flight during someone else's change keep a session it should lose.
        if ($this->isOwnPasswordChange($request, $admin) && $guard->check()) {
            $current = $admin->fresh()?->getAuthPassword();
            // The stored hash must be of the password this request sent. A request that failed
            // validation, or was refused, could otherwise adopt a change made elsewhere.
            if ($current !== null && Hash::check((string) $request->input('password'), $current)) {
                $request->session()->put($key, $guard->hashPasswordForCookie($current));
                // A new id, so a copy of this cookie does not stay logged in after the change.
                $request->session()->migrate(true);
            }
        }

        return $response;
    }

    private function isOwnPasswordChange(Request $request, $admin): bool
    {
        if ($request->isMethodSafe() || ! $request->filled('password')) {
            return false;
        }

        $prefix = trim((string) config('hellotree.cms_route_prefix'), '/');

        return $request->path() === $prefix . '/profile/edit'
            || $request->path() === $prefix . '/admins/' . $admin->getAuthIdentifier();
    }
}
