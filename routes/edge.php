<?php

use App\Http\Controllers\Edge\EdgePairingApiController;
use Illuminate\Support\Facades\Route;

/**
 * BRANCH-DEVICE-PAIRING-1 — CENTRAL, unauthenticated Edge pairing/device API.
 * Registered on the central domain (the cloud base URL the installer already knows).
 * No tenant subdomain required — the pairing code resolves tenant + branch. CSRF is
 * excluded for `api/edge/*` (see bootstrap/app.php); rate limits are applied per route.
 */
Route::domain(config('tenancy.central_domain'))
    ->middleware(['central.only'])
    ->prefix('api/edge')
    ->group(function () {
        // Public exchange — aggressive IP throttle; per-code brute force is bounded by
        // the code's own max_attempts inside the service.
        Route::post('/pair', [EdgePairingApiController::class, 'pair'])
            ->middleware('throttle:5,1')
            ->name('edge.api.pair');

        // Device-authenticated proof endpoint (NOT heartbeat/bootstrap/sync).
        Route::middleware(['edge.device.auth', 'throttle:60,1'])->group(function () {
            Route::get('/device/me', [EdgePairingApiController::class, 'me'])->name('edge.api.device.me');
        });
    });
