<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class MaterialRequisitionLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'material_requisition_id',
        'component_product_id',
        'unit_id',
        'required_quantity',
        'issued_quantity',
        'wastage_percent',
        'source_bom_line_id',
        'sort_order',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'required_quantity' => 'decimal:4',
            'issued_quantity'   => 'decimal:4',
            'wastage_percent'   => 'decimal:4',
            'sort_order'        => 'integer',
        ];
    }

    public function requisition()
    {
        return $this->belongsTo(MaterialRequisition::class, 'material_requisition_id');
    }

    public function componentProduct()
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function sourceBomLine()
    {
        return $this->belongsTo(ManufacturingBomLine::class, 'source_bom_line_id');
    }

    /** Quantity still to be issued (never negative). */
    public function remainingQuantity(): float
    {
        return max(0, (float) $this->required_quantity - (float) $this->issued_quantity);
    }
}
