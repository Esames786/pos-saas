<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class RecipeIngredient extends Model
{
    protected $connection = 'tenant';

    /** KITCHEN-RECIPE-COST-1 report sections. */
    public const SECTIONS = [
        'food_cost'        => 'Recipe / Food Cost',
        'packing_material' => 'Packing Material',
        'garnish'          => 'Garnish',
        'sauce'            => 'Sauce',
        'other'            => 'Other',
    ];

    /** KITCHEN-RECIPE-ORDER-TYPE-1 — POS order types a line may apply to. */
    public const ORDER_TYPE_ALL        = 'all';
    public const ORDER_TYPE_DINE_IN    = 'dine_in';
    public const ORDER_TYPE_TAKEAWAY   = 'takeaway';
    public const ORDER_TYPE_DELIVERY   = 'delivery';
    public const ORDER_TYPE_QUICK_SALE = 'quick_sale';

    public const ORDER_TYPES = [
        self::ORDER_TYPE_ALL        => 'All',
        self::ORDER_TYPE_DINE_IN    => 'Dine In',
        self::ORDER_TYPE_TAKEAWAY   => 'Takeaway',
        self::ORDER_TYPE_DELIVERY   => 'Delivery',
        self::ORDER_TYPE_QUICK_SALE => 'Quick Sale',
    ];

    /** Specific (non-"all") order types — the values a line may actually be scoped to. */
    public const SPECIFIC_ORDER_TYPES = [
        self::ORDER_TYPE_DINE_IN,
        self::ORDER_TYPE_TAKEAWAY,
        self::ORDER_TYPE_DELIVERY,
        self::ORDER_TYPE_QUICK_SALE,
    ];

    protected $fillable = [
        'recipe_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'unit_id',
        'cost_override',
        'line_section',
        'applicable_order_types',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity'               => 'decimal:4',
            'cost_override'          => 'decimal:4',
            'sort_order'             => 'integer',
            'applicable_order_types' => 'array',
        ];
    }

    public function sectionLabel(): string
    {
        return self::SECTIONS[$this->line_section ?? 'food_cost'] ?? 'Recipe / Food Cost';
    }

    /**
     * Does this line apply to the given POS order type?
     *  - $orderType null            → true (report "all lines" mode)
     *  - applicable list null/empty → true (applies everywhere)
     *  - list contains "all"        → true
     *  - otherwise                  → membership test
     */
    public function appliesToOrderType(?string $orderType): bool
    {
        if ($orderType === null) {
            return true;
        }

        $types = $this->applicable_order_types;

        if (empty($types) || in_array(self::ORDER_TYPE_ALL, $types, true)) {
            return true;
        }

        return in_array($orderType, $types, true);
    }

    /** Human labels for the applicable order types (["All"] when unrestricted). */
    public function applicableOrderTypeLabels(): array
    {
        $types = $this->applicable_order_types;

        if (empty($types) || in_array(self::ORDER_TYPE_ALL, $types, true)) {
            return [self::ORDER_TYPES[self::ORDER_TYPE_ALL]];
        }

        return array_values(array_map(
            fn ($t) => self::ORDER_TYPES[$t] ?? $t,
            array_filter($types, fn ($t) => isset(self::ORDER_TYPES[$t]))
        ));
    }

    public function applicableOrderTypesLabel(): string
    {
        return implode(', ', $this->applicableOrderTypeLabels());
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
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
}
