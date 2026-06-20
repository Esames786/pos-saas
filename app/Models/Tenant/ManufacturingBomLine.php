<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ManufacturingBomLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'manufacturing_bom_id',
        'component_product_id',
        'unit_id',
        'quantity',
        'wastage_percent',
        'sort_order',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity'        => 'decimal:4',
            'wastage_percent' => 'decimal:4',
            'sort_order'      => 'integer',
        ];
    }

    public function bom()
    {
        return $this->belongsTo(ManufacturingBom::class, 'manufacturing_bom_id');
    }

    public function componentProduct()
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Estimated component quantity needed to produce $plannedOutputQty units,
     * including the wastage allowance.
     */
    public function estimatedComponentQuantity(float $plannedOutputQty): float
    {
        $outputQty = (float) ($this->bom?->output_quantity ?? 1);
        $base = ($plannedOutputQty / max($outputQty, 0.0001)) * (float) $this->quantity;
        return round($base * (1 + ((float) $this->wastage_percent / 100)), 4);
    }
}
