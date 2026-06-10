<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'stock_count_session_id',
        'product_id',
        'product_variant_id',
        'unit_id',
        'system_quantity',
        'counted_quantity',
        'variance_quantity',
        'average_cost',
        'variance_value',
        'notes',
    ];

    protected $casts = [
        'system_quantity'   => 'decimal:3',
        'counted_quantity'  => 'decimal:3',
        'variance_quantity' => 'decimal:3',
        'average_cost'      => 'decimal:4',
        'variance_value'    => 'decimal:2',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StockCountSession::class, 'stock_count_session_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function recalculate(): void
    {
        $counted = $this->counted_quantity === null ? null : (float) $this->counted_quantity;
        $system  = (float) $this->system_quantity;
        $cost    = (float) $this->average_cost;

        if ($counted === null) {
            $this->variance_quantity = 0;
            $this->variance_value    = 0;
            return;
        }

        $variance = round($counted - $system, 3);

        $this->variance_quantity = $variance;
        $this->variance_value    = round($variance * $cost, 2);
    }
}
