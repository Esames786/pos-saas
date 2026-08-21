<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringCostSnapshot;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * KASHIF-CATERING-COSTING-SOURCE-1 — one authority over a mixed estimate.
 *
 * A quotation may legitimately contain dishes costed different ways:
 *
 *     Chicken Karahi   blocks    -> CateringCostBlockService
 *     Biryani          recipe    -> CateringRecipeCostingService
 *     Chicken Handi    blocks    -> CateringCostBlockService
 *
 * So the question "is this estimate ready" is answered PER LINE, against that
 * line's own active costing source, and never once for the whole document. An
 * estimate-level `if blocks … else recipe …` would let one dish's arrangement
 * decide another dish's verdict.
 *
 * Dormant configuration takes no part. A dish costed from blocks whose old
 * recipe is incomplete is ready; the recipe is stored, not consulted.
 *
 * THE FAIL-CLOSED BOUNDARY LIVES HERE, and this is the reason the class exists
 * rather than the dispatch being written into each caller:
 *
 *   CateringCostBlockService::expectedMaterialCost() is a calculator. It is
 *   read-only and does not throw. A material with no rate in the Rate Book is
 *   excluded from the total and reported by readiness() instead of guessed —
 *   which means that called on its own, it returns an UNDERSTATED number.
 *
 * That number would not look like a failure. It would look like an unusually
 * good margin, and it would be persisted onto the estimate and read back later
 * as fact. So snapshot() checks readiness itself before computing anything, and
 * refuses an estimate that is not ready. Three callers each remembering to check
 * first is three chances to forget.
 *
 * A wrong-but-plausible cost is worse than a blocked quotation.
 *
 * The two engines never call each other. Dispatch belongs to exactly one place,
 * and this is it.
 */
class CateringEstimateCostingService
{
    public function __construct(
        private readonly CateringRecipeCostingService $recipes,
        private readonly CateringCostBlockService $blocks,
        private readonly CateringDocumentLock $locks,
    ) {}

    /**
     * May this estimate's recorded costing basis still be written?
     *
     * Deliberately the SAME two questions the commercial writers ask, in the same
     * order, from the same authority — a cost basis recorded against a booking
     * that has been invoiced or cancelled is as wrong as a price change would be,
     * and having two definitions of "still open" is how they drift apart.
     *
     * Only ever called with an estimate that was re-read under the document lock.
     */
    private function assertCostingBasisEditable(CateringEstimate $estimate): void
    {
        if (! $estimate->isDraft()) {
            throw new RuntimeException(
                "Estimate {$estimate->displayNo()} is {$estimate->status}; its costing basis is frozen. Revise it instead."
            );
        }

        if (! $this->locks->isCommerciallyOpen($estimate)) {
            throw new RuntimeException(
                "Estimate {$estimate->displayNo()} belongs to a booking that has been invoiced, completed or "
                .'cancelled; its costing basis is frozen.'
            );
        }
    }

    /**
     * Whether every line can be costed under its own active authority.
     *
     * @return array{ready: bool, blockers: string[], warnings: string[], result: array}
     */
    public function readiness(CateringEstimate $estimate, ?string $asOfDate = null): array
    {
        $result = $this->calculate($estimate, $asOfDate);
        $blockers = [];
        $warnings = [];

        foreach ($result['lines'] as $line) {
            $blockers = array_merge($blockers, $line['blockers']);
            $warnings = array_merge($warnings, $line['warnings']);
        }

        return [
            'ready' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'result' => $result,
        ];
    }

    /**
     * The full breakdown, line by line, each from its own engine. Pure.
     *
     * Every line records which authority costed it, so a snapshot read back in
     * six months says not just what the cost was but where the number came from.
     */
    public function calculate(CateringEstimate $estimate, ?string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?: now()->toDateString();
        $lines = [];
        $total = 0.0;

        $estimateLines = $estimate->lines()
            ->with(['product.activeRecipe.ingredients.product.unit', 'product.unit', 'unit'])
            ->get();

        foreach ($estimateLines as $line) {
            $breakdown = $this->costingModeFor($line) === CateringProductProfile::COSTING_BLOCKS
                ? $this->costBlockLine($line, $asOfDate)
                : $this->costRecipeLine($line, $asOfDate);

            $lines[] = $breakdown;
            $total += $breakdown['line_cost'];
        }

        return [
            'as_of_date' => $asOfDate,
            'lines' => $lines,
            'total_material_cost' => round($total, 2),
            'warnings' => collect($lines)->flatMap(fn ($l) => $l['warnings'])->unique()->values()->all(),
        ];
    }

    /**
     * Recompute and persist the costing basis for a DRAFT estimate.
     *
     * Refuses an estimate that is not ready. See the class note: the underlying
     * calculators do not throw on a missing rate, so this is the only layer at
     * which an understated cost can be stopped from being written down.
     */
    public function snapshot(CateringEstimate $estimate, ?int $userId = null, ?string $asOfDate = null): CateringCostSnapshot
    {
        // CAT-RATE-011 / KASHIF-CATERING-LIFECYCLE-LOCK-1.
        //
        // "Its costing basis is frozen" was checked here on the model handed in,
        // outside the transaction that persists — the same shape every commercial
        // writer was moved off. It failed exactly as they did: the write waited
        // for the document lock, woke up after Send had committed, and recorded a
        // costing basis against a quotation that was by then SENT.
        //
        // The check now happens under the lock, on a re-read, and the readiness
        // calculation runs inside it too so the lines it reads cannot move while
        // it is reading them.
        return DB::connection('tenant')->transaction(function () use ($estimate, $userId, $asOfDate) {
            $this->locks->refreshEstimate($estimate);
            $this->assertCostingBasisEditable($estimate);

            $readiness = $this->readiness($estimate, $asOfDate);

            if (! $readiness['ready']) {
                throw new RuntimeException(
                    'The cost of this estimate cannot be worked out yet, so it will not be recorded: '
                    .implode(' ', $readiness['blockers'])
                );
            }

            $result = $readiness['result'];

            foreach ($result['lines'] as $breakdown) {
                if ($breakdown['line_id']) {
                    CateringEstimateLine::whereKey($breakdown['line_id'])->update([
                        'estimated_unit_cost' => $breakdown['unit_cost'],
                        'estimated_cost_total' => $breakdown['line_cost'],
                    ]);
                }
            }

            $estimate->forceFill(['estimated_material_cost' => $result['total_material_cost']])->save();

            return CateringCostSnapshot::create([
                'catering_estimate_id' => $estimate->id,
                'breakdown' => $result,
                'total_material_cost' => $result['total_material_cost'],
                'computed_at' => now(),
                'computed_by_user_id' => $userId,
            ]);
        });
    }

    /**
     * Can this product be switched INTO the given costing source right now?
     *
     * Estimate readiness asks whether a quotation can be sent. This asks
     * something earlier: whether a dish may be moved into an authority that
     * cannot yet cost it. Without this, an operator could save "Cost Blocks" on
     * a dish with no blocks, and only discover at send time that the dish had
     * been unquotable since the moment they saved it.
     *
     * Dispatch lives here, with all the other dispatch, so the two engines still
     * never learn about each other.
     *
     * @return array{ready: bool, blockers: string[], warnings: string[]}
     */
    public function productReadinessFor(Product $product, string $targetMode, ?string $asOfDate = null): array
    {
        if ($targetMode === CateringProductProfile::COSTING_BLOCKS) {
            $readiness = $this->blocks->readiness($product->id, $asOfDate);

            return [
                'ready' => $readiness['ready'],
                'blockers' => $readiness['blockers'],
                'warnings' => $readiness['warnings'],
            ];
        }

        return $this->recipes->productReadiness($product, $asOfDate);
    }

    /** Which authority costs this line. Absent or unrecognised means recipe. */
    private function costingModeFor(CateringEstimateLine $line): string
    {
        if (! $line->product_id) {
            return CateringProductProfile::COSTING_RECIPE;
        }

        $profile = CateringProductProfile::where('product_id', $line->product_id)->first();

        return $profile?->costingMode() ?? CateringProductProfile::COSTING_RECIPE;
    }

    /** A recipe-costed line, in the shape the recipe engine already produces. */
    private function costRecipeLine(CateringEstimateLine $line, string $asOfDate): array
    {
        $breakdown = $this->recipes->costLineFor($line, $asOfDate);
        $verdict = $this->recipes->verdictForLine($breakdown);

        return $breakdown + [
            'costing_mode' => CateringProductProfile::COSTING_RECIPE,
            'blockers' => $verdict['blockers'],
            'warnings' => $verdict['warnings'],
        ];
    }

    /**
     * A block-costed line.
     *
     * Cost comes from the material consumption and the Rate Book — never from
     * the commercial blocks. What the customer is charged for chicken and what
     * that chicken costs are different numbers, and snapshotting the charge as
     * cost would report a margin that never existed.
     *
     * CAT-COST-001 — WHICH STRUCTURE, AND WHICH RATE, ARE DIFFERENT QUESTIONS.
     *
     * This read the PRODUCT MASTER for both, which made the internal cost of a
     * quotation describe a dish rather than the quotation. It ignored the event's
     * own material quantity, ignored Customer Supplied, and would refuse to cost
     * a line for a material the master still lists but the quotation never used.
     * The operator saw one breakdown in Cost Details and a different margin above
     * it.
     *
     * The split now is deliberate:
     *
     *   STRUCTURE   — which materials, how much of each, who supplies them —
     *                 comes from the line's frozen snapshot. Those were decisions
     *                 made about this booking and the master cannot overrule them.
     *   COST RATE   — what a material is worth — stays with the as-of Material
     *                 Cost Rate Book, exactly as the costing policy already says.
     *
     * A line with no snapshot keeps the old master-derived behaviour, which is
     * the only sensible answer for a dish that has not been priced from blocks
     * yet.
     */
    private function costBlockLine(CateringEstimateLine $line, string $asOfDate): array
    {
        $quantity = (float) $line->quantity;
        $productId = (int) $line->product_id;

        $snapshots = $line->costBlocks()->get();

        if ($snapshots->isNotEmpty()) {
            $readiness = $this->blocks->readinessForSnapshots($snapshots, $asOfDate);
            $materials = $this->blocks->snapshotMaterialBreakdown($snapshots, $asOfDate);
        } else {
            $readiness = $this->blocks->readiness($productId, $asOfDate);
            $materials = $this->blocks->expectedMaterialBreakdown($productId, $quantity, [], $asOfDate);
        }

        $lineCost = round(collect($materials)->sum('cost'), 2);

        $blockers = array_map(
            fn (string $blocker) => "'{$line->item_name}' — Cost Blocks: {$blocker}",
            $readiness['blockers']
        );
        $warnings = array_map(
            fn (string $warning) => "'{$line->item_name}' — Cost Blocks: {$warning}",
            $readiness['warnings']
        );

        return [
            'line_id' => $line->id,
            'item_name' => $line->item_name,
            'product_id' => $productId,
            'quantity' => $quantity,
            'method' => 'blocks',
            'costing_mode' => CateringProductProfile::COSTING_BLOCKS,
            'batch_count' => null,
            'ingredients' => array_map(fn (array $material) => [
                'product_id' => $material['product_id'],
                'name' => $material['name'],
                'required_qty' => $material['required_qty'],
                'required_unit' => $material['unit_code'],
                'priced_qty' => $material['required_qty'],
                'priced_unit' => $material['unit_code'],
                'rate' => $material['rate'],
                'rate_source' => $material['rate'] === null ? null : 'rate_book',
                'cost' => $material['cost'],
                'warning' => null,
            ], $materials),
            'unit_cost' => $quantity > 0 ? round($lineCost / $quantity, 4) : null,
            'line_cost' => $lineCost,
            'warning' => null,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }
}
