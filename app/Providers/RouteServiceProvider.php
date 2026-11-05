<?php

namespace App\Providers;

use App\Support\ClientIp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Baseline for every /api route.
        //
        // NOTE: ThrottleRequests runs *before* auth:sanctum, so $request->user() is
        // resolved against the default `web` (session) guard and is always null for
        // bearer-token API calls. Every caller therefore falls back to the IP bucket
        // here. That is fine as a blanket ceiling, but it is why the sensitive
        // endpoints below get their own explicit, correctly-keyed limiters instead of
        // relying on this one.
        RateLimiter::for('api', function (Request $request) {
            // The reseller API has its own per-key limiters below. Several of a
            // reseller's customers share one server IP, so the IP bucket would
            // throttle an integration long before its key limit.
            if ($request->is('api/v1/*')) {
                return Limit::none();
            }

            return Limit::perMinute(60)->by($request->user()?->id ?: ClientIp::of($request));
        });

        // Reseller API, keyed by the API key itself (hashed — never the raw key)
        // so the limit does not depend on middleware order resolving the user.
        RateLimiter::for('reseller', function (Request $request) {
            return Limit::perMinute(120)->by('reseller:' . hash('sha256', (string) $request->bearerToken()));
        });

        RateLimiter::for('reseller-write', function (Request $request) {
            return Limit::perMinute(30)->by('reseller-write:' . hash('sha256', (string) $request->bearerToken()));
        });

        // Credential endpoints. The IP bucket is the real limit. The email ceiling is
        // only a backstop against a botnet converging on one account: at 5 a minute it
        // let any anonymous caller lock an owner out of login and recovery with 5
        // requests, so it is deliberately high enough to need many IPs.
        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return array_filter([
                Limit::perMinute(5)->by('auth-ip:' . ClientIp::of($request)),
                $email !== '' ? Limit::perMinute(30)->by('auth-email:' . sha1($email)) : null,
            ]);
        });

        // Routes that send an email per request: /register, /forgot-password and
        // /resend-verification-code. A separate name, so they cannot spend the login
        // allowance, and a low email cap, because this one bounds mail to one inbox.
        RateLimiter::for('auth-mail', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return array_filter([
                Limit::perMinute(5)->by('auth-mail-ip:' . ClientIp::of($request)),
                $email !== '' ? Limit::perMinute(3)->by('auth-mail-email:' . sha1($email)) : null,
            ]);
        });

        // Authenticated state-changing endpoints (place order, request credits).
        // Safe to key on the user id: these all sit behind auth:sanctum, and by the
        // time the limiter's callback runs for a matched route the user is resolved.
        RateLimiter::for('write', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // File uploads (KYC documents, payment receipts) — each accepts up to 5 MB
        // per file and triggers an admin email.
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        // Unauthenticated public form that sends mail to the admin address.
        RateLimiter::for('contact', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        // The supplier cron entrypoints run a full multi-supplier sync in-process
        // with set_time_limit(0). Even with a valid token, this bounds the damage
        // from a leaked token or a misconfigured cron hitting it in a loop.
        RateLimiter::for('cron', function (Request $request) {
            return Limit::perMinute(4)->by($request->ip());
        });

        // CMS admin login. The `web` middleware group ships with no throttling at
        // all, so POST /admin/login was completely unlimited with no lockout.
        RateLimiter::for('cms-login', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return array_filter([
                Limit::perMinute(5)->by('cms-ip:' . $request->ip()),
                // Per IP, so guessing from one address cannot lock the admin out from
                // another. The email-only ceiling stays as a backstop against many IPs.
                $email !== '' ? Limit::perMinutes(15, 10)->by('cms-email-ip:' . sha1($email) . '|' . $request->ip()) : null,
                $email !== '' ? Limit::perMinutes(15, 50)->by('cms-email:' . sha1($email)) : null,
            ]);
        });

        // Generous ceiling for the CMS/web group as a whole, so a single client
        // cannot saturate PHP-FPM workers through the admin panel.
        RateLimiter::for('web', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}
