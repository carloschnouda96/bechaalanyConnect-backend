<?php

namespace App\Providers;

use App\Observers\ProductsVariationObserver;
use App\Observers\UserCreditsObserver;
use App\ProductsVariation;
use App\Services\Suppliers\SupplierRegistry;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One registry instance maps each external_source key to its connector.
        $this->app->singleton(SupplierRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Many sources failing on one admin: staff are told. Nothing refuses the admin on it.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Failed::class, function ($event) {
            if ($event->guard !== 'admin') {
                return;
            }

            // By account where one was found: spellings of one address are one target.
            $identity = $event->user
                ? 'admin:' . $event->user->getAuthIdentifier()
                : strtolower(trim((string) ($event->credentials['email'] ?? '')));
            $key = 'cms-login-failure-signal:' . sha1($identity);

            if (\Illuminate\Support\Facades\RateLimiter::hit($key, 900) === 50) {
                \Illuminate\Support\Facades\Log::critical('Many failed CMS logins on one address from many sources', ['identity_hash' => sha1($identity)]);
            }
        });

        // Boots after the package provider, so this replaces its `admin` alias.
        $this->app['router']->aliasMiddleware('admin', \App\Http\Middleware\CmsAdminMiddleware::class);

        // Laravel 10 defaults to Tailwind pagination views. The CMS is Bootstrap 4
        // (hellotree already ships bootstrap.min.css); without this, $rows->links()
        // on every server-side list emits Tailwind utilities the admin theme never
        // loads, so the pager renders as an unstyled list.
        Paginator::useBootstrap();

        // Registered here rather than as a booted() hook inside the model: the
        // hellotree CMS regenerates every model file above its custom-function
        // markers on a page-schema save, which would silently drop the hook.
        //
        // Reprices a variation when its profit % changes, and otherwise auto-locks
        // Price against the supplier sync the moment an admin (or any non-sync
        // code path) edits it directly — see the observer's docblock.
        ProductsVariation::observe(ProductsVariationObserver::class);

        // Resolves a user-type price in profit-% mode to cost x (1 + %), so
        // product_price_variations.price is always what the tier is charged.
        \App\ProductPriceVariation::observe(\App\Observers\ProductPriceVariationObserver::class);

        // Both User classes map to the `users` table: App\Models\User is the auth
        // model, App\User is what the CMS Users page and Order::users() resolve to.
        // A balance edited on the CMS page goes through App\User, so observing only
        // the auth model would miss exactly the case this is here to catch.
        \App\Models\User::observe(UserCreditsObserver::class);
        \App\User::observe(UserCreditsObserver::class);
    }
}
