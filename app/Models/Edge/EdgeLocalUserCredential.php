<?php

namespace App\Models\Edge;

use Illuminate\Database\Eloquent\Model;

/**
 * EDGE-LOCAL-AUTH-1 — the Branch Server's Edge-SPECIFIC credential verifier for a bootstrapped user
 * (Argon2id hash; never the Cloud users.password). Bound to the appliance's activation epoch.
 */
class EdgeLocalUserCredential extends Model
{
    protected $connection = 'tenant';

    protected $table = 'edge_local_user_credentials';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public const TYPE_PASSWORD = 'password';
    public const TYPE_PIN = 'pin';

    protected $guarded = [];

    protected $hidden = ['credential_hash'];

    protected $casts = [
        'user_id' => 'integer',
        'branch_id' => 'integer',
        'activation_epoch' => 'integer',
        'credential_version' => 'integer',
        'failed_attempts' => 'integer',
        'locked_until' => 'datetime',
        'enrolled_at' => 'datetime',
        'last_authenticated_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
