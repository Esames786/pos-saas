<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * OFFLINE EDGE — the Cloud-side per-branch mutation lease (P0 single-writer authority).
 * Timestamps are the CLOUD clock; the appliance never compares them with its own.
 */
class EdgeBranchAuthorityLease extends Model
{
    protected $connection = 'tenant';

    protected $table = 'edge_branch_authority_leases';

    public const HOLDER_CLOUD = 'cloud';
    public const HOLDER_EDGE = 'edge';

    public const EDGE_STANDBY = 'standby';
    public const EDGE_LOCAL_ACTIVE = 'local_active';
    public const EDGE_HANDING_BACK = 'handing_back';

    protected $guarded = [];

    protected $casts = [
        'branch_id' => 'integer',
        'heartbeat_seq' => 'integer',
        'lease_ttl_seconds' => 'integer',
        'last_heartbeat_at' => 'datetime',
        'expires_at' => 'datetime',
        'fenced_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->lte(now());
    }
}
