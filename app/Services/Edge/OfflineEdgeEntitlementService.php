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

    /** Canonical route the subscription-access engine resolves to the offline_edge module. */
    public const ROUTE_KEY = 'tenant.offline-edge.index';

    public function __construct(
        private readonly TenantSubscriptionAccessService $subscriptionAccess,
    ) {}

    /* ── Gate 1: commercial entitlement ─────────────────────────────────── */

    /**
     * HARDEN-1: the entitlement decision now flows through the ONE canonical
     * subscription-access engine, keyed by the Offline Edge index route, so every
     * subscription-status / plan-status / module-active / module-enabled rule is
     * reused verbatim (no duplicated logic, and a lapsed subscription that still
     * carries the module is now correctly blocked). Returns the raw check() result.
     */
    public function entitlementCheck(): array
    {
        $tenant = $this->currentTenant();

        if (! $tenant) {
            return [
                'allowed'    => false,
                'reason'     => 'no_tenant',
                'message'    => 'No active tenant context.',
                'module_key' => null,
                'module'     => null,
            ];
        }

        return $this->subscriptionAccess->check($tenant, self::ROUTE_KEY);
    }

    public function tenantHasOfflineEdgeAccess(): bool
    {
        $result = $this->entitlementCheck();

        // Commercial gate = fail closed. Only an explicit "module_enabled" verdict
        // grants a paid module; allow-by-default verdicts (always_allowed /
        // no_module_key / unmapped_route_module_key — e.g. a missing catalog row)
        // must NOT silently unlock Offline Edge.
        return ($result['allowed'] ?? false) === true
            && ($result['reason'] ?? null) === 'module_enabled';
    }

    public function assertTenantHasOfflineEdgeAccess(): void
    {
        // Browser HTTP is already blocked upstream by EnsureTenantSubscriptionAccess
        // (standard module-disabled view). This is defence-in-depth for the download
        // controller and any direct API/service caller — throw a STRUCTURED failure
        // (self-renders 403 EDGE_NOT_ENTITLED / module-disabled shell), never a bare
        // abort, so service callers get a typed, catchable result.
        if (! $this->tenantHasOfflineEdgeAccess()) {
            throw OfflineEdgeAccessException::notEntitled();
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
     * True only when a real installer artifact is configured AND is a readable,
     * non-empty regular file. The path comes from config ONLY (never request input);
     * an unset/empty path, a missing path, a DIRECTORY, a zero-byte file, or an
     * unreadable file are all "not available". We never fabricate or substitute
     * another EXE (e.g. the Print Agent installer).
     *
     * NOTE: this is an EXISTENCE/readability check, NOT cryptographic verification.
     * SHA-256 + signature validation (EDGE_INSTALLER_SHA256 / _SIGNATURE_PATH) is
     * reserved for the packaging sprint (EDGE-BUILD-PACKAGING-1).
     */
    public function installerIsAvailable(): bool
    {
        $path = config('app.edge_installer_path');

        if (! is_string($path) || trim($path) === '') {
            return false;   // unset / empty
        }

        if (! is_file($path)) {
            return false;   // missing OR a directory (is_file is false for dirs)
        }

        if (! is_readable($path)) {
            return false;   // present but unreadable
        }

        return @filesize($path) > 0;   // reject zero-byte placeholder
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
