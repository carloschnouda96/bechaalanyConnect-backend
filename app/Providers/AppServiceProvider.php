<?php

namespace App\Providers;

use App\Services\Suppliers\SupplierRegistry;
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
        //
    }
}
