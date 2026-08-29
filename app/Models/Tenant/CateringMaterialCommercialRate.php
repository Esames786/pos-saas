<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * KASHIF-CATERING-COMMERCIAL-RATE-1 — what a material is CHARGED at.
 *
 * Not what it costs. CateringMaterialRate answers that, and the two move for
 * entirely different reasons: the cost follows the market, the charge follows a
 * commercial decision. A caterer may charge 100 for chicken that costs 80, or
 * 90 in a wedding package, and neither figure tells you the other.
 *
 * Effective-dated. Raising the house rate writes a new row; the old one stays,
 * because a quotation priced at 100 last month must remain explicable.
 *
 * This is a RECOMMENDATION, never an instruction. A dish keeps the rate that was
 * applied to it until somebody deliberately applies a new one through Rate
 * Impact — nothing here reprices anything on its own.
 */
class CateringMaterialCommercialRate extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'product_id',
        'rate',
        'unit_id',
        'effective_from',
        'created_by_user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'effective_from' => 'date',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * The house commercial rate for a material as at a date — the latest row
     * that had come into effect by then.
     */
    public static function effectiveFor(int $productId, ?string $asOfDate = null): ?self
    {
        return static::query()
            ->where('product_id', $productId)
            ->whereDate('effective_from', '<=', $asOfDate ?: now()->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /** Convenience: just the number, or null when the material has no house rate. */
    public static function rateFor(int $productId, ?string $asOfDate = null): ?float
    {
        $row = static::effectiveFor($productId, $asOfDate);

        return $row === null ? null : (float) $row->rate;
    }
}
