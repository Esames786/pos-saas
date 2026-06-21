<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ManufacturingScrapLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'manufacturing_scrap_record_id',
        'product_id',
        'unit_id',
        'quantity',
        'recoverable_quantity',
        'disposed_quantity',
        'estimated_loss_value',
        'batch_no',
        'lot_no',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity'             => 'decimal:4',
            'recoverable_quantity' => 'decimal:4',
            'disposed_quantity'    => 'decimal:4',
            'estimated_loss_value' => 'decimal:4',
            'sort_order'           => 'integer',
        ];
    }

    public function scrapRecord()
    {
        return $this->belongsTo(ManufacturingScrapRecord::class, 'manufacturing_scrap_record_id');
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
