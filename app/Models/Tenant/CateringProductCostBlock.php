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

    /** HOW OFTEN a charge applies: every unit, or once for the order. */
    public const BASIS_PER_UNIT = 'per_unit';

    public const BASIS_LUMP_SUM = 'lump_sum';

    public const BASES = [self::BASIS_PER_UNIT, self::BASIS_LUMP_SUM];

    /**
     * WHAT A MATERIAL'S RATE IS A RATE OF — a different question from the one
     * above, and the two are easy to confuse because both end in "basis".
     *
     *   per_dish_unit      rupees per unit of the FINISHED DISH  (legacy)
     *   per_material_unit  rupees per unit of the MATERIAL       (how a caterer thinks)
     *
     * They differ by the consumption ratio, so the same stored number means two
     * different prices. Existing rows say per_dish_unit because that is what
     * they were authored as; nothing already quoted may move.
     */
    public const RATE_PER_DISH_UNIT = 'per_dish_unit';

    public const RATE_PER_MATERIAL_UNIT = 'per_material_unit';

    public const RATE_BASES = [self::RATE_PER_DISH_UNIT, self::RATE_PER_MATERIAL_UNIT];

    /**
     * WHERE THE RATE CAME FROM — and therefore whether a house rate change
     * should be offered to it.
     *
     *   manual           somebody chose this number for this dish. A premium
     *                    counter charging 140 while the house rate is 120 is
     *                    deliberate, and a global change must leave it alone.
     *   commercial_book  this dish follows the house rate and should be OFFERED
     *                    the new one — offered, never given.
     *
     * The rate stored on the block is the APPLIED rate in both cases. A linked
     * block never reads today's book when a quotation opens; the gap between
     * applied and recommended is exactly what Rate Impact exists to show.
     */
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_COMMERCIAL_BOOK = 'commercial_book';

    public const RATE_SOURCES = [self::SOURCE_MANUAL, self::SOURCE_COMMERCIAL_BOOK];

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
        'rate_basis',
        'commercial_rate_source',
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

    /** Legacy rows, and every charge block, are read per unit of the dish. */
    public function rateBasis(): string
    {
        return in_array($this->rate_basis, self::RATE_BASES, true)
            ? $this->rate_basis
            : self::RATE_PER_DISH_UNIT;
    }

    /** Is this material priced in its OWN unit rather than the dish's? */
    public function isPerMaterialUnit(): bool
    {
        return $this->isMaterial() && $this->rateBasis() === self::RATE_PER_MATERIAL_UNIT;
    }

    public function rateSource(): string
    {
        return in_array($this->commercial_rate_source, self::RATE_SOURCES, true)
            ? $this->commercial_rate_source
            : self::SOURCE_MANUAL;
    }

    /**
     * Does this block follow the house commercial rate?
     *
     * Only a per-material-unit material can. A legacy per-dish-unit rate is
     * rupees per kilo of BIRYANI, and the house book quotes rupees per kilo of
     * CHICKEN — offering one as the other would be an arithmetic category error,
     * not merely a bad suggestion.
     */
    public function followsCommercialBook(): bool
    {
        return $this->isPerMaterialUnit()
            && $this->rateSource() === self::SOURCE_COMMERCIAL_BOOK
            && $this->material_product_id !== null;
    }

    /**
     * What this block adds to a line of the given DISH quantity.
     *
     * A lump sum ignores quantity entirely — that is the whole point of it. A
     * live counter costs the same whether the order is 10 KG or 100.
     *
     * A per-material-unit rate is charged against the material actually needed,
     * so 2.5 KG of chicken at 100 a kilo is 250 — regardless of how many kilos
     * of biryani that chicken went into. $materialQuantity lets a booking line
     * pass the quantity IT settled on, which may not be the ratio-derived one:
     * an operator who says this event needs 3 KG must be charged for 3.
     */
    public function amountFor(float $quantity, ?float $materialQuantity = null): float
    {
        if ($this->isLumpSum()) {
            return round((float) $this->rate, 2);
        }

        if ($this->isPerMaterialUnit()) {
            $needed = $materialQuantity ?? $this->materialRequiredFor($quantity);

            return round($needed * (float) $this->rate, 2);
        }

        return round((float) $this->rate * $quantity, 2);
    }

    /**
     * What this block adds to ONE unit of the dish — the piece of the dish's
     * selling rate it is responsible for.
     *
     * For a per-material rate that is the ratio times the rate: chicken at 100 a
     * kilo with 0.5 KG per kilo of biryani adds 50 to the biryani's rate. Lump
     * sums contribute nothing here; they do not scale, so they can never be part
     * of a per-unit rate without being wrong at every size but one.
     */
    public function contributionPerDishUnit(): float
    {
        if ($this->isLumpSum()) {
            return 0.0;
        }

        return round($this->isPerMaterialUnit()
            ? (float) ($this->quantity_per_unit ?? 0) * (float) $this->rate
            : (float) $this->rate, 4);
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
