<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class DepartmentCountLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'line_key',
        'department_count_session_id',
        'product_id',
        'product_variant_id',
        'expected_qty',
        'counted_qty',
        'variance_qty',
        'average_cost',
        'variance_value',
        'reason_code',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'expected_qty'   => 'decimal:3',
            'counted_qty'    => 'decimal:3',
            'variance_qty'   => 'decimal:3',
            'average_cost'   => 'decimal:4',
            'variance_value' => 'decimal:4',
        ];
    }

    public function session()
    {
        return $this->belongsTo(DepartmentCountSession::class, 'department_count_session_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** Recompute variance from counted vs expected. */
    public function recalculate(): void
    {
        $variance = (float) $this->counted_qty - (float) $this->expected_qty;
        $this->variance_qty   = $variance;
        $this->variance_value = round($variance * (float) $this->average_cost, 4);
    }
}
