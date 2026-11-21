<?php

namespace App\Services\Edge;

use App\Support\EdgeApplianceEnvFile;
use RuntimeException;

/**
 * P5B §3 — persists Cloud-issued backup recovery material into the appliance's ONLY secrets file (appliance.env)
 * and refreshes the running configuration, so backups seal under the escrowed key and a replacement machine can
 * open the dead appliance's backups. Retired keys are UNIONED (a key already on file is never dropped: a backup
 * sealed under it may still exist). Returns key ids only — never material.
 */
class EdgeRecoveryKeyProvisioner
{
    public function __construct(private readonly EdgeRecoveryKeyClient $client)
    {
    }

    /** Fetch from the Cloud authority and apply. @return array{key_id:string,retired_key_ids:array<int,string>,env_file:string} */
    public function pull(string $cloudBaseUrl, string $envFile, ?string $forKeyId = null): array
    {
        return $this->apply($this->client->fetch($cloudBaseUrl, $forKeyId), $envFile);
    }

    /** Apply already-fetched material (the in-process proof path). */
    public function apply(array $material, string $envFile): array
    {
        $keyId = (string) ($material['key_id'] ?? '');
        $key = (string) ($material['key'] ?? '');
        if ($keyId === '' || $key === '') {
            throw new RuntimeException('RECOVERY_MATERIAL_INVALID: no current key.');
        }
        $retired = [];
        foreach ((array) ($material['retired'] ?? []) as $id => $k) {
            if (is_string($id) && is_string($k) && $id !== $keyId) {
                $retired[$id] = $k;
            }
        }
        // Union with what the file already holds (never drop a key a local backup may be sealed under).
        $fileRetired = json_decode((string) (EdgeApplianceEnvFile::get($envFile, 'EDGE_BACKUP_RETIRED_KEYS') ?? '{}'), true);
        foreach (is_array($fileRetired) ? $fileRetired : [] as $id => $k) {
            if (is_string($id) && is_string($k) && $id !== $keyId && ! isset($retired[$id])) {
                $retired[$id] = $k;
            }
        }
        $fileCurrentId = (string) (EdgeApplianceEnvFile::get($envFile, 'EDGE_BACKUP_RECOVERY_KEY_ID') ?? '');
        $fileCurrent = (string) (EdgeApplianceEnvFile::get($envFile, 'EDGE_BACKUP_RECOVERY_KEY') ?? '');
        if ($fileCurrentId !== '' && $fileCurrent !== '' && $fileCurrentId !== $keyId && ! isset($retired[$fileCurrentId])) {
            $retired[$fileCurrentId] = $fileCurrent; // a previously provisioned (or locally supplied) key becomes retired, not lost
        }
        EdgeApplianceEnvFile::set($envFile, [
            'EDGE_BACKUP_RECOVERY_KEY' => $key,
            'EDGE_BACKUP_RECOVERY_KEY_ID' => $keyId,
            'EDGE_BACKUP_RETIRED_KEYS' => json_encode($retired, JSON_UNESCAPED_SLASHES),
        ]);
        config([
            'edge.backup.recovery_key' => $key,
            'edge.backup.recovery_key_id' => $keyId,
            'edge.backup.retired_keys' => $retired,
        ]);

        return ['key_id' => $keyId, 'retired_key_ids' => array_keys($retired), 'env_file' => $envFile];
    }
}
