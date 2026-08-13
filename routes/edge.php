<?php

use App\Http\Controllers\Edge\EdgeBootstrapApiController;
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

        // Device-authenticated endpoints (NOT heartbeat/sync/activation).
        Route::middleware(['edge.device.auth', 'throttle:60,1'])->group(function () {
            Route::get('/device/me', [EdgePairingApiController::class, 'me'])->name('edge.api.device.me');

            // BRANCH-BOOTSTRAP-SNAPSHOT-1 — branch-scoped bootstrap snapshot download.
            // Tenant+branch come from the device; snapshots are addressed by public UUID and
            // ownership-checked. No activation / no sync here.
            Route::post('/bootstrap/snapshots', [EdgeBootstrapApiController::class, 'create'])->name('edge.api.bootstrap.create');
            Route::get('/bootstrap/snapshots/{uuid}/manifest', [EdgeBootstrapApiController::class, 'manifest'])->name('edge.api.bootstrap.manifest');
            Route::get('/bootstrap/snapshots/{uuid}/sections/{section}', [EdgeBootstrapApiController::class, 'section'])->name('edge.api.bootstrap.section');
            Route::post('/bootstrap/snapshots/{uuid}/acknowledge', [EdgeBootstrapApiController::class, 'acknowledge'])->name('edge.api.bootstrap.acknowledge');

            // EDGE-COMPATIBILITY-CONTRACT-1 — version/capability exchange (no heartbeat/sync/activation).
            Route::post('/compatibility/report', [\App\Http\Controllers\Edge\EdgeCompatibilityApiController::class, 'report'])->name('edge.api.compatibility.report');
        });
    });
