<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Sets the app locale for routes that are NOT under the {locale} prefix.
 *
 * A meaningful slice of the API lives outside that prefix — /register,
 * /verify-email, /resend-verification-code, /contact-form-submit, /auth/google-sync
 * and every notification mutation — so LocaleMiddleware never runs on them and
 * Laravel fell back to config('app.locale') = 'en'. An Arabic customer got English
 * validation errors, English success messages and English transactional email.
 *
 * SessionController, ContactController and RegisteredUserController each carried
 * their own copy of this logic inline (five copies of the same four lines). This is
 * that logic, once.
 *
 * Order of preference matches the copies it replaces: an explicit ?lang (or `lang`
 * field) wins, then the browser/client Accept-Language. Anything unrecognised leaves
 * the default in place rather than 404ing — unlike LocaleMiddleware, which is
 * strict because the locale is part of the URL there.
 */
class ResolveRequestLocale
{
    /** The locales the app actually ships translations for. */
    public const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next)
    {
        $requested = $request->get('lang') ?: $request->getPreferredLanguage(self::SUPPORTED);

        if (in_array($requested, self::SUPPORTED, true)) {
            App::setLocale($requested);
        }

        return $next($request);
    }
}
