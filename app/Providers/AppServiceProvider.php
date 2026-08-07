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
        // EDGE-RUNTIME-BOUNDARY-1: fail closed on a misconfigured Branch Server. A branch appliance
        // that cannot describe its own runtime must NOT silently boot as the full Cloud SaaS. Only
        // enforced for the HTTP/CLI runtime (not during migrations/config caching, where config may
        // not be fully resolved), and never for the cloud role.
        if ((\App\Support\EdgeRuntime::isBranchServer() || \App\Support\EdgeRuntime::isPackagedEdgeArtifact())
            && ! $this->app->runningInConsole()) {
            \App\Support\EdgeRuntime::assertBootConfig();
        }

        // EDGE-LOCAL-RUNTIME-1 (Section E): DEFAULT-DENY console boundary. On a branch_server runtime,
        // any Artisan command not on the Edge CLI allowlist is refused before it runs — so a Cloud-only
        // command can never operate against the appliance's local database. No-op on Cloud.
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Console\Events\CommandStarting::class,
            function (\Illuminate\Console\Events\CommandStarting $event) {
                \App\Support\EdgeConsoleBoundary::assertAllowed($event->command);
            }
        );

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
