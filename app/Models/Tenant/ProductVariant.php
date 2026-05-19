<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'product_id', 'sku', 'name', 'barcode', 'purchase_price', 'selling_price',
        'reorder_level', 'reorder_quantity', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price'   => 'decimal:2',
            'selling_price'    => 'decimal:2',
            'reorder_level'    => 'decimal:3',
            'reorder_quantity' => 'decimal:3',
            'is_default'       => 'boolean',
            'is_active'        => 'boolean',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function translations()
    {
        return $this->hasMany(ProductVariantTranslation::class);
    }

    public function barcodes()
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function branchPrices()
    {
        return $this->hasMany(ProductBranchPrice::class);
    }

    public function stockBalances()
    {
        return $this->hasMany(StockBalance::class);
    }

    public function stockLedgers()
    {
        return $this->hasMany(StockLedger::class);
    }

    public function inventoryBatches()
    {
        return $this->hasMany(InventoryBatch::class);
    }

    public function salesOrderLines()
    {
        return $this->hasMany(SalesOrderLine::class);
    }
}
