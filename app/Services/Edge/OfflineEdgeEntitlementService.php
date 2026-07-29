<?php

namespace App\Services\Edge;

use App\Exceptions\OfflineEdgeAccessException;
use App\Models\Master\Tenant;
use App\Services\Saas\TenantSubscriptionAccessService;

/**
 * OFFLINE-EDGE-ENTITLEMENT-1 — the single place that answers "may this tenant/user
 * touch Offline Branch Edge, and can they download the installer?".
 *
 * THREE independent gates, never collapsed into one:
 *   1. tenantHasOfflineEdgeAccess()  — commercial ownership (offline_edge in the
 *      effective plan/module set). Reuses TenantSubscriptionAccessService — no plan
 *      logic is duplicated here.
 *   2. featureIsEnabled()            — rollout readiness (EDGE_FEATURE_ENABLED).
 *   3. installerIsAvailable()        — a real, configured installer artifact exists.
 *
 * All three read from subscription/config ONLY — never from request input. Branch/
 * device licensing is intentionally NOT handled here (see BRANCH-DEVICE-PAIRING-1 and
 * a later lease sprint): tenant entitlement is necessary but not sufficient for pairing.
 */
class OfflineEdgeEntitlementService
{
    public const MODULE_KEY = 'offline_edge';

    public function __construct(
        private readonly TenantSubscriptionAccessService $subscriptionAccess,
    ) {}

    /* ── Gate 1: commercial entitlement ─────────────────────────────────── */

    public function tenantHasOfflineEdgeAccess(): bool
    {
        $tenant = $this->currentTenant();

        if (! $tenant) {
            return false;
        }

        $subscription = $tenant->subscription?->loadMissing(['plan.enabledModules']);
        $plan         = $subscription?->plan;

        return (bool) $plan?->hasEnabledModuleKey(self::MODULE_KEY);
    }

    public function assertTenantHasOfflineEdgeAccess(): void
    {
        // The subscription middleware already renders the standard module-disabled
        // page for direct HTTP; this assertion is a defence-in-depth guard for any
        // code path (e.g. the download controller) that must re-check independently.
        if (! $this->tenantHasOfflineEdgeAccess()) {
            abort(403, 'Your current plan does not include the Offline Branch Edge module.');
        }
    }

    /* ── Gate 2: rollout flag ───────────────────────────────────────────── */

    public function featureIsEnabled(): bool
    {
        return (bool) config('app.edge_feature_enabled', false);
    }

    public function assertFeatureEnabled(): void
    {
        if (! $this->featureIsEnabled()) {
            throw OfflineEdgeAccessException::featureDisabled();
        }
    }

    /* ── Combined page-access gate ──────────────────────────────────────── */

    /**
     * Setup page access = entitled AND rolled out. Entitlement is enforced upstream by
     * the module middleware; here we additionally enforce the rollout flag so an
     * entitled tenant still cannot reach the incomplete setup journey before release.
     */
    public function assertSetupAccessAllowed(): void
    {
        $this->assertTenantHasOfflineEdgeAccess();
        $this->assertFeatureEnabled();
    }

    /* ── Gate 3: installer availability ─────────────────────────────────── */

    /**
     * True only when a real installer artifact is configured AND present on disk.
     * The path comes from config (never request input); an unset/missing/empty file
     * is "not available" — we never fabricate or substitute another EXE.
     */
    public function installerIsAvailable(): bool
    {
        $path = config('app.edge_installer_path');

        if (! is_string($path) || $path === '') {
            return false;
        }

        return is_file($path) && filesize($path) > 0;
    }

    public function installerVersion(): ?string
    {
        $v = config('app.edge_installer_version');

        return is_string($v) && $v !== '' ? $v : null;
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    private function currentTenant(): ?Tenant
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
