<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * KASHIF-CATERING-LINE-SNAPSHOT-1 — one priced part of one booking line.
 *
 * A copy of a cost block as it stood when the line was quoted. It is not a link
 * to the master block: the master is free to change, and this must not. A dish
 * re-rated in March cannot be allowed to rewrite what a customer agreed to in
 * January.
 *
 * It keeps the three numbers apart, as everything in this design does:
 *
 *   amount                 what the customer is charged for this part
 *   event_material_qty     what the kitchen was expected to draw
 *   material_cost          what that material cost, at the rate book of the day
 *
 * And it keeps the ratio's default beside the event's actual figure, so an
 * override is visible as an override rather than as the only number anyone
 * remembers.
 */
class CateringEstimateLineCostBlock extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'catering_estimate_line_id',
        'source_block_id',
        'label',
        'block_type',
        'charge_basis',
        'rate_basis',
        'commercial_rate_source',
        'rate',
        'material_product_id',
        'material_name',
        'unit_code',
        'quantity_per_unit',
        'default_material_qty',
        'event_material_qty',
        'is_overridden',
        'is_customer_supplied',
        'material_rate_at_quote',
        'material_cost',
        'amount',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'quantity_per_unit' => 'decimal:4',
            'default_material_qty' => 'decimal:4',
            'event_material_qty' => 'decimal:4',
            'material_rate_at_quote' => 'decimal:4',
            'material_cost' => 'decimal:4',
            'amount' => 'decimal:2',
            'is_overridden' => 'boolean',
            'is_customer_supplied' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function line()
    {
        return $this->belongsTo(CateringEstimateLine::class, 'catering_estimate_line_id');
    }

    public function material()
    {
        return $this->belongsTo(Product::class, 'material_product_id');
    }

    public function isMaterial(): bool
    {
        return $this->block_type === CateringProductCostBlock::TYPE_MATERIAL;
    }

    public function isLumpSum(): bool
    {
        return $this->charge_basis === CateringProductCostBlock::BASIS_LUMP_SUM;
    }

    public function isPerMaterialUnit(): bool
    {
        return $this->isMaterial()
            && $this->rate_basis === CateringProductCostBlock::RATE_PER_MATERIAL_UNIT;
    }

    public function rateSource(): string
    {
        return in_array($this->commercial_rate_source, CateringProductCostBlock::RATE_SOURCES, true)
            ? $this->commercial_rate_source
            : CateringProductCostBlock::SOURCE_MANUAL;
    }

    /**
     * Would a house commercial rate change be offered to this snapshot?
     *
     * Not if the customer is bringing the material: they are not being charged
     * for it, so a change to what it is charged at moves nothing. Not if the
     * rate was chosen by hand for this dish. And not on a legacy per-dish rate,
     * which is measured in a different unit from the house book entirely.
     */
    public function followsCommercialBook(): bool
    {
        return $this->isPerMaterialUnit()
            && ! $this->isCustomerSupplied()
            && $this->rateSource() === CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK
            && $this->material_product_id !== null;
    }

    /** Is the customer bringing this material themselves? */
    public function isCustomerSupplied(): bool
    {
        return $this->isMaterial() && (bool) $this->is_customer_supplied;
    }

    /**
     * What the KITCHEN needs. Unchanged by who supplies it — the dish is the
     * same dish and the cooking is the same cooking.
     */
    public function physicalRequirement(): float
    {
        return round((float) ($this->event_material_qty ?? 0), 4);
    }

    /**
     * What OUR STORE has to hand over. Zero when the customer brings it.
     *
     * This is the number a kitchen requirement sheet must eventually ask for —
     * never the physical requirement, or the business would issue material it
     * agreed not to supply.
     */
    public function ourStockRequirement(): float
    {
        return $this->isCustomerSupplied() ? 0.0 : $this->physicalRequirement();
    }

    /**
     * Recompute what the customer is charged for this part, from whatever the
     * event settled on. The stored amount is the authority afterwards; this is
     * how it gets there.
     *
     * A customer-supplied material is charged nothing: they brought it. Making
     * and packing are untouched — the caterer still did the work.
     */
    public function computeAmount(float $dishQuantity): float
    {
        if ($this->isCustomerSupplied()) {
            return 0.0;
        }

        if ($this->isLumpSum()) {
            return round((float) $this->rate, 2);
        }

        if ($this->isPerMaterialUnit()) {
            return round((float) ($this->event_material_qty ?? 0) * (float) $this->rate, 2);
        }

        return round((float) $this->rate * $dishQuantity, 2);
    }

    /**
     * What this material costs the business on this line.
     *
     * Nothing, when the customer supplied it. The rate book still knows what the
     * material is worth, and that stays visible as context — but the business
     * did not buy it, and recording a cost it did not incur would understate the
     * margin on exactly the arrangement designed to protect it.
     */
    public function computeMaterialCost(): ?float
    {
        if ($this->isCustomerSupplied()) {
            return 0.0;
        }

        if ($this->material_rate_at_quote === null || $this->event_material_qty === null) {
            return null;
        }

        return round((float) $this->event_material_qty * (float) $this->material_rate_at_quote, 4);
    }
}
