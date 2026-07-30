<?php

namespace App\Services\Edge;

use App\Exceptions\EdgePairingException;
use App\Models\Master\EdgeDevice;
use App\Models\Master\EdgePairingCode;
use App\Models\Master\Module;
use App\Models\Master\PlanModule;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * BRANCH-DEVICE-PAIRING-1 — issue / cancel a branch pairing code, exchange it for a
 * device registration, authenticate a device, and revoke it.
 *
 * SECURITY MODEL
 *  - The six-digit code is never stored; only code_hash = HMAC-SHA256(code, app key).
 *    (The queryable digest omits the public_uuid on purpose: the installer must pair
 *    with the code ALONE — cloud URL + 6 digits — so the server has to find the row
 *    from the code. Generation guarantees the live code's hash is unique among recent
 *    codes, so the lookup is unambiguous.)
 *  - The device secret is generated CLIENT-side; the cloud stores only sha256(secret).
 *    A lost pairing response is safe: retrying with the same code + installation_uuid +
 *    secret_hash returns the SAME device and never creates a second one.
 *  - Pairing creates a device in pending_bootstrap ONLY. It never activates Local POS,
 *    never blocks cloud sales, never downloads data.
 */
class EdgePairingService
{
    public const CODE_TTL_MINUTES     = 15;
    public const MAX_ATTEMPTS         = 5;
    /** Fail-closed default when the plan carries no explicit offline_edge device limit. */
    public const DEFAULT_DEVICE_LIMIT = 1;
    public const DEVICE_LIMIT_KEY     = 'max_active_edge_devices';

    public function __construct(
        private readonly OfflineEdgeEntitlementService $entitlement,
        private readonly BranchOperatingModeService $mode,
        private readonly TenancyManager $tenancy,
    ) {}

    /* ── hashing ─────────────────────────────────────────────────────────── */

    /** Queryable HMAC of the six-digit code (keyed with the app key). */
    public function digest(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /** sha256 of a client secret — the ONLY device-secret form the cloud ever stores. */
    public function secretHash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /* ── device limit ────────────────────────────────────────────────────── */

    /** Tenant-wide active-device cap from the offline_edge plan-module limits (fail-closed default). */
    public function deviceLimit(Tenant $tenant): int
    {
        $plan = $tenant->subscription?->loadMissing('plan')->plan;
        if (! $plan) {
            return self::DEFAULT_DEVICE_LIMIT;
        }
        $module = Module::where('key', OfflineEdgeEntitlementService::MODULE_KEY)->first();
        $pm     = $module ? PlanModule::where('plan_id', $plan->id)->where('module_id', $module->id)->first() : null;
        $limit  = $pm?->limits[self::DEVICE_LIMIT_KEY] ?? null;

        // Missing/blank/non-positive → fail closed to the safe default (NOT unlimited).
        return (is_numeric($limit) && (int) $limit > 0) ? (int) $limit : self::DEFAULT_DEVICE_LIMIT;
    }

    public function activeDeviceCount(int $tenantId): int
    {
        return EdgeDevice::active()->where('tenant_id', $tenantId)->count();
    }

    public function activeDeviceForBranch(int $tenantId, int $branchId): ?EdgeDevice
    {
        return EdgeDevice::active()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->first();
    }

    public function liveCodeForBranch(int $tenantId, int $branchId): ?EdgePairingCode
    {
        return EdgePairingCode::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('active_slot', EdgePairingCode::ACTIVE_SLOT)
            ->first();
    }

    /* ── generate (tenant, authenticated) ────────────────────────────────── */

    /**
     * Issue a fresh six-digit code for a branch. Assumes the caller already enforced
     * entitlement + rollout + Owner permission. Returns the plaintext code ONCE.
     */
    public function generateCode(Tenant $tenant, Branch $branch, int $userId): array
    {
        // A branch with an already-active device is not re-pairable without an explicit revoke.
        if ($this->activeDeviceForBranch($tenant->id, $branch->id)) {
            throw EdgePairingException::of(EdgePairingException::CODE_DEVICE_CONFLICT);
        }
        // Licensed device cap.
        if ($this->activeDeviceCount($tenant->id) >= $this->deviceLimit($tenant)) {
            throw EdgePairingException::of(EdgePairingException::CODE_DEVICE_LIMIT);
        }

        // Invalidate any existing live code for this branch (single active code per branch).
        EdgePairingCode::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->where('active_slot', EdgePairingCode::ACTIVE_SLOT)
            ->update(['active_slot' => null, 'cancelled_at' => now()]);

        // Unique six-digit code among all recent codes (24h) so exchange lookup is unambiguous.
        [$code, $hash] = $this->freshCode();

        $row = EdgePairingCode::create([
            'public_uuid'        => (string) Str::uuid(),
            'tenant_id'          => $tenant->id,
            'branch_id'          => $branch->id,
            'code_hash'          => $hash,
            'attempts'           => 0,
            'max_attempts'       => self::MAX_ATTEMPTS,
            'expires_at'         => now()->addMinutes(self::CODE_TTL_MINUTES),
            'active_slot'        => EdgePairingCode::ACTIVE_SLOT,
            'created_by_user_id' => $userId,
        ]);

        // First code on an inactive cloud branch moves it to pending (pending never blocks
        // cloud sales). Existing pending branches stay pending. Never touches active/closing.
        if ($branch->local_edge_status === Branch::STATUS_INACTIVE) {
            $this->mode->transition($branch, Branch::STATUS_PENDING, $userId, 'edge_pairing_code_generated');
        }

        $this->audit('edge.pairing_code.generated', [
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
            'code_uuid' => $row->public_uuid, 'user_id' => $userId,
        ]);

        return [
            'code'        => $code,          // shown ONCE — never stored/logged in plaintext
            'public_uuid' => $row->public_uuid,
            'expires_at'  => $row->expires_at->toIso8601String(),
            'branch_id'   => $branch->id,
        ];
    }

    private function freshCode(): array
    {
        for ($i = 0; $i < 40; $i++) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = $this->digest($code);
            $clash = EdgePairingCode::where('code_hash', $hash)
                ->where('created_at', '>=', now()->subDay())
                ->exists();
            if (! $clash) {
                return [$code, $hash];
            }
        }
        // Astronomically unlikely; fail safe rather than loop forever.
        throw EdgePairingException::of(EdgePairingException::CODE_INVALID);
    }

    /* ── cancel (tenant, authenticated) ──────────────────────────────────── */

    public function cancelCode(Tenant $tenant, Branch $branch, int $userId): void
    {
        EdgePairingCode::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->where('active_slot', EdgePairingCode::ACTIVE_SLOT)
            ->update(['active_slot' => null, 'cancelled_at' => now()]);

        // If nothing is paired and no other setup state remains, return a pending branch to cloud.
        if (! $this->activeDeviceForBranch($tenant->id, $branch->id)
            && $branch->local_edge_status === Branch::STATUS_PENDING) {
            $this->mode->transition($branch, Branch::STATUS_INACTIVE, $userId, 'edge_pairing_cancelled');
        }

        $this->audit('edge.pairing_code.cancelled', [
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'user_id' => $userId,
        ]);
    }

    /* ── exchange (public API) ───────────────────────────────────────────── */

    /**
     * Exchange a pairing code for a device registration. Resolves tenant+branch FROM THE
     * CODE (never request input). Re-checks entitlement + rollout at exchange time.
     * Response-loss safe: same code + installation_uuid + secret_hash → same device.
     *
     * @param array{code:string,installation_uuid:string,device_name:?string,device_secret_hash:string,app_version:?string,schema_version:?string} $in
     */
    public function exchange(array $in): array
    {
        $code = preg_replace('/\D+/', '', (string) ($in['code'] ?? ''));
        if (strlen((string) $code) !== 6) {
            throw EdgePairingException::of(EdgePairingException::CODE_INVALID);
        }

        $installationUuid = (string) $in['installation_uuid'];
        $deviceSecretHash = strtolower((string) $in['device_secret_hash']);

        return DB::connection('master')->transaction(function () use ($code, $installationUuid, $deviceSecretHash, $in) {
            $pc = EdgePairingCode::where('code_hash', $this->digest($code))
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $pc) {
                throw EdgePairingException::of(EdgePairingException::CODE_INVALID);
            }

            // Response-loss / duplicate delivery: a used code retried by the SAME device.
            if ($pc->used_at !== null) {
                $device = $pc->paired_device_id ? EdgeDevice::find($pc->paired_device_id) : null;
                if ($device && ! $device->isRevoked()
                    && $device->installation_uuid === $installationUuid
                    && hash_equals($device->device_secret_hash, $deviceSecretHash)) {
                    return $this->deviceMeta($device);   // idempotent replay — no new device
                }
                throw EdgePairingException::of(EdgePairingException::CODE_USED);
            }

            if ($pc->cancelled_at !== null) {
                throw EdgePairingException::of(EdgePairingException::CODE_INVALID);
            }
            if ($pc->isExpired()) {
                $pc->update(['active_slot' => null]);   // burn
                throw EdgePairingException::of(EdgePairingException::CODE_EXPIRED);
            }
            if ($pc->isExhausted()) {
                throw EdgePairingException::of(EdgePairingException::CODE_EXHAUSTED);
            }

            // Resolve tenant + branch strictly from the code row.
            $tenant = Tenant::find($pc->tenant_id);
            if (! $tenant) {
                $this->burnAttempt($pc);
                throw EdgePairingException::of(EdgePairingException::CODE_INVALID);
            }

            $this->tenancy->activate($tenant);           // sets tenant DB + app('tenant')
            try {
                // Re-check entitlement + rollout at EXCHANGE time (never trust generation-time state).
                if (! $this->entitlement->featureIsEnabled() || ! $this->entitlement->tenantHasOfflineEdgeAccess()) {
                    $this->burnAttempt($pc);
                    throw EdgePairingException::of(EdgePairingException::CODE_ENTITLEMENT);
                }

                $branch = Branch::find($pc->branch_id);
                if (! $branch || ! in_array($branch->local_edge_status, [Branch::STATUS_INACTIVE, Branch::STATUS_PENDING], true)) {
                    $this->burnAttempt($pc);
                    throw EdgePairingException::of(EdgePairingException::CODE_DEVICE_CONFLICT);
                }

                // Already an active device on this branch?
                $existing = $this->activeDeviceForBranch($pc->tenant_id, $pc->branch_id);
                if ($existing) {
                    if ($existing->installation_uuid === $installationUuid
                        && hash_equals($existing->device_secret_hash, $deviceSecretHash)) {
                        // same device re-binding a fresh code — idempotent
                        $pc->update(['used_at' => now(), 'paired_device_id' => $existing->id, 'active_slot' => null]);
                        return $this->deviceMeta($existing);
                    }
                    throw EdgePairingException::of(EdgePairingException::CODE_DEVICE_CONFLICT);
                }

                // Licensed device cap (re-check at exchange).
                if ($this->activeDeviceCount($pc->tenant_id) >= $this->deviceLimit($tenant)) {
                    $this->burnAttempt($pc);
                    throw EdgePairingException::of(EdgePairingException::CODE_DEVICE_LIMIT);
                }

                $device = EdgeDevice::create([
                    'public_uuid'        => (string) Str::uuid(),
                    'tenant_id'          => $pc->tenant_id,
                    'branch_id'          => $pc->branch_id,
                    'installation_uuid'  => $installationUuid,
                    'device_name'        => Str::limit((string) ($in['device_name'] ?? ''), 190, '') ?: null,
                    'device_secret_hash' => $deviceSecretHash,
                    'status'             => EdgeDevice::STATUS_PENDING_BOOTSTRAP,
                    'active_slot'        => EdgeDevice::ACTIVE_SLOT,
                    'app_version'        => $in['app_version'] ?? null,
                    'schema_version'     => $in['schema_version'] ?? null,
                    'paired_at'          => now(),
                ]);

                $pc->update(['used_at' => now(), 'paired_device_id' => $device->id, 'active_slot' => null]);

                // Branch stays pending (never active here). A stray inactive branch → pending.
                if ($branch->local_edge_status === Branch::STATUS_INACTIVE) {
                    $this->mode->transition($branch, Branch::STATUS_PENDING, null, 'edge_device_paired');
                }

                $this->audit('edge.device.paired', [
                    'tenant_id' => $pc->tenant_id, 'branch_id' => $pc->branch_id,
                    'device_uuid' => $device->public_uuid,
                ]);

                return $this->deviceMeta($device);
            } finally {
                $this->tenancy->deactivate();
            }
        });
    }

    private function burnAttempt(EdgePairingCode $pc): void
    {
        $pc->increment('attempts');
    }

    /* ── revoke (tenant, authenticated — NO entitlement/flag dependency) ─── */

    public function revokeDevice(Tenant $tenant, EdgeDevice $device, int $userId, ?string $reason = null): void
    {
        // Security control: revocation must work even if entitlement was removed or the
        // rollout flag was turned off. Only auth + ownership are required (enforced by caller).
        $wasActive = $device->status === EdgeDevice::STATUS_ACTIVE;

        $device->update([
            'status'             => EdgeDevice::STATUS_REVOKED,
            'active_slot'        => null,          // frees the (tenant,branch) slot + kills auth
            'revoked_at'         => now(),
            'revoked_by_user_id' => $userId,
            'revoke_reason'      => $reason ? Str::limit($reason, 190, '') : 'revoked_by_owner',
        ]);

        $branch = Branch::find($device->branch_id);
        if ($branch) {
            if ($wasActive && $branch->local_edge_status === Branch::STATUS_ACTIVE) {
                // A live Local-POS device is being pulled — go active → suspended (never straight to inactive).
                $this->mode->transition($branch, Branch::STATUS_SUSPENDED, $userId, 'edge_device_revoked');
            } elseif ($branch->local_edge_status === Branch::STATUS_PENDING
                && ! $this->activeDeviceForBranch($tenant->id, $branch->id)
                && ! $this->liveCodeForBranch($tenant->id, $branch->id)) {
                // Pre-activation device pulled and nothing else pending → back to cloud.
                $this->mode->transition($branch, Branch::STATUS_INACTIVE, $userId, 'edge_device_revoked');
            }
        }

        $this->audit('edge.device.revoked', [
            'tenant_id' => $tenant->id, 'branch_id' => $device->branch_id,
            'device_uuid' => $device->public_uuid, 'user_id' => $userId,
        ]);
    }

    /* ── shared ──────────────────────────────────────────────────────────── */

    public function deviceMeta(EdgeDevice $device): array
    {
        $tenant = Tenant::find($device->tenant_id);
        $branchName = null;
        // branch name is best-effort (tenant DB); never leak other tenants' data.
        if (app()->bound('tenant') && app('tenant')->id === $device->tenant_id) {
            $branchName = optional(Branch::find($device->branch_id))->name;
        }

        return [
            'device_id'     => $device->public_uuid,
            'public_uuid'   => $device->public_uuid,
            'tenant_code'   => $tenant?->tenant_code,
            'branch_id'     => $device->branch_id,
            'branch_name'   => $branchName,
            'device_status' => $device->status,
            'cloud_base_url' => rtrim((string) config('app.url'), '/'),
            'paired_at'     => optional($device->paired_at)->toIso8601String(),
        ];
    }

    public function audit(string $event, array $ctx = []): void
    {
        Log::info("[edge-pairing-audit] {$event}", $ctx);
    }
}
