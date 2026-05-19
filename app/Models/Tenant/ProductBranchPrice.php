<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ProductBranchPrice extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id', 'product_id', 'product_variant_id',
        'selling_price', 'minimum_selling_price', 'is_available',
    ];

    protected function casts(): array
    {
        return [
            'selling_price'         => 'decimal:2',
            'minimum_selling_price' => 'decimal:2',
            'is_available'          => 'boolean',
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
}
