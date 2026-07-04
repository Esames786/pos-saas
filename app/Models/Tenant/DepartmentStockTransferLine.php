<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class DepartmentStockTransferLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'department_stock_transfer_id',
        'product_id',
        'product_variant_id',
        'inventory_batch_id',
        'quantity',
        'unit_cost',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity'  => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function transfer()
    {
        return $this->belongsTo(DepartmentStockTransfer::class, 'department_stock_transfer_id');
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
