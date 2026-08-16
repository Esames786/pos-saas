<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use Illuminate\Database\Eloquent\Model;

/**
 * KASHIF-CATERING-COST-BLOCKS-1 — one named part of a dish's price.
 *
 * A dish is the sum of its blocks. Two kinds, and the difference decides both
 * what the customer pays and what leaves the store:
 *
 *   MATERIAL  linked to a real material. Carries a rate (what the customer pays
 *             per unit of the DISH) and a quantity_per_unit (how much material
 *             one unit of dish consumes). Those two are independent: 20 KG of
 *             karahi charges 20 × 200 while drawing 20 × 0.5 = 10 KG of chicken.
 *
 *   CHARGE    money with no material behind it — making, packing, service. Never
 *             touches stock. Either per_unit (rate × quantity) or lump_sum
 *             (charged once, however large the order).
 */
class CateringProductCostBlock extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    protected string $canonicalIdentityColumn = 'block_uuid';

    public const TYPE_MATERIAL = 'material';

    public const TYPE_CHARGE = 'charge';

    public const TYPES = [self::TYPE_MATERIAL, self::TYPE_CHARGE];

    public const BASIS_PER_UNIT = 'per_unit';

    public const BASIS_LUMP_SUM = 'lump_sum';

    public const BASES = [self::BASIS_PER_UNIT, self::BASIS_LUMP_SUM];

    protected $fillable = [
        'product_id',
        'label',
        'block_type',
        'sort_order',
        'material_product_id',
        'quantity_per_unit',
        'unit_id',
        'rate',
        'charge_basis',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'quantity_per_unit' => 'decimal:4',
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** The material this block draws from the store. Null for a charge. */
    public function material()
    {
        return $this->belongsTo(Product::class, 'material_product_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function isMaterial(): bool
    {
        return $this->block_type === self::TYPE_MATERIAL;
    }

    public function isLumpSum(): bool
    {
        return $this->charge_basis === self::BASIS_LUMP_SUM;
    }

    /**
     * What this block adds to a line of the given quantity.
     *
     * A lump sum ignores quantity entirely — that is the whole point of it. A
     * live counter costs the same whether the order is 10 KG or 100.
     */
    public function amountFor(float $quantity): float
    {
        return round($this->isLumpSum()
            ? (float) $this->rate
            : (float) $this->rate * $quantity, 2);
    }

    /**
     * How much material a line of the given quantity consumes.
     *
     * Zero for a charge block, and zero for a material block with no ratio set —
     * a block that charges for chicken without saying how much chicken is a
     * pricing decision, not a stock one.
     */
    public function materialRequiredFor(float $quantity): float
    {
        if (! $this->isMaterial() || $this->quantity_per_unit === null) {
            return 0.0;
        }

        return round((float) $this->quantity_per_unit * $quantity, 4);
    }
}
