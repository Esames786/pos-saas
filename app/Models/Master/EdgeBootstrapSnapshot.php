<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BRANCH-BOOTSTRAP-SNAPSHOT-1 — an immutable branch-scoped bootstrap snapshot (master).
 */
class EdgeBootstrapSnapshot extends Model
{
    protected $connection = 'master';

    public const STATUS_BUILDING     = 'building';
    public const STATUS_READY        = 'ready';
    public const STATUS_DOWNLOADED   = 'downloaded';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_FAILED       = 'failed';
    public const STATUS_EXPIRED      = 'expired';

    protected $fillable = [
        'public_uuid', 'tenant_id', 'branch_id', 'edge_device_id', 'schema_version', 'status',
        'source_revision', 'manifest_hash', 'section_summary', 'generated_at', 'expires_at',
        'downloaded_at', 'acknowledged_at', 'failure_code', 'last_error',
    ];

    protected $casts = [
        'section_summary' => 'array',
        'generated_at'    => 'datetime',
        'expires_at'      => 'datetime',
        'downloaded_at'   => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function sections(): HasMany
    {
        return $this->hasMany(EdgeBootstrapSnapshotSection::class, 'snapshot_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isReusable(): bool
    {
        return in_array($this->status, [self::STATUS_READY, self::STATUS_DOWNLOADED, self::STATUS_ACKNOWLEDGED], true)
            && ! $this->isExpired();
    }
}
