<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $connection = 'tenant';

    // PRODUCT-BOUNDARY-2: logical product roles. products.id stays the single inventory
    // identity; "saleable"/"purchasable" reuse the existing is_sellable / is_purchasable.
    public const KIND_SALE_ITEM          = 'sale_item';
    public const KIND_RAW_MATERIAL       = 'raw_material';
    public const KIND_PACKAGING_MATERIAL = 'packaging_material';
    public const KIND_SEMI_FINISHED      = 'semi_finished';
    public const KIND_FINISHED_GOOD      = 'finished_good';
    public const KIND_SERVICE            = 'service';
    public const KIND_COMBO_VIRTUAL      = 'combo_virtual';

    public const KINDS = [
        self::KIND_SALE_ITEM          => 'Sale Item',
        self::KIND_RAW_MATERIAL       => 'Raw Material',
        self::KIND_PACKAGING_MATERIAL => 'Packaging',
        self::KIND_SEMI_FINISHED      => 'Semi Finished',
        self::KIND_FINISHED_GOOD      => 'Finished Good',
        self::KIND_SERVICE            => 'Service',
        self::KIND_COMBO_VIRTUAL      => 'Combo (Virtual)',
    ];

    protected $fillable = [
        'category_id', 'unit_id', 'sku', 'name', 'slug', 'product_type',
        'item_kind', 'inventory_consumption_method', 'is_perishable',
        'storage_type', 'shelf_life_days', 'default_wastage_percent',
        'is_sellable', 'is_purchasable', 'is_stock_tracked', 'has_variants',
        'has_expiry', 'requires_batch', 'default_purchase_price',
        'default_selling_price', 'is_taxable', 'tax_rate_percent',
        'description', 'image_path', 'status',
        'product_kind', 'is_pos_visible', 'can_be_bom_component',
        'can_be_bom_output', 'is_manufactured_finished_good',
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
            'is_pos_visible'             => 'boolean',
            'can_be_bom_component'       => 'boolean',
            'can_be_bom_output'          => 'boolean',
            'is_manufactured_finished_good' => 'boolean',
        ];
    }

    /* ── Role / visibility scopes ─────────────────────────────────────────── */

    /** POS-eligible: active + saleable + explicitly POS-visible. */
    public function scopePosVisible($query)
    {
        return $query->where('status', 'active')
            ->where('is_sellable', true)
            ->where('is_pos_visible', true);
    }

    public function scopeSaleable($query)
    {
        return $query->where('is_sellable', true);
    }

    public function scopePurchasable($query)
    {
        return $query->where('is_purchasable', true);
    }

    public function scopeBomComponent($query)
    {
        return $query->where('can_be_bom_component', true);
    }

    public function scopeBomOutput($query)
    {
        return $query->where('can_be_bom_output', true);
    }

    public function scopeManufacturedFinishedGood($query)
    {
        return $query->where('is_manufactured_finished_good', true);
    }

    /* ── Role / visibility helpers ────────────────────────────────────────── */

    public function isPosVisible(): bool
    {
        return $this->status === 'active' && (bool) $this->is_sellable && (bool) $this->is_pos_visible;
    }

    public function isSaleable(): bool
    {
        return (bool) $this->is_sellable;
    }

    public function isPurchasable(): bool
    {
        return (bool) $this->is_purchasable;
    }

    public function canBeBomComponent(): bool
    {
        return (bool) $this->can_be_bom_component;
    }

    public function canBeBomOutput(): bool
    {
        return (bool) $this->can_be_bom_output;
    }

    /** True for products that exist for manufacturing/internal use, not the POS. */
    public function isManufacturingOnly(): bool
    {
        $kind = $this->product_kind ?? self::KIND_SALE_ITEM;

        if (in_array($kind, [self::KIND_RAW_MATERIAL, self::KIND_PACKAGING_MATERIAL, self::KIND_SEMI_FINISHED], true)) {
            return true;
        }

        return $kind === self::KIND_FINISHED_GOOD && ! $this->is_pos_visible;
    }

    public function productKindLabel(): string
    {
        return self::KINDS[$this->product_kind ?? self::KIND_SALE_ITEM] ?? 'Sale Item';
    }

    public function productKindBadgeClass(): string
    {
        return match ($this->product_kind ?? self::KIND_SALE_ITEM) {
            self::KIND_RAW_MATERIAL       => 'bg-warning text-dark',
            self::KIND_PACKAGING_MATERIAL => 'bg-secondary',
            self::KIND_SEMI_FINISHED      => 'bg-info text-dark',
            self::KIND_FINISHED_GOOD      => 'bg-primary',
            self::KIND_SERVICE            => 'bg-light text-dark border',
            self::KIND_COMBO_VIRTUAL      => 'bg-dark',
            default                       => 'bg-success', // sale_item
        };
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
