<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class StockAdjustmentLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'stock_adjustment_id',
        'product_id',
        'product_variant_id',
        'inventory_batch_id',
        'batch_no',
        'expiry_date',
        'quantity',
        'unit_cost',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'quantity'    => 'decimal:3',
            'unit_cost'   => 'decimal:4',
        ];
    }

    public function adjustment()
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function batch()
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }
}
