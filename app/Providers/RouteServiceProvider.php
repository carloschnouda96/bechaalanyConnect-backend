<?php

namespace App\Providers;

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
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Credential and account-recovery endpoints. Keyed by IP *and* by the
        // targeted email, so one attacker cannot spread a password-spray across
        // many accounts from one IP, and a botnet cannot converge on one account
        // from many IPs. Also caps outbound mail: /register, /forgot-password and
        // /resend-verification-code each send an email per request.
        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return array_filter([
                Limit::perMinute(5)->by('auth-ip:' . $request->ip()),
                $email !== '' ? Limit::perMinute(5)->by('auth-email:' . $email) : null,
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
                $email !== '' ? Limit::perMinutes(15, 10)->by('cms-email:' . $email) : null,
            ]);
        });

        // Generous ceiling for the CMS/web group as a whole, so a single client
        // cannot saturate PHP-FPM workers through the admin panel.
        RateLimiter::for('web', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}
