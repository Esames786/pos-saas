<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ProductBarcode extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['product_id', 'product_variant_id', 'barcode', 'barcode_type', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
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
