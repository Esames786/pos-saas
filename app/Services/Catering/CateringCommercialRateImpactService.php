<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLineCostBlock;
use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * KASHIF-CATERING-RATE-IMPACT-1 — what a house rate change would do, before it
 * does it.
 *
 * Chicken goes from 100 a kilo to 120. Four dishes use chicken, at four
 * different ratios, and there are drafts already quoted at the old rate. The
 * question is never "reprice everything" — it is "show me what would move, and
 * let me choose".
 *
 * So the two halves are kept strictly apart:
 *
 *   APPLIED       the rate on the block or the snapshot. What is being charged.
 *   RECOMMENDED   the rate in the commercial book. What the house now says.
 *
 * Nothing reads the book at quotation time. The gap between the two is the
 * impact, and closing it is always somebody's decision, one selection at a time.
 *
 * Three things are never offered an impact, each for its own reason:
 *
 *   manual rates          chosen for that dish on purpose. A premium counter at
 *                         140 is not a dish that forgot to update.
 *   legacy per-dish rates measured in rupees per kilo of BIRYANI, where the book
 *                         quotes rupees per kilo of CHICKEN. Not a bad
 *                         suggestion — a category error.
 *   customer-supplied     the customer is not being charged for that material,
 *                         so what it is charged at cannot move anything.
 */
class CateringCommercialRateImpactService
{
    /**
     * What a material's house rate change would do to the DISHES that follow it.
     *
     * @return array{
     *   material: array{id: int, name: string},
     *   recommended: ?float,
     *   products: array<int, array>,
     *   ineligible: array<int, array>
     * }
     */
    public function productImpact(int $materialProductId, ?float $newRate = null, ?string $asOfDate = null): array
    {
        $material = Product::findOrFail($materialProductId);
        $recommended = $newRate ?? CateringMaterialCommercialRate::rateFor($materialProductId, $asOfDate);

        $blocks = CateringProductCostBlock::query()
            ->with(['product:id,name,sku'])
            ->where('material_product_id', $materialProductId)
            ->where('block_type', CateringProductCostBlock::TYPE_MATERIAL)
            ->where('is_active', true)
            ->orderBy('product_id')
            ->get();

        $products = [];
        $ineligible = [];

        foreach ($blocks as $block) {
            $ratio = (float) ($block->quantity_per_unit ?? 0);
            $applied = (float) $block->rate;

            $row = [
                'block_id' => (int) $block->id,
                'product_id' => (int) $block->product_id,
                'product_name' => $block->product?->name,
                'label' => $block->label,
                'ratio' => $ratio,
                'applied_rate' => $applied,
                'rate_basis' => $block->rateBasis(),
                'source' => $block->rateSource(),
            ];

            if (! $block->followsCommercialBook()) {
                $ineligible[] = $row + ['reason' => $this->whyIneligible($block)];

                continue;
            }

            // The dish's rate is the sum of its parts, so this part's movement
            // IS the dish's movement — ratio times the change, per unit of dish.
            $oldRate = app(CateringCostBlockService::class)->rateFor($block->product_id);
            $contributionNow = round($ratio * $applied, 4);
            $contributionNew = $recommended === null ? $contributionNow : round($ratio * $recommended, 4);
            $projected = round($oldRate - $contributionNow + $contributionNew, 2);

            $products[] = $row + [
                'recommended_rate' => $recommended,
                'old_calculated_rate' => round($oldRate, 2),
                'projected_calculated_rate' => $projected,
                'difference' => round($projected - $oldRate, 2),
            ];
        }

        return [
            'material' => ['id' => $material->id, 'name' => $material->name],
            'recommended' => $recommended,
            'products' => $products,
            'ineligible' => $ineligible,
        ];
    }

    private function whyIneligible(CateringProductCostBlock $block): string
    {
        if (! $block->isPerMaterialUnit()) {
            return 'Priced per unit of the dish, not per unit of the material — the house rate is a '
                .'different measurement and cannot be offered to it.';
        }

        return 'This rate was set by hand for this dish, so a house change leaves it alone.';
    }

    /**
     * What the change would do to DRAFT QUOTATIONS already priced at the old
     * rate — worked out from each line's own snapshot, never from the dish.
     *
     * @return array<int, array>
     */
    public function draftImpact(int $materialProductId, ?float $newRate = null, ?string $asOfDate = null): array
    {
        $recommended = $newRate ?? CateringMaterialCommercialRate::rateFor($materialProductId, $asOfDate);
        if ($recommended === null) {
            return [];
        }

        $snapshots = CateringEstimateLineCostBlock::query()
            ->with(['line.estimate.event'])
            ->where('material_product_id', $materialProductId)
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($snapshots as $snapshot) {
            $estimate = $snapshot->line?->estimate;
            if (! $estimate) {
                continue;
            }

            $status = $estimate->isDraft() ? 'draft' : $estimate->status;
            $applied = (float) $snapshot->rate;

            // The quantity THIS event settled on, which may not be the ratio's:
            // an operator who said twelve kilos is charged for twelve.
            $quantity = $snapshot->physicalRequirement();

            $eligible = $snapshot->followsCommercialBook() && $estimate->isDraft();

            $oldAmount = (float) $snapshot->amount;
            $newAmount = $snapshot->isCustomerSupplied()
                ? 0.0
                : round($quantity * $recommended, 2);

            $rows[] = [
                'snapshot_id' => (int) $snapshot->id,
                'line_id' => (int) $snapshot->catering_estimate_line_id,
                'estimate_id' => (int) $estimate->id,
                'event_no' => $estimate->event?->event_no,
                'customer' => $estimate->event?->customer_name,
                'item_name' => $snapshot->line?->item_name,
                'status' => $status,
                'label' => $snapshot->label,
                'material_qty' => $quantity,
                'applied_rate' => $applied,
                'recommended_rate' => $recommended,
                'old_amount' => round($oldAmount, 2),
                'new_amount' => $snapshot->isCustomerSupplied() ? 0.0 : $newAmount,
                // Zero for a customer-supplied material, whatever the house does:
                // nobody is being charged for it.
                'difference' => $snapshot->isCustomerSupplied() ? 0.0 : round($newAmount - $oldAmount, 2),
                'customer_supplied' => $snapshot->isCustomerSupplied(),
                'source' => $snapshot->rateSource(),
                'eligible' => $eligible,
                'reason' => $this->whyQuoteIneligible($snapshot, $estimate),
            ];
        }

        return $rows;
    }

    private function whyQuoteIneligible(CateringEstimateLineCostBlock $snapshot, CateringEstimate $estimate): ?string
    {
        if ($snapshot->isCustomerSupplied()) {
            return 'The customer is supplying this material, so it is not being charged for.';
        }
        if (! $snapshot->isPerMaterialUnit()) {
            return 'Priced per unit of the dish — a different measurement from the house rate.';
        }
        if ($snapshot->rateSource() !== CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK) {
            return 'This price was agreed for this quotation rather than taken from the house rate.';
        }
        if (! $estimate->isDraft()) {
            return 'This quotation has been sent. Revise it to change anything.';
        }

        return null;
    }

    /**
     * Apply the house rate to selected DISHES.
     *
     * Changes what future quotations will be priced at. Deliberately does NOT
     * touch quotations that already exist — those were agreed at the rate they
     * were agreed at, and moving them silently is the thing this whole workflow
     * is built to prevent.
     *
     * @param  array<int, int>  $blockIds
     */
    public function applyToProducts(int $materialProductId, array $blockIds, ?float $newRate = null, ?string $asOfDate = null): int
    {
        $rate = $newRate ?? CateringMaterialCommercialRate::rateFor($materialProductId, $asOfDate);
        if ($rate === null) {
            throw new RuntimeException(
                'There is no commercial rate for this material yet, so there is nothing to apply.'
            );
        }

        $applied = 0;

        DB::connection('tenant')->transaction(function () use ($materialProductId, $blockIds, $rate, &$applied) {
            $blocks = CateringProductCostBlock::query()
                ->where('material_product_id', $materialProductId)
                ->whereIn('id', $blockIds ?: [0])
                ->get();

            foreach ($blocks as $block) {
                // Re-checked here, not merely filtered in the preview: a request
                // naming a manual block must not be able to overwrite a rate
                // somebody chose on purpose.
                if (! $block->followsCommercialBook()) {
                    continue;
                }

                $block->forceFill(['rate' => $rate])->save();
                $applied++;
            }
        });

        return $applied;
    }

    /**
     * Apply the house rate to selected DRAFT quotations.
     *
     * Only the named material's snapshot moves. Everything else the quotation
     * knows — the other materials, the making, the quantity this event settled
     * on, who is supplying it, the lump sums, an agreed rate and its reason —
     * is left exactly as it was.
     *
     * @param  array<int, int>  $snapshotIds
     */
    public function applyToDrafts(int $materialProductId, array $snapshotIds, ?float $newRate = null, ?string $asOfDate = null): int
    {
        $rate = $newRate ?? CateringMaterialCommercialRate::rateFor($materialProductId, $asOfDate);
        if ($rate === null) {
            throw new RuntimeException(
                'There is no commercial rate for this material yet, so there is nothing to apply.'
            );
        }

        $lineBlocks = app(CateringLineCostBlockService::class);
        $applied = 0;
        $touchedLines = [];

        DB::connection('tenant')->transaction(function () use (
            $materialProductId, $snapshotIds, $rate, $lineBlocks, &$applied, &$touchedLines
        ) {
            $snapshots = CateringEstimateLineCostBlock::query()
                ->with(['line.estimate'])
                ->where('material_product_id', $materialProductId)
                ->whereIn('id', $snapshotIds ?: [0])
                ->get();

            foreach ($snapshots as $snapshot) {
                $estimate = $snapshot->line?->estimate;

                // Fail closed on every condition the preview filtered by. A sent
                // quotation, a hand-agreed price and a customer-supplied
                // material each stay where they are.
                if (! $estimate?->isDraft() || ! $snapshot->followsCommercialBook()) {
                    continue;
                }

                $snapshot->forceFill(['rate' => $rate])->save();
                $snapshot->forceFill([
                    'material_cost' => $snapshot->computeMaterialCost(),
                    'amount' => $snapshot->computeAmount((float) $snapshot->line->quantity),
                ])->save();

                $applied++;
                $touchedLines[$snapshot->catering_estimate_line_id] = $snapshot->line;
            }

            // Reprice each touched line once, which also re-adds its quotation.
            foreach ($touchedLines as $line) {
                $lineBlocks->reprice($line->refresh());
            }
        });

        return $applied;
    }
}
