<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\Unit;
use App\Services\Kitchen\UnitConversionService;
use RuntimeException;

/**
 * CATERING-SLICE-3 / CAT-PROD-001 — what a booking actually needs from the store.
 *
 * Consolidates by material in the MATERIAL's own stock unit: Chicken Biryani +
 * Qorma + Karahi sharing Raw Chicken yield ONE Raw Chicken requirement line.
 *
 * WHICH AUTHORITY DECIDES, and this is the whole of CAT-PROD-001.
 *
 * This service used to explode active recipes and nothing else. That was correct
 * when a recipe was the only way to say what a dish consumed, and quietly wrong
 * from the moment Cost Blocks arrived, because the operator's event-specific
 * decisions live on the QUOTATION, not on the product:
 *
 *   - a block-costed dish with no recipe contributed NOTHING to the store
 *   - a block-costed dish with a dormant old recipe contributed the DORMANT one
 *   - "this wedding needs 12 KG, not the ratio's 10" was ignored
 *   - "the customer is bringing the rice" was ignored, and the store would have
 *     been asked to issue rice the business had agreed not to supply
 *
 * So the authority is chosen per line, in this order:
 *
 *   1. the line's own cost-block SNAPSHOT, when it has one — the frozen record
 *      of what was quoted, including every event decision made against it
 *   2. the product's active RECIPE, exactly as before
 *   3. nothing, for a free-text or unconfigured line
 *
 * A snapshot is never rebuilt from today's product master. That is the point of
 * a snapshot: the dish may have been re-costed since, and the customer agreed to
 * what was on the paper.
 *
 * TWO QUANTITIES, DELIBERATELY SEPARATE:
 *
 *   physical_qty   what the KITCHEN needs. Unchanged by who supplies it — the
 *                  dish is the same dish and the cooking is the same cooking.
 *   required_qty   what OUR STORE must hand over. Zero for a customer-supplied
 *                  material, and this is the number an issue is measured against.
 *
 * READ-ONLY planning throughout. Stock balances are read; nothing is written,
 * reserved, or posted. Looking at a requirement has never moved stock and still
 * does not.
 */
class CateringRequirementService
{
    public function __construct(
        private readonly UnitConversionService $unitConversion,
    ) {}

    /**
     * @return array{requirements: array<int, array>, warnings: string[]}
     */
    public function consolidatedForEstimate(CateringEstimate $estimate, ?int $branchId = null): array
    {
        $requirements = [];
        $warnings = [];

        $lines = $estimate->lines()
            ->with([
                'costBlocks',
                'product.activeRecipe.ingredients.product.unit',
                'product.unit',
                'unit',
            ])
            ->get();

        foreach ($lines as $line) {
            // The quotation first. Only when the line was never priced from
            // blocks does the product master get a say.
            if ($this->addFromSnapshot($line, $requirements, $warnings)) {
                continue;
            }

            $this->addFromRecipe($line, $requirements, $warnings);
        }

        $this->attachStockPosition($requirements, $branchId);

        return [
            'requirements' => array_values($requirements),
            'warnings' => $warnings,
        ];
    }

    /**
     * The quoted breakdown, as it was quoted.
     *
     * Returns false when this line has no snapshot, so the caller can fall back
     * to the recipe — but true as soon as one exists, even if every block on it
     * is a charge. A dish quoted as pure labour requires nothing from the store,
     * and that is an answer, not a gap to be filled from somewhere else.
     *
     * @param  array<int, array>  $requirements
     * @param  string[]  $warnings
     */
    private function addFromSnapshot(CateringEstimateLine $line, array &$requirements, array &$warnings): bool
    {
        $snapshots = $line->costBlocks;

        if ($snapshots->isEmpty()) {
            return false;
        }

        foreach ($snapshots as $snapshot) {
            if (! $snapshot->isMaterial() || $snapshot->material_product_id === null) {
                continue;
            }

            $material = $snapshot->material;
            if (! $material) {
                continue;
            }

            // physicalRequirement() is the event's own settled quantity — the
            // override when somebody typed one, the ratio's figure when nobody
            // did. ourStockRequirement() is the same number, or zero when the
            // customer is bringing it.
            $physical = $snapshot->physicalRequirement();
            $ours = $snapshot->ourStockRequirement();

            if ($physical <= 0 && $ours <= 0) {
                continue;
            }

            // The snapshot recorded the unit it was quoted in. Consolidate in
            // the material's own stock unit so one material is one line.
            [$physical, $ours] = $this->toStockUnit(
                $physical,
                $ours,
                $snapshot->unit_code,
                $material,
                $warnings
            );

            $this->accumulate($requirements, $material, $line, $physical, $ours, 'quotation');
        }

        return true;
    }

    /**
     * @param  array<int, array>  $requirements
     * @param  string[]  $warnings
     */
    private function addFromRecipe(CateringEstimateLine $line, array &$requirements, array &$warnings): void
    {
        $product = $line->product;
        if (! $product) {
            return;
        }

        $recipe = $product->activeRecipe;
        if (! $recipe || $recipe->ingredients->isEmpty()) {
            return;
        }

        $yieldQty = (float) ($recipe->yield_quantity ?: 1);
        $outputQty = (float) $line->quantity;

        if ($line->unit && $recipe->yieldUnit && $line->unit->id !== $recipe->yieldUnit->id) {
            try {
                $outputQty = $this->unitConversion->convert($outputQty, $line->unit, $recipe->yieldUnit);
            } catch (RuntimeException $e) {
                $warnings[] = "Line '{$line->item_name}': {$e->getMessage()} Quote quantity used unconverted.";
            }
        }

        $batchCount = $outputQty / $yieldQty;

        foreach ($recipe->ingredients as $ingredient) {
            $material = $ingredient->product;
            if (! $material) {
                continue;
            }

            $requiredQty = (float) $ingredient->quantity * $batchCount;

            if ($ingredient->unit && $material->unit && $ingredient->unit->id !== $material->unit->id) {
                try {
                    $requiredQty = $this->unitConversion->convert($requiredQty, $ingredient->unit, $material->unit);
                } catch (RuntimeException $e) {
                    $warnings[] = "{$material->name}: {$e->getMessage()} Quantity consolidated unconverted.";
                }
            }

            // A recipe has no notion of the customer bringing an ingredient, so
            // the kitchen's need and our issue are the same number.
            $this->accumulate($requirements, $material, $line, $requiredQty, $requiredQty, 'recipe');
        }
    }

    /**
     * Convert a snapshot's quantities into the material's stock unit.
     *
     * @param  string[]  $warnings
     * @return array{0: float, 1: float}
     */
    private function toStockUnit(float $physical, float $ours, ?string $quotedUnitCode, $material, array &$warnings): array
    {
        $stockUnit = $material->unit;
        $quoted = $quotedUnitCode === null || trim($quotedUnitCode) === ''
            ? null
            : Unit::where('code', trim($quotedUnitCode))->first();

        if (! $stockUnit || ! $quoted || $quoted->id === $stockUnit->id) {
            return [$physical, $ours];
        }

        try {
            return [
                $this->unitConversion->convert($physical, $quoted, $stockUnit),
                $this->unitConversion->convert($ours, $quoted, $stockUnit),
            ];
        } catch (RuntimeException $e) {
            $warnings[] = "{$material->name}: {$e->getMessage()} Quantity consolidated unconverted.";

            return [$physical, $ours];
        }
    }

    /** @param  array<int, array>  $requirements */
    private function accumulate(
        array &$requirements,
        $material,
        CateringEstimateLine $line,
        float $physical,
        float $ours,
        string $source
    ): void {
        if (! isset($requirements[$material->id])) {
            $requirements[$material->id] = [
                'product_id' => $material->id,
                'name' => $material->name,
                'unit_code' => $material->unit?->code,
                'physical_qty' => 0.0,
                'required_qty' => 0.0,
                'customer_supplied_qty' => 0.0,
                'used_by' => [],
                'sources' => [],
            ];
        }

        $requirements[$material->id]['physical_qty'] += $physical;
        $requirements[$material->id]['required_qty'] += $ours;
        $requirements[$material->id]['customer_supplied_qty'] += max($physical - $ours, 0);
        $requirements[$material->id]['used_by'][$line->item_name] = true;
        $requirements[$material->id]['sources'][$source] = true;
    }

    /**
     * Read-only stock comparison (official balances; no lock, no mutation).
     *
     * Shortfall is measured against what OUR store has to issue, not what the
     * kitchen needs — asking a storeman to find chicken the customer is bringing
     * would be a shortage that does not exist.
     *
     * @param  array<int, array>  $requirements
     */
    private function attachStockPosition(array &$requirements, ?int $branchId): void
    {
        foreach ($requirements as $productId => &$row) {
            $row['physical_qty'] = round($row['physical_qty'], 3);
            $row['required_qty'] = round($row['required_qty'], 3);
            $row['customer_supplied_qty'] = round($row['customer_supplied_qty'], 3);
            $row['used_by'] = array_keys($row['used_by']);
            $row['sources'] = array_keys($row['sources']);

            $onHandQuery = StockBalance::query()->where('product_id', $productId);
            if ($branchId) {
                $onHandQuery->where('branch_id', $branchId);
            }
            $onHand = (float) $onHandQuery->sum('quantity_on_hand');

            $row['on_hand'] = round($onHand, 3);
            $row['shortfall'] = round(max($row['required_qty'] - $onHand, 0), 3);
        }
        unset($row);
    }
}
