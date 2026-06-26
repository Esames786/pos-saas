<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'category_id', 'unit_id', 'sku', 'name', 'slug', 'product_type',
        'item_kind', 'inventory_consumption_method', 'is_perishable',
        'storage_type', 'shelf_life_days', 'default_wastage_percent',
        'is_sellable', 'is_purchasable', 'is_stock_tracked', 'has_variants',
        'has_expiry', 'requires_batch', 'default_purchase_price',
        'default_selling_price', 'is_taxable', 'tax_rate_percent',
        'description', 'image_path', 'status',
    ];

    protected function casts(): array
    {
        return [
            'is_perishable'              => 'boolean',
            'shelf_life_days'            => 'integer',
            'default_wastage_percent'    => 'decimal:2',
            'is_sellable'                => 'boolean',
            'is_purchasable'             => 'boolean',
            'is_stock_tracked'           => 'boolean',
            'has_variants'               => 'boolean',
            'has_expiry'                 => 'boolean',
            'requires_batch'             => 'boolean',
            'default_purchase_price'     => 'decimal:2',
            'default_selling_price'      => 'decimal:2',
            'is_taxable'                 => 'boolean',
            'tax_rate_percent'           => 'decimal:4',
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function translations()
    {
        return $this->hasMany(ProductTranslation::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function defaultVariant()
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
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

    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }

    public function activeRecipe()
    {
        return $this->hasOne(Recipe::class)->where('is_active', true)->latest();
    }

    public function recipeIngredientUses()
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function modifierGroups()
    {
        return $this->belongsToMany(ModifierGroup::class, 'product_modifier_group')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function linkedModifiers()
    {
        return $this->hasMany(Modifier::class, 'linked_product_id');
    }

    public function isRecipeBased(): bool
    {
        return $this->inventory_consumption_method === 'recipe';
    }

    public function isStockItem(): bool
    {
        return $this->inventory_consumption_method === 'stock_item';
    }
}
