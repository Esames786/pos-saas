<?php

namespace App\Services\Edge;

use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Models\Tenant\User;
use App\Support\EdgeUserAuthz;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * EDGE-LOCAL-AUTH-1 (Sections 5/6) — CLOUD side. Issues a signed, single-use enrollment assertion that
 * lets a bootstrapped Branch Server user establish their FIRST Edge credential (breaking the circular
 * first-login problem). Only an authorized Cloud actor reaches this (route authorization); this
 * service enforces the runtime contract and signs with the Cloud PRIVATE key.
 *
 * Fails closed: no signing key / no crypto / device-not-current / wrong epoch / entitlement-fail /
 * target-user-inactive-or-unauthorized  → refuse. Never issues for another tenant/branch/device/user.
 */
class EdgeEnrollmentIssuer
{
    public function __construct(
        private readonly EdgeActivationEpochService $epoch,
        private readonly EdgePairingService $pairing,
        private readonly OfflineEdgeEntitlementService $entitlement,
    ) {
    }

    /**
     * Issue a signed assertion for $targetUser to enroll on $device (the current appliance of
     * tenant+branch). Returns {payload, signature}. The tenant must be ACTIVE (tenant connection) so
     * $targetUser + branch access resolve.
     */
    public function issue(Tenant $tenant, int $branchId, EdgeDevice $device, User $targetUser, int $issuerUserId): array
    {
        $signingKey = (string) config('edge.enrollment.signing_key');
        if ($signingKey === '' || ! EdgeEnrollmentCrypto::available()) {
            throw new RuntimeException('Edge enrollment issuer is not configured (missing signing key or crypto). Failing closed.');
        }

        // Device must be THE current, non-revoked active device for this tenant+branch.
        if ($device->isRevoked() || $device->active_slot !== EdgeDevice::ACTIVE_SLOT
            || (int) $device->tenant_id !== (int) $tenant->id || (int) $device->branch_id !== $branchId) {
            throw new RuntimeException('Enrollment refused: device is not the current active appliance for this branch.');
        }
        $current = $this->pairing->activeDeviceForBranch((int) $tenant->id, $branchId);
        if (! $current || (int) $current->id !== (int) $device->id) {
            throw new RuntimeException('Enrollment refused: device is not the current active appliance for this branch.');
        }

        // Current activation generation.
        $epoch = $this->epoch->currentGeneration((int) $tenant->id, $branchId);
        if ($epoch < 1) {
            throw new RuntimeException('Enrollment refused: branch has no activation generation yet.');
        }

        // Offline-edge entitlement.
        if (! $this->entitlement->featureIsEnabled() || ! $this->entitlement->tenantHasOfflineEdgeAccess()) {
            throw new RuntimeException('Enrollment refused: offline Edge entitlement is not available.');
        }

        // Target user must be active, Edge-login-eligible (has an employee_code) and authorized here.
        if (! EdgeUserAuthz::isActive($targetUser)) {
            throw new RuntimeException('Enrollment refused: target user is not active.');
        }
        if (! EdgeUserAuthz::isEdgeLoginEligible($targetUser)) {
            throw new RuntimeException('Enrollment refused: target user has no employee_code and cannot log in locally.');
        }
        if (! EdgeUserAuthz::mayOperateBranch($targetUser, $branchId)) {
            throw new RuntimeException('Enrollment refused: target user is not authorized for this branch.');
        }

        $now = time();
        $ttl = (int) config('edge.enrollment.assertion_ttl', 900);
        $payload = [
            'version' => EdgeEnrollmentCrypto::ASSERTION_VERSION,
            'purpose' => EdgeEnrollmentCrypto::PURPOSE,
            'tenant_id' => (int) $tenant->id,
            'tenant_code' => (string) $tenant->tenant_code,
            'branch_id' => $branchId,
            'device_public_uuid' => (string) $device->public_uuid,
            'activation_epoch' => $epoch,
            'user_id' => (int) $targetUser->id,
            'jti' => (string) Str::ulid(),
            'issuer_user_id' => $issuerUserId,
            'issued_at' => $now,
            'expires_at' => $now + $ttl,
        ];

        return EdgeEnrollmentCrypto::sign($payload, $signingKey);
    }
}
