<?php

namespace App\Providers;

use App\Observers\ProductObserver;
use App\Observers\UserCreditsObserver;
use App\Product;
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
        // Laravel 10 defaults to Tailwind pagination views. The CMS is Bootstrap 4
        // (hellotree already ships bootstrap.min.css); without this, $rows->links()
        // on every server-side list emits Tailwind utilities the admin theme never
        // loads, so the pager renders as an unstyled list.
        Paginator::useBootstrap();

        // Registered here rather than as a booted() hook inside the model: the
        // hellotree CMS regenerates every model file above its custom-function
        // markers on a page-schema save, which would silently drop the hook.
        Product::observe(ProductObserver::class);

        // Both User classes map to the `users` table: App\Models\User is the auth
        // model, App\User is what the CMS Users page and Order::users() resolve to.
        // A balance edited on the CMS page goes through App\User, so observing only
        // the auth model would miss exactly the case this is here to catch.
        \App\Models\User::observe(UserCreditsObserver::class);
        \App\User::observe(UserCreditsObserver::class);
    }
}
