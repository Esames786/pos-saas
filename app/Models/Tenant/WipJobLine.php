<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class WipJobLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'wip_job_id',
        'material_requisition_line_id',
        'component_product_id',
        'unit_id',
        'required_quantity',
        'issued_quantity',
        'consumed_quantity',
        'remaining_quantity',
        'sort_order',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'required_quantity'  => 'decimal:4',
            'issued_quantity'    => 'decimal:4',
            'consumed_quantity'  => 'decimal:4',
            'remaining_quantity' => 'decimal:4',
            'sort_order'         => 'integer',
        ];
    }

    public function wipJob()
    {
        return $this->belongsTo(WipJob::class);
    }

    public function materialRequisitionLine()
    {
        return $this->belongsTo(MaterialRequisitionLine::class, 'material_requisition_line_id');
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
