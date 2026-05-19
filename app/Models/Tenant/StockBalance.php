<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class StockBalance extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'balance_key',
        'branch_id',
        'product_id',
        'product_variant_id',
        'inventory_batch_id',
        'quantity_on_hand',
        'average_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:3',
            'average_cost'     => 'decimal:4',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
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
