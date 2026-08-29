<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * CATERING-GO-LIVE-READINESS-1 (§7): immutable record of an authorized
 * material issue for a production release. All stock movement behind it went
 * through InventoryService::postOutFefo (never direct table writes); one
 * issue per release (unique) makes retries idempotent. Only the write-once
 * COGS journal linkage may be set after creation.
 */
class CateringMaterialIssue extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    protected string $canonicalIdentityColumn = 'issue_uuid';

    public const STATUS_ISSUED = 'issued';

    protected $fillable = [
        'issue_no',
        'catering_production_release_id',
        'catering_event_id',
        'branch_id',
        'status',
        'total_fefo_cost',
        'cogs_journal_entry_id',
        'issued_at',
        'issued_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'total_fefo_cost' => 'decimal:4',
            'issued_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CateringMaterialIssue $issue) {
            foreach (array_keys($issue->getDirty()) as $column) {
                if ($column === 'updated_at') {
                    continue;
                }
                if ($column !== 'cogs_journal_entry_id' || $issue->getOriginal('cogs_journal_entry_id') !== null) {
                    throw new RuntimeException('A catering material issue is an immutable stock document.');
                }
            }
        });
    }

    public function release()
    {
        return $this->belongsTo(CateringProductionRelease::class, 'catering_production_release_id');
    }

    /**
     * LEGACY. The first booking this issue was recorded against.
     *
     * Kept because a rollback of the many-booking migration can only rebuild
     * from this column, and because anything still reading it should see a real
     * booking rather than nothing. New code reads events() below — this one
     * cannot answer "which bookings", only "one of them".
     */
    public function event()
    {
        return $this->belongsTo(CateringEvent::class, 'catering_event_id');
    }

    /**
     * KASHIF-CATERING-STORE-2 — every booking this issue was made for.
     *
     * The authoritative reference model. One trip to the store may cover twelve
     * weddings, and all twelve are the reason the stock left.
     *
     * These are REFERENCES, not allocations. This relation says eighty kilos
     * went out for these twelve bookings; it does not say, and must never be
     * read as saying, how much each of them took.
     */
    public function events()
    {
        return $this->belongsToMany(
            CateringEvent::class,
            'catering_material_issue_events',
            'catering_material_issue_id',
            'catering_event_id'
        )->withTimestamps();
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines()
    {
        return $this->hasMany(CateringMaterialIssueLine::class);
    }
}
