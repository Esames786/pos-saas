<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Central\DashboardController;
use App\Http\Controllers\Central\RouteCatalogController;
use Illuminate\Support\Facades\Route;

Route::domain(config('tenancy.central_domain'))
    ->middleware(['central.only'])
    ->group(function () {

        Route::get('/login', [AuthController::class, 'showLogin'])->name('central.login');
        Route::post('/login', [AuthController::class, 'login'])->name('central.login.post');
        Route::post('/logout', [AuthController::class, 'logout'])->name('central.logout');

        Route::middleware(['auth:central', 'route.permission'])->group(function () {
            Route::get('/', fn () => redirect('/dashboard'));
            Route::get('/dashboard', DashboardController::class)->name('central.dashboard');

            Route::post('/routes/sync', [RouteCatalogController::class, 'sync'])->name('central.routes.sync');
            Route::post('/routes/publish', [RouteCatalogController::class, 'publish'])->name('central.routes.publish');
        });
    });
