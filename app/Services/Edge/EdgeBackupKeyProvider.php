<?php

namespace App\Services\Edge;

/**
 * OFFLINE EDGE PRODUCTIZATION — the appliance BACKUP recovery-key contract.
 *
 * A backup must be recoverable on a REPLACEMENT machine after the original appliance (and its Laravel
 * APP_KEY) is gone. So backups are NOT encrypted with APP_KEY. Each backup carries a random per-backup
 * data-encryption key (DEK) that is wrapped by a recovery WRAPPING KEY resolved through this contract — a
 * key provisioned per branch/appliance and independently recoverable (from the Cloud recovery authority),
 * never bound to one machine's APP_KEY.
 *
 * The wrapping key is identified by a key_id stamped into the backup, so keys can rotate and older backups
 * stay recoverable with their retained key. An unknown/revoked key_id fails closed — never a plaintext
 * fallback. A real Cloud KMS / key-vault implementation slots in behind this interface later.
 */
interface EdgeBackupKeyProvider
{
    /** The key_id new backups are wrapped under. */
    public function currentKeyId(): string;

    /**
     * The raw 32-byte wrapping key for the given key_id (or the current one). Throws when the key_id is
     * unknown/revoked or the material is malformed — never returns a fallback.
     */
    public function wrappingKey(?string $keyId = null): string;
}
