<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Tenant\DashboardController;
use Illuminate\Support\Facades\Route;

Route::domain('{subdomain}.' . config('tenancy.tenant_base_domain'))
    ->middleware(['tenant.only'])
    ->group(function () {

        Route::get('/login', [AuthController::class, 'showLogin'])->name('tenant.login');
        Route::post('/login', [AuthController::class, 'login'])->name('tenant.login.post');
        Route::post('/logout', [AuthController::class, 'logout'])->name('tenant.logout');

        Route::middleware(['auth:tenant', 'route.permission'])->group(function () {
            Route::get('/', fn () => redirect('/dashboard'));
            Route::get('/dashboard', DashboardController::class)->name('tenant.dashboard');
        });
    });
