<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * BRANCH-DEVICE-PAIRING-1 — a Bingoo Edge device bound to ONE tenant+branch.
 * Master-DB row (pairing precedes any tenant credentials). The plaintext device
 * secret is NEVER stored — only device_secret_hash = sha256(secret).
 */
class EdgeDevice extends Model
{
    protected $connection = 'master';

    public const STATUS_PAIRED            = 'paired';
    public const STATUS_PENDING_BOOTSTRAP = 'pending_bootstrap';
    public const STATUS_READY             = 'ready';
    public const STATUS_ACTIVE            = 'active';
    public const STATUS_REVOKED           = 'revoked';

    public const ACTIVE_SLOT = 1;

    protected $fillable = [
        'public_uuid', 'tenant_id', 'branch_id', 'installation_uuid', 'device_name',
        'device_secret_hash', 'status', 'active_slot', 'app_version', 'schema_version',
        'paired_at', 'last_authenticated_at', 'revoked_at', 'revoked_by_user_id', 'revoke_reason',
    ];

    protected $casts = [
        'active_slot'           => 'integer',
        'paired_at'             => 'datetime',
        'last_authenticated_at' => 'datetime',
        'revoked_at'            => 'datetime',
    ];

    protected $hidden = ['device_secret_hash'];

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED || $this->revoked_at !== null;
    }

    public function scopeActive($query)
    {
        return $query->where('active_slot', self::ACTIVE_SLOT)
            ->where('status', '!=', self::STATUS_REVOKED);
    }
}
