<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * EDGE 0.9 (K6) — a SIGNED, VERIFIED Branch Server package the Cloud may hand out (setup download + auto-update). Rows
 * are written only by `php artisan edge:release:publish` after the package passed the integrity/boundary audit and the
 * Ed25519 update-signature check against the configured update public key.
 */
class EdgeRelease extends Model
{
    protected $connection = 'master';

    public const CHANNEL_STABLE = 'stable';

    public const STATUS_PUBLISHED = 'published';
    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'public_uuid', 'channel', 'version', 'status', 'manifest_sha256', 'package_hash', 'zip_sha256', 'size_bytes',
        'signing_key_id', 'min_from_version', 'schema_generation', 'source_revision', 'update_package_file', 'storage_path',
        'file_count', 'published_by', 'published_at', 'withdrawn_at',
    ];

    protected $casts = [
        'size_bytes'   => 'integer',
        'file_count'   => 'integer',
        'published_at' => 'datetime',
        'withdrawn_at' => 'datetime',
    ];

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }
}
