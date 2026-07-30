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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * BRANCH-DEVICE-PAIRING-1 (+ HARDEN-1) — issue / cancel a branch pairing code, exchange
 * it for a device registration, authenticate a device, and revoke it.
 *
 * SECURITY MODEL
 *  - The six-digit code is never stored; only code_hash = HMAC-SHA256(code, app key).
 *    The queryable digest omits the public_uuid on purpose (the installer pairs with the
 *    code alone). DB-enforced unique(code_hash, active_slot) guarantees two live codes can
 *    never share the same six digits, so the exchange lookup is unambiguous.
 *  - The device secret is generated CLIENT-side; the cloud stores only sha256(secret).
 *    A lost pairing response is safe: retrying with the same code + installation_uuid +
 *    secret_hash returns the SAME device and never creates a second one.
 *  - Pairing creates a device in pending_bootstrap ONLY. It never activates Local POS.
 *
 * HARDEN-1 durability/concurrency
 *  - Failure-state mutations (attempt increments, expired/exhausted burns) COMMIT: the
 *    transaction returns an outcome and the structured exception is thrown AFTER commit.
 *  - Device-slot allocation is serialized with a tenant-row lock, so two DIFFERENT
 *    branches of one tenant cannot both consume the last licensed slot.
 *  - Cross-DB writes are ordered as a saga: the master security mutation commits first,
 *    then the tenant-DB branch lifecycle is reconciled idempotently (never a 500 if the
 *    reconciliation step fails — the security action stays committed).
 *
 * IMPORTANT (honest): max_attempts only bounds failures where a REAL code row was
 * resolved. A completely wrong six-digit guess matches no row and cannot increment any
 * counter — unknown-code guessing is bounded by the IP throttle on the exchange endpoint.
 */
class EdgePairingService
{
    public const CODE_TTL_MINUTES     = 15;
    public const MAX_ATTEMPTS         = 5;
    /** Fail-closed default when the plan carries no explicit offline_edge device limit. */
    public const DEFAULT_DEVICE_LIMIT = 1;
    public const DEVICE_LIMIT_KEY     = 'max_active_edge_devices';
    private const CODE_GEN_RETRIES    = 40;

    public function __construct(
        private readonly OfflineEdgeEntitlementService $entitlement,
        private readonly BranchOperatingModeService $mode,
        private readonly TenancyManager $tenancy,
    ) {}

    /* ── hashing ─────────────────────────────────────────────────────────── */

    public function digest(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    public function secretHash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /* ── device limit ────────────────────────────────────────────────────── */

    public function deviceLimit(Tenant $tenant): int
    {
        $plan = $tenant->subscription?->loadMissing('plan')->plan;
        if (! $plan) {
            return self::DEFAULT_DEVICE_LIMIT;
        }
        $module = Module::where('key', OfflineEdgeEntitlementService::MODULE_KEY)->first();
        $pm     = $module ? PlanModule::where('plan_id', $plan->id)->where('module_id', $module->id)->first() : null;
        $limit  = $pm?->limits[self::DEVICE_LIMIT_KEY] ?? null;

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

    public function generateCode(Tenant $tenant, Branch $branch, int $userId): array
    {
        // Race-safe slot allocation + single-live-code creation under a tenant-row lock.
        // No tenant-DB (branch) write happens inside the master transaction.
        $result = DB::connection('master')->transaction(function () use ($tenant, $branch, $userId) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            if ($this->activeDeviceForBranch($tenant->id, $branch->id)) {
                return ['fail' => EdgePairingException::CODE_DEVICE_CONFLICT];
            }
            if ($this->activeDeviceCount($tenant->id) >= $this->deviceLimit($tenant)) {
                return ['fail' => EdgePairingException::CODE_DEVICE_LIMIT];
            }

            // HARDEN-2: never silently replace a live code. An existing active_slot=1 code
            // that is still UNEXPIRED wins → the owner must Cancel it first (controlled 409).
            // Only an already-EXPIRED live-slot code is burned so the owner isn't stuck.
            $existing = EdgePairingCode::where('tenant_id', $tenant->id)
                ->where('branch_id', $branch->id)
                ->where('active_slot', EdgePairingCode::ACTIVE_SLOT)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (! $existing->isExpired()) {
                    return ['fail' => EdgePairingException::CODE_ALREADY_ACTIVE];
                }
                $existing->update(['active_slot' => null]);   // dead code — clear the slot
            }

            [$code, $row] = $this->createLiveCode($tenant, $branch, $userId);

            return ['ok' => $row, 'code' => $code];
        });

        if (isset($result['fail'])) {
            throw EdgePairingException::of($result['fail']);
        }

        /** @var EdgePairingCode $row */
        $row  = $result['ok'];
        $code = $result['code'];

        // Saga step 2 (tenant DB): move a cloud/inactive branch to pending. Compensation:
        // if the branch transition fails, burn the just-created code so we never leave a
        // live code on a branch that stayed cloud/inactive.
        if ($branch->local_edge_status === Branch::STATUS_INACTIVE) {
            try {
                $this->mode->transition($branch, Branch::STATUS_PENDING, $userId, 'edge_pairing_code_generated');
            } catch (\Throwable $e) {
                EdgePairingCode::whereKey($row->id)->update(['active_slot' => null, 'cancelled_at' => now()]);
                Log::warning('[edge-pairing-audit] edge.pairing_code.compensated', [
                    'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'reason' => 'branch_transition_failed',
                ]);
                throw $e;
            }
        }

        $this->audit('edge.pairing_code.generated', [
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
            'code_uuid' => $row->public_uuid, 'user_id' => $userId,
        ]);

        return [
            'code'        => $code,
            'public_uuid' => $row->public_uuid,
            'expires_at'  => $row->expires_at->toIso8601String(),
            'branch_id'   => $branch->id,
        ];
    }

    /** Create exactly one live code; retry on the DB live-code-uniqueness collision. */
    private function createLiveCode(Tenant $tenant, Branch $branch, int $userId): array
    {
        for ($i = 0; $i < self::CODE_GEN_RETRIES; $i++) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            try {
                $row = EdgePairingCode::create([
                    'public_uuid'        => (string) Str::uuid(),
                    'tenant_id'          => $tenant->id,
                    'branch_id'          => $branch->id,
                    'code_hash'          => $this->digest($code),
                    'attempts'           => 0,
                    'max_attempts'       => self::MAX_ATTEMPTS,
                    'expires_at'         => now()->addMinutes(self::CODE_TTL_MINUTES),
                    'active_slot'        => EdgePairingCode::ACTIVE_SLOT,
                    'created_by_user_id' => $userId,
                ]);

                return [$code, $row];
            } catch (UniqueConstraintViolationException $e) {
                // Another live code already uses this six-digit value — try a fresh number.
                continue;
            }
        }

        // Astronomically unlikely with a 10^6 space and few live codes; fail controlled.
        throw EdgePairingException::of(EdgePairingException::CODE_INVALID);
    }

    /* ── cancel (tenant, authenticated — SECURITY action, no entitlement dep) ─ */

    public function cancelCode(Tenant $tenant, Branch $branch, int $userId): void
    {
        // Idempotent: cancelling again simply updates zero live rows.
        EdgePairingCode::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->where('active_slot', EdgePairingCode::ACTIVE_SLOT)
            ->update(['active_slot' => null, 'cancelled_at' => now()]);

        $this->reconcileBranch($tenant, $branch->id, $userId, 'edge_pairing_cancelled');

        $this->audit('edge.pairing_code.cancelled', [
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'user_id' => $userId,
        ]);
    }

    /* ── exchange (public API) — outcome-based so failure state COMMITS ────── */

    public function exchange(array $in): array
    {
        $code = preg_replace('/\D+/', '', (string) ($in['code'] ?? ''));
        if (strlen((string) $code) !== 6) {
            throw EdgePairingException::of(EdgePairingException::CODE_INVALID);
        }
        $installationUuid = (string) ($in['installation_uuid'] ?? '');
        $deviceSecretHash = strtolower((string) ($in['device_secret_hash'] ?? ''));

        // Resolve tenant from the code (unlocked pre-read) so we can activate before the txn.
        $pcPre = EdgePairingCode::where('code_hash', $this->digest($code))->orderByDesc('id')->first();
        if (! $pcPre) {
            throw EdgePairingException::of(EdgePairingException::CODE_INVALID);
        }
        $tenant = Tenant::find($pcPre->tenant_id);
        if (! $tenant) {
            throw EdgePairingException::of(EdgePairingException::CODE_INVALID);
        }

        $this->tenancy->activate($tenant);   // tenant DB + app('tenant') for entitlement/branch reads

        try {
            $outcome = DB::connection('master')->transaction(function () use ($code, $installationUuid, $deviceSecretHash, $in, $tenant) {
                // Serialize slot allocation across all branches of this tenant.
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();

                $pc = EdgePairingCode::where('code_hash', $this->digest($code))
                    ->orderByDesc('id')->lockForUpdate()->first();
                if (! $pc) {
                    return ['fail' => EdgePairingException::CODE_INVALID];
                }

                // Used code → idempotent replay only for the SAME device; else conflict.
                if ($pc->used_at !== null) {
                    $device = $pc->paired_device_id ? EdgeDevice::find($pc->paired_device_id) : null;
                    if ($device && ! $device->isRevoked()
                        && $device->installation_uuid === $installationUuid
                        && hash_equals($device->device_secret_hash, $deviceSecretHash)) {
                        return ['ok' => $this->deviceMeta($device)];
                    }
                    return ['fail' => EdgePairingException::CODE_USED];
                }

                if ($pc->cancelled_at !== null) {
                    return ['fail' => EdgePairingException::CODE_INVALID];
                }
                if ($pc->isExpired()) {
                    $pc->update(['active_slot' => null]);           // burn — COMMITS
                    return ['fail' => EdgePairingException::CODE_EXPIRED];
                }
                if ($pc->isExhausted()) {
                    $pc->update(['active_slot' => null]);           // burn — COMMITS
                    return ['fail' => EdgePairingException::CODE_EXHAUSTED];
                }

                // Re-check entitlement + rollout at EXCHANGE time (never trust generation-time state).
                if (! $this->entitlement->featureIsEnabled() || ! $this->entitlement->tenantHasOfflineEdgeAccess()) {
                    $this->burnAttempt($pc);
                    return ['fail' => EdgePairingException::CODE_ENTITLEMENT];
                }

                // Branch must already be pending (generation moved it there — exchange never transitions it).
                $branch = Branch::find($pc->branch_id);
                if (! $branch || $branch->local_edge_status !== Branch::STATUS_PENDING) {
                    $this->burnAttempt($pc);
                    return ['fail' => EdgePairingException::CODE_DEVICE_CONFLICT];
                }

                // Existing active device on this branch → replay (same device) or conflict.
                $existing = $this->activeDeviceForBranch($pc->tenant_id, $pc->branch_id);
                if ($existing) {
                    if ($existing->installation_uuid === $installationUuid
                        && hash_equals($existing->device_secret_hash, $deviceSecretHash)) {
                        $pc->update(['used_at' => now(), 'paired_device_id' => $existing->id, 'active_slot' => null]);
                        return ['ok' => $this->deviceMeta($existing)];
                    }
                    return ['fail' => EdgePairingException::CODE_DEVICE_CONFLICT];
                }

                // This installation must not already be ACTIVE elsewhere (unique installation+active_slot).
                $instActive = EdgeDevice::active()->where('installation_uuid', $installationUuid)->first();
                if ($instActive) {
                    return ['fail' => EdgePairingException::CODE_DEVICE_CONFLICT];
                }

                // Licensed tenant-wide cap (checked under the tenant lock).
                if ($this->activeDeviceCount($pc->tenant_id) >= $this->deviceLimit($tenant)) {
                    $this->burnAttempt($pc);
                    return ['fail' => EdgePairingException::CODE_DEVICE_LIMIT];
                }

                try {
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
                } catch (UniqueConstraintViolationException $e) {
                    // A concurrent winner already created the active device for this branch/installation.
                    return ['fail' => EdgePairingException::CODE_DEVICE_CONFLICT];
                }

                $pc->update(['used_at' => now(), 'paired_device_id' => $device->id, 'active_slot' => null]);

                $this->audit('edge.device.paired', [
                    'tenant_id' => $pc->tenant_id, 'branch_id' => $pc->branch_id,
                    'device_uuid' => $device->public_uuid,
                ]);

                return ['ok' => $this->deviceMeta($device)];
            });
        } finally {
            $this->tenancy->deactivate();
        }

        if (isset($outcome['fail'])) {
            throw EdgePairingException::of($outcome['fail']);
        }

        return $outcome['ok'];
    }

    /** Increment a resolved code's failed-attempt counter; burn it once exhausted. Commits. */
    private function burnAttempt(EdgePairingCode $pc): void
    {
        $pc->increment('attempts');
        if ($pc->attempts >= $pc->max_attempts) {
            $pc->update(['active_slot' => null]);
        }
    }

    /* ── revoke (tenant, authenticated — SECURITY action, no entitlement dep) ─ */

    public function revokeDevice(Tenant $tenant, EdgeDevice $device, int $userId, ?string $reason = null): void
    {
        // Saga step 1 (master): kill device auth first. Idempotent — a re-revoke still
        // proceeds to reconcile the branch (repairs any earlier reconciliation drift).
        if (! $device->isRevoked()) {
            $device->update([
                'status'             => EdgeDevice::STATUS_REVOKED,
                'active_slot'        => null,      // frees the slot + immediately kills auth
                'revoked_at'         => now(),
                'revoked_by_user_id' => $userId,
                'revoke_reason'      => $reason ? Str::limit($reason, 190, '') : 'revoked_by_owner',
            ]);
        }

        // Saga step 2 (tenant DB): reconcile branch lifecycle idempotently. Never a 500 —
        // if this fails the security revocation stays committed and we log reconciliation-required.
        $this->reconcileBranch($tenant, $device->branch_id, $userId, 'edge_device_revoked');

        $this->audit('edge.device.revoked', [
            'tenant_id' => $tenant->id, 'branch_id' => $device->branch_id,
            'device_uuid' => $device->public_uuid, 'user_id' => $userId,
        ]);
    }

    /**
     * Idempotent branch reconciliation, driven purely by current state (safe to retry):
     *   active|closing + no active device        → suspended (never straight to inactive)
     *   pending + no active device + no live code → inactive
     * Failures are logged as reconciliation-required and swallowed (security action stays committed).
     */
    private function reconcileBranch(Tenant $tenant, int $branchId, int $userId, string $reason): void
    {
        try {
            $branch = Branch::find($branchId);
            if (! $branch) {
                $this->resolveMarker($tenant->id, $branchId);   // nothing to reconcile
                return;
            }
            $hasActiveDevice = (bool) $this->activeDeviceForBranch($tenant->id, $branchId);
            $hasLiveCode     = (bool) $this->liveCodeForBranch($tenant->id, $branchId);

            if (in_array($branch->local_edge_status, [Branch::STATUS_ACTIVE, Branch::STATUS_CLOSING], true) && ! $hasActiveDevice) {
                $this->mode->transition($branch, Branch::STATUS_SUSPENDED, $userId, $reason);
            } elseif ($branch->local_edge_status === Branch::STATUS_PENDING && ! $hasActiveDevice && ! $hasLiveCode) {
                $this->mode->transition($branch, Branch::STATUS_INACTIVE, $userId, $reason);
            }

            // Success (including a legitimate no-op) → clear any pending marker for this branch.
            $this->resolveMarker($tenant->id, $branchId);
        } catch (\Throwable $e) {
            // The security mutation is already committed; persist a DURABLE marker so a later
            // retry / job can finish the lifecycle. Never a 500.
            $this->persistMarker($tenant->id, $branchId, $reason, substr($e->getMessage(), 0, 500));
            Log::warning('[edge-pairing-audit] edge.branch.reconciliation_required', [
                'tenant_id' => $tenant->id, 'branch_id' => $branchId,
                'reason' => $reason, 'error' => substr($e->getMessage(), 0, 190),
            ]);
        }
    }

    private function persistMarker(int $tenantId, int $branchId, string $operation, string $error): void
    {
        try {
            $marker = \App\Models\Master\EdgeReconciliationMarker::firstOrNew([
                'tenant_id' => $tenantId, 'branch_id' => $branchId,
            ]);
            $marker->operation  = $operation;
            $marker->status     = \App\Models\Master\EdgeReconciliationMarker::STATUS_PENDING;
            $marker->attempts   = (int) $marker->attempts + 1;
            $marker->last_error = $error;
            $marker->resolved_at = null;
            $marker->save();
        } catch (\Throwable $e) {
            Log::error('[edge-pairing-audit] edge.branch.marker_persist_failed', [
                'tenant_id' => $tenantId, 'branch_id' => $branchId, 'error' => substr($e->getMessage(), 0, 190),
            ]);
        }
    }

    private function resolveMarker(int $tenantId, int $branchId): void
    {
        try {
            \App\Models\Master\EdgeReconciliationMarker::where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->delete();
        } catch (\Throwable $e) {
            // best-effort cleanup only
        }
    }

    /* ── shared ──────────────────────────────────────────────────────────── */

    public function deviceMeta(EdgeDevice $device): array
    {
        $tenant     = Tenant::find($device->tenant_id);
        $branchName = null;
        if (app()->bound('tenant') && app('tenant')->id === $device->tenant_id) {
            $branchName = optional(Branch::find($device->branch_id))->name;
        }

        return [
            'device_id'      => $device->public_uuid,
            'public_uuid'    => $device->public_uuid,
            'tenant_code'    => $tenant?->tenant_code,
            'branch_id'      => $device->branch_id,
            'branch_name'    => $branchName,
            'device_status'  => $device->status,
            'cloud_base_url' => rtrim((string) config('app.url'), '/'),
            'paired_at'      => optional($device->paired_at)->toIso8601String(),
        ];
    }

    public function audit(string $event, array $ctx = []): void
    {
        Log::info("[edge-pairing-audit] {$event}", $ctx);
    }
}
