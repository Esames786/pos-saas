<?php

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
    Route::get('/health', [EdgeRuntimeController::class, 'health'])->name('health');
    Route::get('/ready', [EdgeRuntimeController::class, 'ready'])->name('ready');
    Route::get('/build-info', [EdgeRuntimeController::class, 'buildInfo'])->name('build-info');
});
