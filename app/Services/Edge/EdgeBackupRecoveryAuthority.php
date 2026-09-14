<?php

namespace App\Services\Edge;

use App\Models\Master\EdgeBackupRecoveryAudit;
use App\Models\Master\EdgeBackupRecoveryKey;
use App\Models\Master\EdgeDevice;
use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * P5B §3 — the CLOUD BACKUP RECOVERY AUTHORITY (hosted by the Cloud only; physically excluded from the appliance).
 *
 * Contract:
 *   - every branch has ONE active 32-byte backup wrapping key, issued here and escrowed in the master DB encrypted
 *     under the Cloud APP_KEY (Crypt). The appliance seals every backup's DEK under it (EdgeBackupService) and
 *     stamps the key_id;
 *   - an ACTIVE, PAIRED device of (tenant, branch) may retrieve the material of ITS OWN branch — the current key
 *     plus every retired key of that branch — so a replacement appliance opens the dead appliance's backups after a
 *     supervised pairing. Scope comes from the device row, never from request input; another branch's key_id is
 *     refused (and audited) without revealing whether it exists;
 *   - rotation RETIRES (never deletes) the active key and issues a fresh one; a device revocation rotates, so a
 *     leaked appliance secret cannot read FUTURE backups while older backups remain recoverable by the next
 *     authorized device;
 *   - every issue / retrieval / rotation / refusal is audited with actor, scope and key_id — never material;
 *   - nothing here logs key material; the cashier/operator UI never sees it (the appliance holds it in the
 *     ACL-protected appliance.env only).
 *
 * Threat model (documented, not hidden): the escrow's root of trust is the Cloud APP_KEY + master DB access control.
 * A compromise of BOTH exposes every branch's backup material; the upgrade path is to move wrap()/unwrap() to a
 * KMS/HSM without changing the appliance contract. A stolen device secret is bounded by revocation (401 + rotation).
 */
final class EdgeBackupRecoveryAuthority
{
    public const KEY_BYTES = 32;

    /** The current active key of a branch, issuing the first one on demand. ['key_id' => …, 'key' => base64]. */
    public function current(int $tenantId, int $branchId, string $actor, ?string $ip = null, string $reason = 'first_use'): array
    {
        $this->assertCloud();
        $row = EdgeBackupRecoveryKey::forBranch($tenantId, $branchId)->where('status', EdgeBackupRecoveryKey::STATUS_ACTIVE)->orderByDesc('id')->first();
        if (! $row) {
            $row = $this->issue($tenantId, $branchId, $actor, $ip, $reason);
        }

        return ['key_id' => $row->key_id, 'key' => $this->unwrap($row)];
    }

    /** Everything an authorized device of (tenant, branch) needs: its branch's current key + retired keys. */
    public function materialForDevice(EdgeDevice $device, ?string $ip = null): array
    {
        $this->assertCloud();
        $tenantId = (int) $device->tenant_id;
        $branchId = (int) $device->branch_id;
        $actor = 'device:' . $device->public_uuid;
        if ($device->isRevoked() || (int) $device->active_slot !== EdgeDevice::ACTIVE_SLOT) {
            $this->audit($tenantId, $branchId, 'refused', 'refused', null, $actor, 'RECOVERY_DEVICE_NOT_ACTIVE', $ip);
            throw new RuntimeException('RECOVERY_DEVICE_NOT_ACTIVE: only the active paired device of the branch may retrieve recovery material.');
        }
        $current = $this->current($tenantId, $branchId, $actor, $ip);
        $retired = [];
        foreach (EdgeBackupRecoveryKey::forBranch($tenantId, $branchId)->where('status', EdgeBackupRecoveryKey::STATUS_RETIRED)->orderBy('id')->get() as $row) {
            $retired[$row->key_id] = $this->unwrap($row);
        }
        $this->audit($tenantId, $branchId, 'retrieved', 'ok', $current['key_id'], $actor, 'retired_keys=' . count($retired), $ip);

        return ['tenant_id' => $tenantId, 'branch_id' => $branchId, 'current' => $current, 'retired' => $retired];
    }

    /** A device may only ask for key ids of ITS branch; anything else is refused + audited without disclosure. */
    public function assertKeyInScope(EdgeDevice $device, string $keyId, ?string $ip = null): void
    {
        $this->assertCloud();
        $row = EdgeBackupRecoveryKey::where('key_id', $keyId)->first();
        if (! $row || (int) $row->tenant_id !== (int) $device->tenant_id || (int) $row->branch_id !== (int) $device->branch_id) {
            $this->audit((int) $device->tenant_id, (int) $device->branch_id, 'refused', 'refused', Str::limit($keyId, 40, ''), 'device:' . $device->public_uuid, 'RECOVERY_KEY_SCOPE', $ip);
            throw new RuntimeException('RECOVERY_KEY_NOT_FOUND: no recovery key with that id is available to this device.');
        }
    }

    /** Rotate: retire the active key (older backups stay recoverable under it) and issue a fresh one. */
    public function rotate(int $tenantId, int $branchId, string $actor, string $reason, ?string $ip = null): array
    {
        $this->assertCloud();

        return DB::connection('master')->transaction(function () use ($tenantId, $branchId, $actor, $reason, $ip) {
            EdgeBackupRecoveryKey::forBranch($tenantId, $branchId)->where('status', EdgeBackupRecoveryKey::STATUS_ACTIVE)->update([
                'status' => EdgeBackupRecoveryKey::STATUS_RETIRED,
                'retired_at' => now(),
                'retire_reason' => Str::limit($reason, 190, ''),
                'updated_at' => now(),
            ]);
            $row = $this->issue($tenantId, $branchId, $actor, $ip, $reason);
            $this->audit($tenantId, $branchId, 'rotated', 'ok', $row->key_id, $actor, Str::limit($reason, 190, ''), $ip);

            return ['key_id' => $row->key_id];
        });
    }

    /** Status without material: the active key id, the retired ids and the latest audit rows. */
    public function status(int $tenantId, int $branchId, int $auditRows = 10): array
    {
        $this->assertCloud();
        $rows = EdgeBackupRecoveryKey::forBranch($tenantId, $branchId)->orderBy('id')->get();
        $active = $rows->firstWhere('status', EdgeBackupRecoveryKey::STATUS_ACTIVE);

        return [
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'active_key_id' => $active ? $active->key_id : null,
            'retired_key_ids' => $rows->where('status', EdgeBackupRecoveryKey::STATUS_RETIRED)->pluck('key_id')->values()->all(),
            'audits' => EdgeBackupRecoveryAudit::where('tenant_id', $tenantId)->where('branch_id', $branchId)->orderByDesc('id')->limit($auditRows)->get()
                ->map(fn ($a) => [
                    'at' => $a->created_at ? $a->created_at->toIso8601String() : null,
                    'action' => $a->action, 'outcome' => $a->outcome, 'key_id' => $a->key_id, 'actor' => $a->actor, 'detail' => $a->detail,
                ])
                ->all(),
        ];
    }

    private function issue(int $tenantId, int $branchId, string $actor, ?string $ip, string $reason): EdgeBackupRecoveryKey
    {
        $material = random_bytes(self::KEY_BYTES);
        $row = EdgeBackupRecoveryKey::create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'key_id' => 'brk_' . strtolower((string) Str::ulid()),
            'key_ciphertext' => $this->wrap($material),
            'status' => EdgeBackupRecoveryKey::STATUS_ACTIVE,
            'issued_by' => Str::limit($actor, 190, ''),
            'issue_reason' => Str::limit($reason, 190, ''),
        ]);
        sodium_memzero($material);
        $this->audit($tenantId, $branchId, 'issued', 'ok', $row->key_id, $actor, Str::limit($reason, 190, ''), $ip);

        return $row;
    }

    /** Escrow encryption: the Cloud APP_KEY (Crypt). Swap this pair for a KMS/HSM without touching the contract. */
    private function wrap(string $material): string
    {
        return Crypt::encryptString(base64_encode($material));
    }

    private function unwrap(EdgeBackupRecoveryKey $row): string
    {
        $b64 = Crypt::decryptString((string) $row->key_ciphertext);
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) !== self::KEY_BYTES) {
            throw new RuntimeException('RECOVERY_ESCROW_CORRUPT: escrowed material for key_id [' . $row->key_id . '] is not 32 bytes.');
        }

        return $b64;
    }

    private function audit(?int $tenantId, ?int $branchId, string $action, string $outcome, ?string $keyId, string $actor, ?string $detail, ?string $ip): void
    {
        EdgeBackupRecoveryAudit::create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'action' => $action,
            'outcome' => $outcome,
            'key_id' => $keyId,
            'actor' => Str::limit($actor, 190, ''),
            'detail' => $detail !== null ? Str::limit($detail, 190, '') : null,
            'ip' => $ip !== null ? Str::limit($ip, 45, '') : null,
            'created_at' => now(),
        ]);
    }

    private function assertCloud(): void
    {
        if (EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('RECOVERY_AUTHORITY_CLOUD_ONLY: the backup recovery authority is hosted by the Cloud, never by a Branch Server.');
        }
    }
}
