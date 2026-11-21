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

        // EDGE 0.9 (K2) — the setup wizard redeems the owner's one-time setup code (public; own IP limiter; the code's own
        // attempt limit bounds failures on a resolved code). Returns a setup token + a FRESH pairing code (existing pairing).
        Route::post('/setup/redeem', [\App\Http\Controllers\Edge\EdgeSetupApiController::class, 'redeem'])
            ->middleware('throttle:10,1,edge-setup-redeem')
            ->name('edge.api.setup.redeem');

        // EDGE 0.9 (K2b) — a fresh pairing code for an INSTALL setup session whose code expired / is in its last 3 minutes
        // (public: the wizard has no device credential yet; X-Edge-Setup-Token scopes it to the session's tenant + branch).
        Route::post('/setup/pairing-code', [\App\Http\Controllers\Edge\EdgeSetupApiController::class, 'pairingCode'])
            ->middleware('throttle:10,1,edge-setup-pairing')
            ->name('edge.api.setup.pairing-code');

        // EDGE 0.9 (K6) — the release zip behind a 15-minute SIGNED URL bound to an active device (issued by updates/latest).
        Route::get('/updates/{release}/download', [\App\Http\Controllers\Edge\EdgeUpdatesApiController::class, 'download'])
            ->middleware(['signed', 'throttle:10,1,edge-release-download'])
            ->where('release', '[0-9a-fA-F-]{36}')
            ->name('edge.api.updates.download');

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
            // P5B §3 — the Cloud backup recovery authority: the device's OWN branch material (current + retired keys), audited;
            // tighter per-device throttle with its own limiter prefix; the response is never cacheable.
            Route::post('/backup/recovery-keys', [\App\Http\Controllers\Edge\EdgeBackupRecoveryApiController::class, 'material'])
                ->middleware('throttle:6,1,edge-recovery')->name('edge.api.backup.recovery_keys');

            // EDGE 0.9 — setup wizard + auto-update + continuity (all Cloud-only; tenant/branch from the device).
            // K3: bulk enrollment assertions — also needs the X-Edge-Setup-Token of a live setup session of this branch.
            Route::post('/setup/enrollment-assertions', [\App\Http\Controllers\Edge\EdgeSetupApiController::class, 'enrollmentAssertions'])
                ->middleware('throttle:10,1,edge-setup-assertions')->name('edge.api.setup.enrollment-assertions');
            // K4 / K5: install/update status and the PUBLIC LAN profile (CA certificate validated).
            Route::post('/device/status', [\App\Http\Controllers\Edge\EdgeDeviceStatusApiController::class, 'status'])->name('edge.api.device.status');
            Route::post('/device/lan-profile', [\App\Http\Controllers\Edge\EdgeDeviceStatusApiController::class, 'lanProfile'])
                ->middleware('throttle:10,1,edge-lan-profile')->name('edge.api.device.lan-profile');
            // K6: is a newer signed release registered for this device (+ maintenance window, Update-now flag).
            Route::get('/updates/latest', [\App\Http\Controllers\Edge\EdgeUpdatesApiController::class, 'latest'])
                ->middleware('throttle:20,1,edge-updates')->name('edge.api.updates.latest');
            // K1: the branch live state the warm standby mirrors (read-only; monotonic revision; since= short-circuit).
            Route::get('/live-state', [\App\Http\Controllers\Edge\EdgeLiveStateApiController::class, 'show'])->name('edge.api.live-state');
        });
    });
