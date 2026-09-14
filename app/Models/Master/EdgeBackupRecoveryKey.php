<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * P5B §3 — an escrowed per-branch backup wrapping key (Cloud recovery authority; master DB).
 * `key_ciphertext` is the Cloud-APP_KEY-encrypted base64 key — hidden from serialization, never logged.
 */
class EdgeBackupRecoveryKey extends Model
{
    protected $connection = 'master';

    protected $table = 'edge_backup_recovery_keys';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_RETIRED = 'retired';

    protected $fillable = [
        'tenant_id', 'branch_id', 'key_id', 'key_ciphertext', 'status', 'issued_by', 'issue_reason', 'retire_reason', 'retired_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'branch_id' => 'integer',
        'retired_at' => 'datetime',
    ];

    protected $hidden = ['key_ciphertext'];

    public function scopeForBranch($query, int $tenantId, int $branchId)
    {
        return $query->where('tenant_id', $tenantId)->where('branch_id', $branchId);
    }
}
