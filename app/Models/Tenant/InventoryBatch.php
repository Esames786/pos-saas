<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class InventoryBatch extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'batch_key',
        'branch_id',
        'product_id',
        'product_variant_id',
        'batch_no',
        'manufactured_at',
        'expiry_date',
        'received_date',
        'unit_cost',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'manufactured_at' => 'date',
            'expiry_date'     => 'date',
            'received_date'   => 'date',
            'unit_cost'       => 'decimal:4',
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

    public function balances()
    {
        return $this->hasMany(StockBalance::class);
    }

    public function ledgers()
    {
        return $this->hasMany(StockLedger::class);
    }
}
