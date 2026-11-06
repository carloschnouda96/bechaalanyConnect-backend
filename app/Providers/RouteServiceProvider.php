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

        // Otherwise /api/v1/credit-types?locale=en matches the {locale} group with "v1" as
        // the locale, and escapes the per-IP limit that is waived for the reseller API.
        Route::pattern('locale', 'en|ar');

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /** Per account, once api.key has resolved it: five keys must not mean five budgets. */
    /**
     * The admin this spelling reaches. admins.email is utf8mb4_unicode_ci, so accented
     * spellings find the same row, and each must not get its own budget.
     */
    private static function adminLoginIdentity(string $email): string
    {
        $id = \Hellotreedigital\Cms\Models\Admin::where('email', $email)->value('id');

        return $id ? 'admin:' . $id : sha1(strtolower(trim($email)));
    }

    private function resellerKey(Request $request): string
    {
        return $request->user()?->id ? 'user:' . $request->user()->id : hash('sha256', (string) $request->bearerToken());
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
            // The ceiling stays high for the same reason, but bounds failed keys from one IP.
            if ($request->is('api/v1/*')) {
                return Limit::perMinute(600)->by('v1-ip:' . ClientIp::network((string) $request->ip()));
            }

            return Limit::perMinute(60)->by($request->user()?->id ?: ClientIp::of($request));
        });

        // Reseller API, keyed by the API key itself (hashed — never the raw key)
        // so the limit does not depend on middleware order resolving the user.
        RateLimiter::for('reseller', function (Request $request) {
            return Limit::perMinute(120)->by('reseller:' . $this->resellerKey($request));
        });

        RateLimiter::for('reseller-write', function (Request $request) {
            // The hourly figure bounds what one account can spend of a shared supplier quota.
            return [
                Limit::perMinute(30)->by('reseller-write:' . $this->resellerKey($request)),
                Limit::perHour(600)->by('reseller-write-hour:' . $this->resellerKey($request)),
            ];
        });

        // Credential endpoints.
        RateLimiter::for('auth', function (Request $request) {
            // No email-only bucket: a stranger on another address could spend it and lock
            // the owner out of login and recovery. SessionController counts failures per
            // account and source, and alerts staff on many sources.
            return [
                Limit::perMinute(5)->by('auth-ip:' . ClientIp::of($request)),
                Limit::perHour(60)->by('auth-ip-hour:' . ClientIp::of($request)),
            ];
        });

        // Routes that send an email per request: /register, /forgot-password and
        // /resend-verification-code. Mail to one inbox is bounded where the send happens
        // (the reset and verification cooldowns), so a refusal here can never stop an
        // owner's own recovery. RegisteredUserController caps sign-up mail across addresses.
        RateLimiter::for('auth-mail', function (Request $request) {
            return [
                Limit::perMinute(5)->by('auth-mail-ip:' . ClientIp::of($request)),
                Limit::perHour(30)->by('auth-mail-ip-hour:' . ClientIp::of($request)),
            ];
        });

        // Authenticated state-changing endpoints (place order, request credits).
        // Safe to key on the user id: these all sit behind auth:sanctum, and by the
        // time the limiter's callback runs for a matched route the user is resolved.
        RateLimiter::for('write', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: ClientIp::network((string) $request->ip()));
        });

        // File uploads (KYC documents, payment receipts) — each accepts up to 5 MB
        // per file and triggers an admin email.
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: ClientIp::network((string) $request->ip()));
        });

        // Unauthenticated public form that sends mail to the admin address.
        RateLimiter::for('contact', function (Request $request) {
            return [
                Limit::perMinute(3)->by(ClientIp::network((string) $request->ip())),
                Limit::perDay(30)->by('contact-day:' . ClientIp::network((string) $request->ip())),
            ];
        });

        // The supplier cron entrypoints run a full multi-supplier sync in-process
        // with set_time_limit(0). Even with a valid token, this bounds the damage
        // from a leaked token or a misconfigured cron hitting it in a loop.
        RateLimiter::for('cron', function (Request $request) {
            return Limit::perMinute(4)->by(ClientIp::network((string) $request->ip()));
        });

        // CMS admin login. The `web` middleware group ships with no throttling at
        // all, so POST /admin/login was completely unlimited with no lockout.
        RateLimiter::for('cms-login', function (Request $request) {
            // Runs before the vendor login, which would pass any JSON value to a query.
            $input = $request->input('email');
            if ($input !== null && !is_string($input)) {
                abort(422);
            }

            $ip = ClientIp::network((string) $request->ip());

            // Per source only. An email-only bucket is charged before the password is checked,
            // so strangers could refuse the admin's correct one, and the CMS has no reset to
            // clear it. AppServiceProvider raises a critical when many sources converge.
            return array_filter([
                Limit::perMinute(5)->by('cms-ip:' . $ip),
                is_string($input) && $input !== '' ? Limit::perMinutes(15, 10)->by('cms-email-ip:' . self::adminLoginIdentity($input) . '|' . $ip) : null,
            ]);
        });

        // Generous ceiling for the CMS/web group as a whole, so a single client
        // cannot saturate PHP-FPM workers through the admin panel.
        RateLimiter::for('web', function (Request $request) {
            return Limit::perMinute(120)->by(ClientIp::network((string) $request->ip()));
        });
    }
}
