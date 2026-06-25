<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class StockLedger extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id',
        'product_id',
        'product_variant_id',
        'inventory_batch_id',
        'movement_type',
        'direction',
        'quantity',
        'unit_cost',
        'total_cost',
        'balance_after',
        'reference_type',
        'reference_id',
        'reference_no',
        'notes',
        'created_by_user_id',
        // Reversal linkage (MFG-FIN-B) — a future reversal row points at the original.
        'reversal_of_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity'      => 'decimal:3',
            'unit_cost'     => 'decimal:4',
            'total_cost'    => 'decimal:4',
            'balance_after' => 'decimal:3',
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

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
