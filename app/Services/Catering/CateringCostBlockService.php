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
                // Not the raw rate: a per-material rate is per KG of CHICKEN,
                // and what the dish's rate owes to it is that times the ratio.
                ->sum(fn (CateringProductCostBlock $b) => $b->contributionPerDishUnit()),
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
                $perUnit += $block->contributionPerDishUnit();
            }

            $lines[] = [
                'block_id' => (int) $block->id,
                'label' => $block->label,
                'type' => $block->block_type,
                'basis' => $block->charge_basis,
                'rate_basis' => $block->rateBasis(),
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
        return round(
            collect($this->expectedMaterialBreakdown($productId, $quantity, $zeroed, $asOfDate))
                ->sum('cost'),
            4
        );
    }

    /**
     * The same calculation, itemised — what each material contributes to cost.
     *
     * The orchestrator needs the detail to record a snapshot an operator can
     * read afterwards; expectedMaterialCost() is this, summed. One computation,
     * so a total can never disagree with the lines it came from.
     *
     * A material with no rate is reported with `rate => null` and zero cost
     * rather than guessed. It is excluded from the total for the same reason,
     * and readiness() is what refuses the quotation over it.
     *
     * @param  array<int, int>  $zeroed
     * @return array<int, array{product_id: int, name: string, required_qty: float, unit_code: ?string, rate: ?float, cost: float}>
     */
    public function expectedMaterialBreakdown(int $productId, float $quantity, array $zeroed = [], ?string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?: now()->toDateString();
        $zeroed = array_map('intval', $zeroed);
        $entries = [];

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

            $entries[] = [
                'product_id' => (int) $block->material_product_id,
                'name' => $block->material?->name ?? $block->label,
                'required_qty' => $required,
                'unit_code' => $block->unit?->code,
                'rate' => $rate === null ? null : round((float) $rate, 4),
                'cost' => $rate === null ? 0.0 : round($required * (float) $rate, 4),
            ];
        }

        return $entries;
    }

    /**
     * Whether a dish can be quoted from its blocks at all.
     *
     * Fails closed for the same reason the recipe path does: a quotation must
     * never leave the business on a cost nobody can stand behind.
     *
     * @return array{ready: bool, blockers: array<int, string>, warnings: array<int, string>}
     */
    /**
     * CAT-COST-001 — the same breakdown, taken from a QUOTATION instead of a dish.
     *
     * Structure and quantity come from the frozen snapshot; the rate comes from
     * the as-of Material Cost Rate Book, exactly as the master-derived version
     * does. That split is the point: the dish may have been re-costed since, but
     * what this booking agreed to consume is settled, while what a kilo of
     * chicken is worth is a question with a current answer.
     *
     * A customer-supplied material contributes ZERO cost and is still listed —
     * the business did not buy it, so recording a cost it never incurred would
     * understate the margin on exactly the arrangement designed to protect it,
     * but hiding the row would make the breakdown disagree with Cost Details.
     *
     * @param  iterable<int, \App\Models\Tenant\CateringEstimateLineCostBlock>  $snapshots
     */
    public function snapshotMaterialBreakdown(iterable $snapshots, ?string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?: now()->toDateString();
        $entries = [];

        foreach ($snapshots as $snapshot) {
            if (! $snapshot->isMaterial() || ! $snapshot->material_product_id) {
                continue;
            }

            // What the KITCHEN needs, which is what has to be bought — not what
            // our store hands over. Those differ only when the customer supplies
            // it, and that case is charged nothing just below.
            $required = $snapshot->physicalRequirement();
            if ($required <= 0) {
                continue;
            }

            $rate = CateringMaterialRate::query()
                ->where('product_id', $snapshot->material_product_id)
                ->whereDate('effective_from', '<=', $asOfDate)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->value('rate');

            $supplied = $snapshot->isCustomerSupplied();

            $entries[] = [
                'product_id' => (int) $snapshot->material_product_id,
                'name' => $snapshot->material_name ?: $snapshot->label,
                'required_qty' => $required,
                'unit_code' => $snapshot->unit_code,
                'rate' => $rate === null ? null : round((float) $rate, 4),
                'cost' => ($supplied || $rate === null) ? 0.0 : round($required * (float) $rate, 4),
                'is_customer_supplied' => $supplied,
            ];
        }

        return $entries;
    }

    /**
     * CAT-COST-001 — readiness for the QUOTATION being costed, not for the dish.
     *
     * Two failures this prevents, and they point in opposite directions:
     *
     *   it must not say READY because today's master happens to be valid while
     *   the quotation snapshot is missing a rate;
     *
     *   and it must not say NOT READY because somebody edited the dish after
     *   this quotation was agreed. The customer signed the old one.
     *
     * A customer-supplied material needs no cost rate to be costable — its cost
     * to us is zero by contract, and blocking a send over a missing rate for
     * something we are not buying would refuse a perfectly valid quotation.
     *
     * @param  iterable<int, \App\Models\Tenant\CateringEstimateLineCostBlock>  $snapshots
     */
    public function readinessForSnapshots(iterable $snapshots, ?string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?: now()->toDateString();
        $blockers = [];
        $warnings = [];
        $seen = false;

        foreach ($snapshots as $snapshot) {
            $seen = true;

            if ((float) $snapshot->rate <= 0 && ! $snapshot->isCustomerSupplied()) {
                $warnings[] = "'{$snapshot->label}' has no rate, so it adds nothing to the price.";
            }

            if (! $snapshot->isMaterial()) {
                continue;
            }

            if (! $snapshot->material_product_id) {
                $blockers[] = "'{$snapshot->label}' is a material block but no material is selected.";

                continue;
            }

            if ($snapshot->isCustomerSupplied()) {
                $warnings[] = "'{$snapshot->label}' is supplied by the customer, so it costs the business nothing.";

                continue;
            }

            if ($snapshot->physicalRequirement() <= 0) {
                $warnings[] = "'{$snapshot->label}' has no quantity on this booking, so the kitchen sheet "
                    .'will not ask the store for it.';

                continue;
            }

            $hasRate = CateringMaterialRate::query()
                ->where('product_id', $snapshot->material_product_id)
                ->whereDate('effective_from', '<=', $asOfDate)
                ->exists();

            if (! $hasRate) {
                $blockers[] = "'{$snapshot->label}' has no rate in the Material Rate Book, "
                    .'so its cost cannot be worked out.';
            }
        }

        if (! $seen) {
            return [
                'ready' => false,
                'blockers' => ['This line is priced from cost blocks but its breakdown is empty.'],
                'warnings' => [],
            ];
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

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
