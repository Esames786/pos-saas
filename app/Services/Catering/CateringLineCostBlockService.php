<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEstimateLineCostBlock;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * KASHIF-CATERING-LINE-SNAPSHOT-1 — pricing a booking line from its own copy.
 *
 * When a cost-block dish is put on a quotation, its blocks are COPIED onto the
 * line. Everything afterwards reads the copy. The master is free to change; the
 * quotation is not, and a dish re-rated in March must not rewrite what a
 * customer agreed to in January.
 *
 * The copy also gives the event somewhere to disagree with the dish. A wedding
 * needing three kilos of chicken where the ratio says two and a half is a fact
 * about that booking — the operator types it on the line, it is charged for, and
 * no other quotation and no product master hears about it.
 *
 * Three separations this service is careful to preserve:
 *
 *   calculated rate   what the blocks add up to, per unit of dish
 *   quoted rate       what the operator is actually charging
 *   line lump sums    charges that happen ONCE and never scale with quantity
 *
 * A lump sum is deliberately kept out of the calculated rate — a per-unit rate
 * containing a flat fee is wrong at every quantity except the one it was divided
 * by — and out of the quoted-rate override, which is a per-unit decision.
 */
class CateringLineCostBlockService
{
    /**
     * Copy the dish's blocks onto a freshly saved line and price it.
     *
     * Returns false when the product is not block-costed, so the caller can
     * leave a recipe or free-text line exactly as it always was.
     */
    public function snapshot(CateringEstimateLine $line): bool
    {
        if (! $line->product_id) {
            return false;
        }

        $profile = CateringProductProfile::where('product_id', $line->product_id)->first();
        if (! $profile?->usesBlocks()) {
            return false;
        }

        $blocks = CateringProductCostBlock::query()
            ->with(['material:id,name', 'unit:id,code'])
            ->where('product_id', $line->product_id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        if ($blocks->isEmpty()) {
            return false;
        }

        $dishQty = (float) $line->quantity;
        $asOf = now()->toDateString();

        DB::connection('tenant')->transaction(function () use ($line, $blocks, $dishQty, $asOf) {
            CateringEstimateLineCostBlock::where('catering_estimate_line_id', $line->id)->delete();

            foreach ($blocks as $index => $block) {
                $isMaterial = $block->isMaterial();
                $defaultQty = $isMaterial ? $block->materialRequiredFor($dishQty) : null;

                $rateBookRate = $isMaterial && $block->material_product_id
                    ? $this->materialRate($block->material_product_id, $asOf)
                    : null;

                $snapshot = new CateringEstimateLineCostBlock([
                    'catering_estimate_line_id' => $line->id,
                    'source_block_id' => $block->id,
                    'label' => $block->label,
                    'block_type' => $block->block_type,
                    'charge_basis' => $block->charge_basis,
                    'rate_basis' => $block->rateBasis(),
                    'rate' => $block->rate,
                    'material_product_id' => $block->material_product_id,
                    'material_name' => $block->material?->name,
                    'unit_code' => $block->unit?->code,
                    'quantity_per_unit' => $block->quantity_per_unit,
                    'default_material_qty' => $defaultQty,
                    // A fresh snapshot starts where the ratio says. The event may
                    // move it afterwards; that is a separate, deliberate act.
                    'event_material_qty' => $defaultQty,
                    'is_overridden' => false,
                    'material_rate_at_quote' => $rateBookRate,
                    'material_cost' => $rateBookRate === null || $defaultQty === null
                        ? null
                        : round($defaultQty * $rateBookRate, 4),
                    'sort_order' => $index + 1,
                ]);

                $snapshot->amount = $snapshot->computeAmount($dishQty);
                $snapshot->save();
            }
        });

        $this->reprice($line->refresh());

        return true;
    }

    /**
     * An event needs a different quantity of one material than the ratio says.
     *
     * Only this line moves. Not the product, not the block master, not another
     * booking that happens to use the same dish — the whole reason the snapshot
     * exists is so an operator can be specific about tonight without editing the
     * recipe everyone else is quoted from.
     */
    public function overrideMaterialQuantity(CateringEstimateLineCostBlock $snapshot, float $quantity): void
    {
        $this->assertEditable($snapshot);

        if ($quantity < 0) {
            throw new RuntimeException('A material quantity cannot be negative.');
        }

        $snapshot->forceFill([
            'event_material_qty' => $quantity,
            'is_overridden' => true,
        ])->save();

        $this->refreshSnapshotAmount($snapshot);
        $this->reprice($snapshot->line);
    }

    /** Put a material back on the dish's own ratio for this line. */
    public function resetMaterialQuantity(CateringEstimateLineCostBlock $snapshot): void
    {
        $this->assertEditable($snapshot);

        $snapshot->forceFill([
            'event_material_qty' => $snapshot->default_material_qty,
            'is_overridden' => false,
        ])->save();

        $this->refreshSnapshotAmount($snapshot);
        $this->reprice($snapshot->line);
    }

    /**
     * The dish quantity changed, so ratio-derived requirements follow it.
     *
     * A material the operator has deliberately overridden does NOT follow. They
     * said three kilos; silently making it four because the order grew would
     * discard a decision somebody made on purpose. It stays, visibly overridden,
     * until they reset it.
     */
    public function recalculateForQuantity(CateringEstimateLine $line): void
    {
        $dishQty = (float) $line->quantity;

        foreach ($this->snapshotsFor($line) as $snapshot) {
            if ($snapshot->isMaterial() && $snapshot->quantity_per_unit !== null) {
                $default = round((float) $snapshot->quantity_per_unit * $dishQty, 4);

                $snapshot->forceFill([
                    'default_material_qty' => $default,
                    'event_material_qty' => $snapshot->is_overridden
                        ? $snapshot->event_material_qty
                        : $default,
                ])->save();
            }

            $this->refreshSnapshotAmount($snapshot, $dishQty);
        }

        $this->reprice($line->refresh());
    }

    /**
     * Recompute the line's calculated rate, lump sums and amount from its own
     * snapshot — never from the product master, which may have moved on.
     *
     * The quoted rate is left alone unless it has never been set: an operator's
     * agreed price is theirs, and repricing the blocks underneath it must not
     * quietly change what the customer was told.
     */
    public function reprice(CateringEstimateLine $line): void
    {
        $snapshots = $this->snapshotsFor($line);

        if ($snapshots->isEmpty()) {
            return;
        }

        $dishQty = (float) $line->quantity;
        $perUnit = 0.0;
        $lumpSum = 0.0;

        foreach ($snapshots as $snapshot) {
            if ($snapshot->isLumpSum()) {
                $lumpSum += (float) $snapshot->amount;

                continue;
            }

            // What this part adds to ONE unit of the dish. Derived from the
            // amount so an overridden quantity is reflected in the rate rather
            // than only in the total.
            $perUnit += $dishQty > 0 ? (float) $snapshot->amount / $dishQty : 0.0;
        }

        $calculated = round($perUnit, 2);

        // The override REASON is what says an operator chose this price. Without
        // one the quoted rate is simply tracking the calculation and follows it;
        // with one it is a decision somebody made, and repricing the blocks
        // underneath must not quietly move what the customer was told.
        $quoted = $line->rate_override_reason === null
            ? $calculated
            : (float) $line->rate;

        $line->forceFill([
            'calculated_rate' => $calculated,
            'rate' => $quoted,
            'lump_sum_amount' => round($lumpSum, 2),
            'amount' => round($quoted * $dishQty + $lumpSum, 2),
        ])->save();

        $this->recalculateDocument($line);
    }

    /**
     * Quote a different price from the one the blocks calculated.
     *
     * A reason is required, because "700 calculated, 650 quoted" with nothing
     * beside it is indistinguishable from a typing mistake six months later.
     * Nothing about the dish or its blocks changes — this is a decision about
     * one quotation.
     */
    public function overrideQuotedRate(CateringEstimateLine $line, float $quotedRate, string $reason): void
    {
        if (! $line->estimate?->isDraft()) {
            throw new RuntimeException('A sent quotation cannot be repriced — revise it instead.');
        }

        if ($quotedRate < 0) {
            throw new RuntimeException('A quoted rate cannot be negative.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException(
                'Quoting a different rate from the calculated one needs a reason — '
                .'without it nobody can tell a discount from a mistake.'
            );
        }

        $line->forceFill([
            'rate' => round($quotedRate, 2),
            'rate_override_reason' => trim($reason),
            'amount' => round($quotedRate * (float) $line->quantity + (float) $line->lump_sum_amount, 2),
        ])->save();

        $this->recalculateDocument($line);
    }

    /**
     * Put a line back on the price its blocks calculate.
     *
     * Clearing the reason is what does it: the reason is the signal that says an
     * operator chose this number, and without one the quoted rate simply tracks
     * the calculation again.
     */
    public function useCalculatedRate(CateringEstimateLine $line): void
    {
        if (! $line->estimate?->isDraft()) {
            throw new RuntimeException('A sent quotation cannot be repriced — revise it instead.');
        }

        $line->forceFill(['rate_override_reason' => null])->save();

        $this->reprice($line->refresh());
    }

    /**
     * A changed line changes the quotation. Without this the screen shows a line
     * at 1,960 inside a document that still totals 1,910, and whichever figure
     * the customer is given, one of them is wrong.
     */
    private function recalculateDocument(CateringEstimateLine $line): void
    {
        if ($estimate = $line->estimate) {
            app(CateringEstimateService::class)->recalculateTotals($estimate);
        }
    }

    /** @return \Illuminate\Support\Collection<int, CateringEstimateLineCostBlock> */
    public function snapshotsFor(CateringEstimateLine $line)
    {
        return CateringEstimateLineCostBlock::where('catering_estimate_line_id', $line->id)
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    private function refreshSnapshotAmount(CateringEstimateLineCostBlock $snapshot, ?float $dishQty = null): void
    {
        $dishQty ??= (float) $snapshot->line->quantity;

        $cost = $snapshot->material_rate_at_quote === null || $snapshot->event_material_qty === null
            ? null
            : round((float) $snapshot->event_material_qty * (float) $snapshot->material_rate_at_quote, 4);

        $snapshot->forceFill([
            'material_cost' => $cost,
            'amount' => $snapshot->computeAmount($dishQty),
        ])->save();
    }

    private function assertEditable(CateringEstimateLineCostBlock $snapshot): void
    {
        if (! $snapshot->line?->estimate?->isDraft()) {
            throw new RuntimeException(
                'This quotation has been sent — its costing is history. Revise it to change anything.'
            );
        }
    }

    private function materialRate(int $productId, string $asOf): ?float
    {
        $rate = CateringMaterialRate::query()
            ->where('product_id', $productId)
            ->whereDate('effective_from', '<=', $asOf)
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->value('rate');

        return $rate === null ? null : (float) $rate;
    }
}
