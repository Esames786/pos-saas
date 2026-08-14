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
        'amount',
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
            'amount' => 'decimal:2',
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
