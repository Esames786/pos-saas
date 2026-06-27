<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ManufacturingConsumptionLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'manufacturing_consumption_record_id',
        'wip_job_line_id',
        'material_requisition_line_id',
        'component_product_id',
        'unit_id',
        'planned_quantity',
        'consumed_quantity',
        'wastage_quantity',
        'variance_quantity',
        'estimated_unit_cost',
        'estimated_total_value',
        'actual_unit_cost',
        'actual_total_cost',
        'posted_quantity',
        'batch_no',
        'lot_no',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'planned_quantity'      => 'decimal:4',
            'consumed_quantity'     => 'decimal:4',
            'wastage_quantity'      => 'decimal:4',
            'variance_quantity'     => 'decimal:4',
            'estimated_unit_cost'   => 'decimal:4',
            'estimated_total_value' => 'decimal:4',
            'actual_unit_cost'      => 'decimal:4',
            'actual_total_cost'     => 'decimal:4',
            'posted_quantity'       => 'decimal:4',
            'sort_order'            => 'integer',
        ];
    }

    public function consumptionRecord()
    {
        return $this->belongsTo(ManufacturingConsumptionRecord::class, 'manufacturing_consumption_record_id');
    }

    public function wipJobLine()
    {
        return $this->belongsTo(WipJobLine::class);
    }

    public function materialRequisitionLine()
    {
        return $this->belongsTo(MaterialRequisitionLine::class);
    }

    public function componentProduct()
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
