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
        // DEAL-SPLIT-REPORT-1: this item's part of ONE deal, in Rs (report-only).
        'report_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'sort_order' => 'integer',
            'report_amount' => 'decimal:2',
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
