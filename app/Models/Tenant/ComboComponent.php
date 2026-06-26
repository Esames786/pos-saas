<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ComboComponent extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'combo_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'sort_order' => 'integer',
        ];
    }

    public function combo()
    {
        return $this->belongsTo(Combo::class);
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
