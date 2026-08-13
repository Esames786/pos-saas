<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\Recipe;
use Illuminate\Support\Collection;

/**
 * CATERING-SLICE-2: Rate Impact Center (spec §8).
 *
 * When a material rate changes, shows every DRAFT estimate whose costing
 * consumes that material (through a recipe or directly) with current vs
 * recomputed cost and margin impact. Actions only ever touch DRAFT
 * estimates — sent/accepted/confirmed documents are immutable (enforced by
 * the model guard) and are never listed for update.
 */
class CateringRateImpactService
{
    public function __construct(
        private readonly CateringRecipeCostingService $costing,
    ) {}

    /**
     * Draft estimates affected by a product's rate: the product appears as a
     * recipe ingredient of a quoted line's product, or as the line product.
     * Returns rows with old/new cost and margin figures.
     */
    public function impactForProduct(int $productId): Collection
    {
        // Products whose ACTIVE recipe consumes this material.
        $consumingProductIds = Recipe::query()
            ->where('is_active', true)
            ->whereHas('ingredients', fn ($q) => $q->where('product_id', $productId))
            ->pluck('product_id');

        $affected = CateringEstimate::query()
            ->with(['event', 'lines'])
            ->where('status', CateringEstimate::STATUS_DRAFT)
            ->whereHas('lines', fn ($q) => $q
                ->whereIn('product_id', $consumingProductIds->push($productId)->unique()->all()))
            ->whereHas('event', fn ($q) => $q->whereNotIn('status', ['cancelled', 'closed', 'completed']))
            ->orderBy('id')
            ->get();

        return $affected->map(function (CateringEstimate $estimate) {
            $result = $this->costing->calculate($estimate);
            $oldCost = $estimate->estimated_material_cost !== null ? (float) $estimate->estimated_material_cost : null;
            $newCost = $result['total_material_cost'];
            $revenue = (float) $estimate->grand_total;

            return [
                'estimate' => $estimate,
                'event' => $estimate->event,
                'old_cost' => $oldCost,
                'new_cost' => $newCost,
                'cost_delta' => $oldCost !== null ? round($newCost - $oldCost, 2) : null,
                'revenue' => $revenue,
                'old_margin' => $oldCost !== null ? round($revenue - $oldCost, 2) : null,
                'new_margin' => round($revenue - $newCost, 2),
                'warnings' => $result['warnings'],
            ];
        });
    }

    /** Recompute + persist costing for the selected draft estimates. */
    public function applyToDrafts(array $estimateIds, ?int $userId = null): int
    {
        $updated = 0;
        $drafts = CateringEstimate::query()
            ->whereIn('id', $estimateIds)
            ->where('status', CateringEstimate::STATUS_DRAFT)
            ->get();

        foreach ($drafts as $estimate) {
            $this->costing->snapshot($estimate, $userId);
            $updated++;
        }

        return $updated;
    }
}
