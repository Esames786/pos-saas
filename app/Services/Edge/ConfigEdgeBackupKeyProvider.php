<?php

namespace App\Services\Edge;

use RuntimeException;

/**
 * OFFLINE EDGE PRODUCTIZATION — a config/env-provisioned EdgeBackupKeyProvider.
 *
 * The recovery wrapping key(s) are provisioned to the appliance out of band (from the Cloud recovery
 * authority, per branch), NOT committed to git and NOT the APP_KEY. This is the interim provider; a real
 * Cloud KMS / key-vault provider implements the same contract later (REAL_RECOVERY_KEY_PROVIDER_REQUIRED).
 *
 *   edge.backup.recovery_key      — base64 of the current 32-byte wrapping key
 *   edge.backup.recovery_key_id   — its id (stamped into every new backup)
 *   edge.backup.retired_keys      — [ key_id => base64 ] retained older keys, for recovering older backups
 */
class ConfigEdgeBackupKeyProvider implements EdgeBackupKeyProvider
{
    public function currentKeyId(): string
    {
        $id = (string) config('edge.backup.recovery_key_id');

        return $id !== '' ? $id : 'k1';
    }

    public function wrappingKey(?string $keyId = null): string
    {
        $id = $keyId ?? $this->currentKeyId();

        if ($id === $this->currentKeyId()) {
            $b64 = (string) config('edge.backup.recovery_key');
        } else {
            $retired = (array) config('edge.backup.retired_keys', []);
            $b64 = (string) ($retired[$id] ?? '');
        }

        if ($b64 === '') {
            throw new RuntimeException("BACKUP_KEY_UNKNOWN: no recovery key is available for key_id [{$id}].");
        }
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException("BACKUP_KEY_INVALID: recovery key [{$id}] must be base64 of 32 bytes.");
        }

        return $raw;
    }
}
