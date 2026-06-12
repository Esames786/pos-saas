<?php

namespace App\Providers;

use App\Models\Master\Tenant;
use App\Services\Saas\TenantSubscriptionAccessService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // The tenant domain constraint uses {subdomain} for wildcard matching only.
        // Binding it to null causes parametersWithoutNulls() to drop it before
        // the controller dispatcher builds the argument list, preventing the
        // positional collision where 'demo' lands as arg #1 ahead of the actual
        // route model (e.g. Role $role, Branch $branch).
        Route::bind('subdomain', fn () => null);

        // Tenant subscription banner — only when a tenant is active in the
        // container (bound by TenancyManager::activate via IdentifyTenant).
        // Tenant is NOT on $request->attributes; it lives in app('tenant').
        View::composer('layouts.app', function ($view) {
            if (!app()->bound('tenant')) {
                return;
            }

            $tenant = app('tenant');

            if (!$tenant instanceof Tenant) {
                return;
            }

            $view->with(
                'tenantSubscriptionStatus',
                app(TenantSubscriptionAccessService::class)->subscriptionStatus($tenant)
            );
        });
    }
}
