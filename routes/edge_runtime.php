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
        // EDGE-CASHIER-UI-1 — the browser cashier POS page itself (the Online operator surface, Edge-executed).
        Route::get('/', [EdgeLocalPosController::class, 'screen'])->name('screen');
        Route::get('/terminals', [EdgeLocalPosController::class, 'terminals'])->name('terminals');
        Route::post('/terminal/select', [EdgeLocalPosController::class, 'selectTerminal'])->name('terminal.select');
        Route::get('/shift', [EdgeLocalPosController::class, 'shiftStatus'])->name('shift.status');
        Route::post('/shift/open', [EdgeLocalPosController::class, 'openShift'])->name('shift.open');
        Route::post('/shift/close', [EdgeLocalPosController::class, 'closeShift'])->name('shift.close');
        Route::post('/sales', [EdgeLocalPosController::class, 'storeSale'])->name('sales.store');
        // ONLINE-POS PARITY — Preview Bill (zero-mutation running bill).
        Route::post('/preview-bill', [EdgeLocalPosController::class, 'previewBill'])->name('preview.bill');

        // Restaurant layer: dine-in table sessions, held orders (Add Round), KOT business events,
        // settle/cancel, manager re-auth. Same authority envelope (EdgeLocalPosService); NO print transport.
        Route::get('/restaurant/board', [EdgeLocalPosController::class, 'restaurantBoard'])->name('restaurant.board');
        Route::post('/restaurant/tables/{table}/open', [EdgeLocalPosController::class, 'openTable'])->name('restaurant.table.open');
        // ONLINE-POS PARITY — table reservations (reserve / view / cancel).
        Route::get('/restaurant/tables/{table}/reservation', [EdgeLocalPosController::class, 'tableReservation'])->name('restaurant.table.reservation');
        Route::post('/restaurant/tables/{table}/reserve', [EdgeLocalPosController::class, 'reserveTable'])->name('restaurant.table.reserve');
        Route::post('/restaurant/tables/{table}/unreserve', [EdgeLocalPosController::class, 'cancelReservation'])->name('restaurant.table.unreserve');
        Route::post('/restaurant/table-sessions/{session}/close', [EdgeLocalPosController::class, 'closeTableSession'])->name('restaurant.session.close');
        Route::post('/held-sales', [EdgeLocalPosController::class, 'storeHeldSale'])->name('held.store');
        Route::post('/held-sales/{sale}/kot', [EdgeLocalPosController::class, 'queueKot'])->name('held.kot');
        Route::post('/held-sales/{sale}/settle', [EdgeLocalPosController::class, 'settleHeldSale'])->name('held.settle');
        Route::post('/held-sales/{sale}/cancel', [EdgeLocalPosController::class, 'cancelHeldSale'])->name('held.cancel');
        Route::post('/manager-approvals/verify', [EdgeLocalPosController::class, 'verifyManagerApproval'])->name('manager.verify');
    });
});
