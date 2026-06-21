<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ManufacturingRejectionLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'manufacturing_rejection_record_id',
        'product_id',
        'unit_id',
        'quantity',
        'rework_quantity',
        'scrap_quantity',
        'accepted_after_review_quantity',
        'disposed_quantity',
        'estimated_loss_value',
        'defect_code',
        'batch_no',
        'lot_no',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity'                       => 'decimal:4',
            'rework_quantity'                => 'decimal:4',
            'scrap_quantity'                 => 'decimal:4',
            'accepted_after_review_quantity' => 'decimal:4',
            'disposed_quantity'              => 'decimal:4',
            'estimated_loss_value'           => 'decimal:4',
            'sort_order'                     => 'integer',
        ];
    }

    public function rejectionRecord()
    {
        return $this->belongsTo(ManufacturingRejectionRecord::class, 'manufacturing_rejection_record_id');
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
