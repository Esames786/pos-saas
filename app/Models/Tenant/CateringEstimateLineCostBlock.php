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
        'rate',
        'material_product_id',
        'material_name',
        'unit_code',
        'quantity_per_unit',
        'default_material_qty',
        'event_material_qty',
        'is_overridden',
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

    /**
     * Recompute what the customer is charged for this part, from whatever the
     * event settled on. The stored amount is the authority afterwards; this is
     * how it gets there.
     */
    public function computeAmount(float $dishQuantity): float
    {
        if ($this->isLumpSum()) {
            return round((float) $this->rate, 2);
        }

        if ($this->isPerMaterialUnit()) {
            return round((float) ($this->event_material_qty ?? 0) * (float) $this->rate, 2);
        }

        return round((float) $this->rate * $dishQuantity, 2);
    }
}
