<?php

use App\Http\Controllers\Edge\EdgeLocalAuthController;
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
});
