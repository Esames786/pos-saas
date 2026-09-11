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

            // OFFLINE-SYNC-ENGINE-1D — device-authenticated sync ingestion (thin boundary around 1C).
            Route::post('/sync/sales', [\App\Http\Controllers\Edge\EdgeSyncIngestionApiController::class, 'store'])->name('edge.api.sync.sales');

            // PRODUCTIZATION GATE 0 — device-authenticated, READ-ONLY reconciliation status (no posting).
            Route::post('/sync/reconcile', [\App\Http\Controllers\Edge\EdgeSyncReconciliationApiController::class, 'status'])->name('edge.api.sync.reconcile');

            // PRODUCTIZATION GATE 0 — device-authenticated operational-baseline issuance (Cloud official position).
            Route::post('/sync/baseline', [\App\Http\Controllers\Edge\EdgeBaselineApiController::class, 'issue'])->name('edge.api.sync.baseline');

            // P0 BRANCH AUTHORITY LEASE — heartbeat renews the Cloud's per-branch lease (or asserts a local takeover);
            // handback returns authority when the appliance's sync is clean.
            Route::post('/authority/heartbeat', [\App\Http\Controllers\Edge\EdgeAuthorityApiController::class, 'heartbeat'])->name('edge.api.authority.heartbeat');
            Route::post('/authority/handback', [\App\Http\Controllers\Edge\EdgeAuthorityApiController::class, 'handback'])->name('edge.api.authority.handback');

            // Q — WARM STANDBY FRESHNESS: the current config refresh package for the device's branch (pulled when the
            // heartbeat advertises a newer config revision than the appliance has applied).
            Route::post('/config/refresh', [\App\Http\Controllers\Edge\EdgeConfigRefreshApiController::class, 'package'])->name('edge.api.config.refresh');

            // F1 — SALES RETURNS: the returnable-sale projection the standby mirrors, and the exactly-once ingestion of
            // Edge-originated return events through the OFFICIAL return authority.
            Route::post('/returnable/refresh', [\App\Http\Controllers\Edge\EdgeReturnableCacheApiController::class, 'package'])->name('edge.api.returnable.refresh');
            Route::post('/sync/returns', [\App\Http\Controllers\Edge\EdgeInboundReturnApiController::class, 'store'])->name('edge.api.sync.returns');
            // F2 — supplier finance: the warm projection the standby pulls, and the ingestion of supplier payments / AP journals.
            Route::post('/supplier-finance/refresh', [\App\Http\Controllers\Edge\EdgeSupplierFinanceCacheApiController::class, 'package'])->name('edge.api.supplier-finance.refresh');
            Route::post('/sync/supplier-finance', [\App\Http\Controllers\Edge\EdgeInboundSupplierFinanceApiController::class, 'store'])->name('edge.api.sync.supplier-finance');
            // F3 — purchase returns: the warm projection the standby pulls, and the ingestion of purchase-return events.
            Route::post('/purchase-returns/refresh', [\App\Http\Controllers\Edge\EdgePurchaseReturnCacheApiController::class, 'package'])->name('edge.api.purchase-returns.refresh');
            Route::post('/sync/purchase-returns', [\App\Http\Controllers\Edge\EdgeInboundPurchaseReturnApiController::class, 'store'])->name('edge.api.sync.purchase-returns');
        });
    });
