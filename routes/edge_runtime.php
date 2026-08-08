<?php

use App\Http\Controllers\Edge\EdgeLocalAuthController;
use App\Http\Controllers\Edge\EdgeLocalPosController;
use App\Http\Controllers\Edge\EdgeRuntimeController;
use Illuminate\Support\Facades\Route;

/**
 * EDGE-RUNTIME-BOUNDARY-1 — Branch Server LOCAL runtime routes (health / readiness / build info).
 *
 * Unlike routes/edge.php (the CENTRAL cloud-side pairing/bootstrap API on the central domain), these
 * run ON the Branch Server appliance, so they are NOT domain-restricted — they answer on the local
 * host. They are the ONLY names on the branch_server route allowlist (config/edge.php). On a cloud
 * instance they still resolve (non-secret) but are unremarkable; on a branch_server instance the
 * runtime boundary permits ONLY these.
 *
 * They intentionally carry NO auth (no local auth exists yet) and expose non-secret data only.
 */
Route::prefix('edge/local')->name('edge.local.')->group(function () {
    // Non-secret liveness/readiness/build (no auth — health must answer while uninitialised).
    Route::get('/health', [EdgeRuntimeController::class, 'health'])->name('health');
    Route::get('/ready', [EdgeRuntimeController::class, 'ready'])->name('ready');
    Route::get('/build-info', [EdgeRuntimeController::class, 'buildInfo'])->name('build-info');

    // EDGE-LOCAL-AUTH-1 — local login/logout + authenticated status. These authenticate via the Edge
    // credential (never the Cloud password) and establish the `tenant` session. /pos is NOT opened.
    Route::get('/login', [EdgeLocalAuthController::class, 'showLogin'])->name('auth.login');
    Route::post('/login', [EdgeLocalAuthController::class, 'login'])->middleware('throttle:edge-login')->name('auth.login.post');
    Route::post('/logout', [EdgeLocalAuthController::class, 'logout'])->name('auth.logout');
    Route::get('/status', [EdgeLocalAuthController::class, 'status'])->middleware('edge.auth')->name('auth.status');

    // EDGE-LOCAL-POS-1 — the branch-local POS surface: authenticated local session (edge.auth) + bound
    // appliance (edge.branch — request tenant/branch ids can never override the binding). Registered ONLY
    // on a branch_server (this file is not loaded on Cloud), and every name is on the explicit allowlist.
    // Cash quick_sale/takeaway only; the service refuses everything else. activation_ready stays false.
    Route::prefix('pos')->name('pos.')->middleware(['edge.auth', 'edge.branch'])->group(function () {
        Route::get('/terminals', [EdgeLocalPosController::class, 'terminals'])->name('terminals');
        Route::post('/terminal/select', [EdgeLocalPosController::class, 'selectTerminal'])->name('terminal.select');
        Route::get('/shift', [EdgeLocalPosController::class, 'shiftStatus'])->name('shift.status');
        Route::post('/shift/open', [EdgeLocalPosController::class, 'openShift'])->name('shift.open');
        Route::post('/shift/close', [EdgeLocalPosController::class, 'closeShift'])->name('shift.close');
        Route::post('/sales', [EdgeLocalPosController::class, 'storeSale'])->name('sales.store');
    });
});
