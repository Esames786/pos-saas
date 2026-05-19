<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class KitchenWastage extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'wastage_no',
        'branch_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'unit_id',
        'reason',
        'wastage_date',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity'     => 'decimal:4',
            'wastage_date' => 'date',
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

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
