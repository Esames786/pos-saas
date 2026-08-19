<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * CATERING-SLICE-1: one line of a catering estimate. Snapshots the item
 * name/unit so the sent document never drifts with the catalog. Immutable
 * once the parent estimate leaves draft (defence-in-depth guard).
 */
class CateringEstimateLine extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    protected string $canonicalIdentityColumn = 'line_uuid';

    protected $fillable = [
        'catering_estimate_id',
        'product_id',
        'item_name',
        'item_name_ur',
        'quantity',
        'unit_id',
        'unit_code',
        'rate',
        // KASHIF-CATERING-LINE-SNAPSHOT-1: what the blocks worked out, kept
        // beside what is actually being quoted. Null on a line that was never
        // priced from blocks.
        'calculated_rate',
        'rate_override_reason',
        'amount',
        'lump_sum_amount',
        'instructions',
        'estimated_unit_cost',
        'estimated_cost_total',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'rate' => 'decimal:2',
            'calculated_rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'lump_sum_amount' => 'decimal:2',
            'estimated_unit_cost' => 'decimal:4',
            'estimated_cost_total' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (CateringEstimateLine $line): void {
            $status = $line->estimate?->status;
            if ($status !== null && $status !== CateringEstimate::STATUS_DRAFT) {
                throw new RuntimeException(
                    'Catering estimate lines are immutable once the estimate is sent. Create a revision instead.'
                );
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * The cost blocks as they stood when this line was quoted — a copy, not a
     * link. The dish is free to change; what a customer agreed to is not.
     */
    public function costBlocks()
    {
        return $this->hasMany(CateringEstimateLineCostBlock::class, 'catering_estimate_line_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    /** Is this line quoting something other than what its blocks calculated? */
    public function hasQuotedRateOverride(): bool
    {
        return $this->calculated_rate !== null
            && round((float) $this->rate, 2) !== round((float) $this->calculated_rate, 2);
    }

    public function estimate()
    {
        return $this->belongsTo(CateringEstimate::class, 'catering_estimate_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
