<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use Illuminate\Support\Collection;

/**
 * KASHIF-CATERING-COST-BLOCKS-1 — pricing a dish from its parts.
 *
 * The recipe path answers "what does this dish cost me". This one answers "what
 * does this dish sell for", by adding up named blocks. Both read the same
 * Material Rate Book, so beef still has one price in one place.
 *
 * READ-ONLY, deliberately, exactly like CateringRecipeCostingService: it reads
 * blocks, materials and the rate book, and returns numbers. It never writes
 * stock, GL, estimates or products. Whoever calls it decides what to persist.
 *
 * The two numbers a material block carries are independent and both matter:
 *
 *   rate               charged per unit of the DISH   → the customer's bill
 *   quantity_per_unit  material per unit of the DISH  → the kitchen sheet
 *
 * A 20 KG karahi charges 20 × 200 for chicken and draws 20 × 0.5 = 10 KG. Using
 * one number for both would make either the bill or the store wrong.
 */
class CateringCostBlockService
{
    // Aliases of the profile's own constants — the model owns the vocabulary, so
    // there is one definition of what 'blocks' means, not two that can drift.
    public const MODE_BLOCKS = CateringProductProfile::COSTING_BLOCKS;

    public const MODE_RECIPE = CateringProductProfile::COSTING_RECIPE;

    /** Is this dish priced from blocks rather than a recipe? */
    public function usesBlocks(Product $product): bool
    {
        $profile = CateringProductProfile::where('product_id', $product->id)->first();

        return $profile?->usesBlocks() ?? false;
    }

    /** @return Collection<int, CateringProductCostBlock> */
    public function blocksFor(int $productId): Collection
    {
        return CateringProductCostBlock::query()
            ->with(['material:id,name,sku,is_stock_tracked', 'unit:id,code'])
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The dish rate — what one unit sells for.
     *
     * Lump-sum blocks are deliberately EXCLUDED. A per-unit rate that silently
     * contained a flat setup fee would be wrong at every quantity except one,
     * so a lump sum only ever appears on a line, never in the per-unit rate.
     */
    public function rateFor(int $productId): float
    {
        return round(
            $this->blocksFor($productId)
                ->reject(fn (CateringProductCostBlock $b) => $b->isLumpSum())
                ->sum(fn (CateringProductCostBlock $b) => (float) $b->rate),
            2
        );
    }

    /**
     * Price and material requirement for one booking line.
     *
     * $zeroed lists block ids the customer is supplying themselves. Such a block
     * is dropped from BOTH the charge and the requirement — charging for chicken
     * the customer brought, or asking the store for it, are each wrong on their
     * own and wrong together.
     *
     * @param  array<int, int>  $zeroed
     * @return array{
     *   rate: float, total: float,
     *   blocks: array<int, array>, materials: array<int, array>
     * }
     */
    public function priceLine(int $productId, float $quantity, array $zeroed = [], ?string $asOfDate = null): array
    {
        $zeroed = array_map('intval', $zeroed);

        $lines = [];
        $materials = [];
        $total = 0.0;
        $perUnit = 0.0;

        foreach ($this->blocksFor($productId) as $block) {
            $isZeroed = in_array((int) $block->id, $zeroed, true);

            $amount = $isZeroed ? 0.0 : $block->amountFor($quantity);
            $required = $isZeroed ? 0.0 : $block->materialRequiredFor($quantity);

            $total += $amount;
            if (! $isZeroed && ! $block->isLumpSum()) {
                $perUnit += (float) $block->rate;
            }

            $lines[] = [
                'block_id' => (int) $block->id,
                'label' => $block->label,
                'type' => $block->block_type,
                'basis' => $block->charge_basis,
                'rate' => (float) $block->rate,
                'amount' => $amount,
                'customer_supplied' => $isZeroed,
                'material_product_id' => $block->material_product_id,
                'required_qty' => $required,
                'unit_code' => $block->unit?->code,
            ];

            if ($required > 0 && $block->material_product_id) {
                $materials[] = [
                    'product_id' => (int) $block->material_product_id,
                    'name' => $block->material?->name ?? $block->label,
                    'required_qty' => $required,
                    'unit_code' => $block->unit?->code,
                ];
            }
        }

        return [
            'rate' => round($perUnit, 2),
            'total' => round($total, 2),
            'blocks' => $lines,
            'materials' => $materials,
        ];
    }

    /**
     * What the materials in a line actually cost, from the Rate Book.
     *
     * Separate from the charge on purpose. The charge is what Kashif decided to
     * sell at; this is what he expects to pay. The gap between them is the real
     * margin, and it can only be seen if the two are computed apart.
     */
    public function expectedMaterialCost(int $productId, float $quantity, array $zeroed = [], ?string $asOfDate = null): float
    {
        $asOfDate = $asOfDate ?: now()->toDateString();
        $zeroed = array_map('intval', $zeroed);
        $cost = 0.0;

        foreach ($this->blocksFor($productId) as $block) {
            if (! $block->isMaterial() || in_array((int) $block->id, $zeroed, true)) {
                continue;
            }

            $required = $block->materialRequiredFor($quantity);
            if ($required <= 0 || ! $block->material_product_id) {
                continue;
            }

            $rate = CateringMaterialRate::query()
                ->where('product_id', $block->material_product_id)
                ->whereDate('effective_from', '<=', $asOfDate)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->value('rate');

            // No rate book entry means no honest cost. Skipping it silently
            // would understate cost and overstate margin, so it is left out and
            // reported by readiness() instead of being guessed.
            if ($rate === null) {
                continue;
            }

            $cost += $required * (float) $rate;
        }

        return round($cost, 4);
    }

    /**
     * Whether a dish can be quoted from its blocks at all.
     *
     * Fails closed for the same reason the recipe path does: a quotation must
     * never leave the business on a cost nobody can stand behind.
     *
     * @return array{ready: bool, blockers: array<int, string>, warnings: array<int, string>}
     */
    public function readiness(int $productId, ?string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?: now()->toDateString();
        $blocks = $this->blocksFor($productId);
        $blockers = [];
        $warnings = [];

        if ($blocks->isEmpty()) {
            return [
                'ready' => false,
                'blockers' => ['This dish is priced from cost blocks but has none defined.'],
                'warnings' => [],
            ];
        }

        foreach ($blocks as $block) {
            if ((float) $block->rate <= 0) {
                $warnings[] = "'{$block->label}' has no rate, so it adds nothing to the price.";
            }

            if (! $block->isMaterial()) {
                continue;
            }

            if (! $block->material_product_id) {
                $blockers[] = "'{$block->label}' is a material block but no material is selected.";

                continue;
            }

            if ($block->quantity_per_unit === null || (float) $block->quantity_per_unit <= 0) {
                $warnings[] = "'{$block->label}' has no quantity per unit, so the kitchen sheet "
                    .'will not ask the store for it.';
            }

            $hasRate = CateringMaterialRate::query()
                ->where('product_id', $block->material_product_id)
                ->whereDate('effective_from', '<=', $asOfDate)
                ->exists();

            if (! $hasRate) {
                $blockers[] = "'{$block->label}' has no rate in the Material Rate Book, "
                    .'so its cost cannot be worked out.';
            }
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }
}
