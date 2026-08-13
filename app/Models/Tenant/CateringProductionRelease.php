<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * CATERING-SLICE-3: immutable production snapshot document (spec §14).
 * A separate catering business event — NOT a POS KOT; no kot_batches rows,
 * no customer pricing anywhere on the document.
 */
class CateringProductionRelease extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    protected string $canonicalIdentityColumn = 'release_uuid';

    public const STATUS_RELEASED = 'released';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'release_no',
        'catering_event_id',
        'catering_estimate_id',
        'event_snapshot',
        'requirements_snapshot',
        'status',
        'released_at',
        'released_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'event_snapshot' => 'array',
            'requirements_snapshot' => 'array',
            'released_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Immutable snapshot: only a status flip to cancelled is ever allowed.
        static::updating(function (CateringProductionRelease $release) {
            $dirty = array_keys($release->getDirty());
            $allowed = ['status', 'updated_at'];
            if (array_diff($dirty, $allowed) !== []) {
                throw new RuntimeException('A production release is an immutable snapshot; release a new document instead.');
            }
        });
    }

    public function event()
    {
        return $this->belongsTo(CateringEvent::class, 'catering_event_id');
    }

    public function estimate()
    {
        return $this->belongsTo(CateringEstimate::class, 'catering_estimate_id');
    }

    public function lines()
    {
        return $this->hasMany(CateringProductionReleaseLine::class)->orderBy('sort_order');
    }
}
