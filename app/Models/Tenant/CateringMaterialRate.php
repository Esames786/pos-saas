<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * CATERING-SLICE-2: one row of the Catering Material Rate Book — the
 * commercial quote-rate for a raw material, versioned by effective date.
 * Never mutates inventory costs or POS prices.
 */
class CateringMaterialRate extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'product_id',
        'rate',
        'unit_id',
        'effective_from',
        'note',
        'created_by_user_id',
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
        return $this->belongsTo(Product::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    /** The rate effective for a product on a given date (latest effective_from <= date). */
    public static function effectiveFor(int $productId, ?string $date = null): ?self
    {
        return static::query()
            ->where('product_id', $productId)
            ->whereDate('effective_from', '<=', $date ?? now()->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}
