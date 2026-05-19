<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Route;
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
    }
}
