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
 *
 * KASHIF-CATERING-LIFECYCLE-LOCK-1 — TWO KINDS OF METHOD IN HERE.
 *
 * Everything that can change what a customer is quoted now comes in two halves,
 * and the distinction is not cosmetic:
 *
 *   PUBLIC OPERATIONS       enter the transaction, take the document lock, re-read
 *                           the target under it, prove the quotation may still be
 *                           edited, then mutate.
 *
 *   *Locked() HELPERS       assume the caller is already inside that critical
 *                           section. They do no checking of their own because
 *                           any check they could make would be the stale one.
 *
 * The old design had a single set of methods that each asked
 * `$snapshot->line?->estimate?->isDraft()` — a cached relation, loaded before
 * anything was locked. An operator could open a booking, the answer would be
 * "draft", the write would queue behind a row, Send would commit, the write
 * would wake up and land on a sent quotation. The check was real; it was just
 * answering a question about the past.
 */
class CateringLineCostBlockService
{
    public function __construct(private readonly CateringDocumentLock $locks) {}

    /**
     * Copy the dish's blocks onto a freshly saved line and price it.
     *
     * Returns false when the product is not block-costed, so the caller can
     * leave a recipe or free-text line exactly as it always was.
     */
    public function snapshotLocked(CateringEstimateLine $line): bool
    {
        $this->locks->assertInsideCriticalSection('snapshotLocked');

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
                    // Carried onto the line so a quotation remembers whether its
                    // price followed the house rate or was chosen for this
                    // customer — which decides whether a later house change is
                    // even offered to it.
                    'commercial_rate_source' => $block->rateSource(),
                    // Whether this charge IS the Making charge, frozen with the
                    // quote — a later reclassification of the dish must not
                    // rewrite what an old document meant.
                    'charge_role' => $block->charge_role,
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
                    // A fresh line is supplied by the business until somebody
                    // says otherwise.
                    'is_customer_supplied' => false,
                    'sort_order' => $index + 1,
                ]);

                // Both through the model, so there is one cost rule and one
                // charge rule rather than a second copy of each living here.
                $snapshot->material_cost = $snapshot->computeMaterialCost();
                $snapshot->amount = $snapshot->computeAmount($dishQty);
                $snapshot->save();
            }
        });

        $this->repriceLocked($line->refresh());

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
        if ($quantity < 0) {
            throw new RuntimeException('A material quantity cannot be negative.');
        }

        DB::connection('tenant')->transaction(function () use ($snapshot, $quantity) {
            // The model handed in was loaded before any lock and proves nothing.
            // These three are the current ones.
            [, $line, $locked] = $this->locks->editableSnapshot($snapshot);

            $locked->forceFill([
                'event_material_qty' => $quantity,
                'is_overridden' => true,
            ])->save();

            $this->refreshSnapshotAmount($locked);
            $this->repriceLocked($line);
        });
    }

    /**
     * The customer is bringing this material themselves — or has stopped.
     *
     * Deliberately a FLAG, not a quantity edit. The kitchen still needs its five
     * kilos of chicken; what changes is who hands them over and who pays for
     * them. Writing the requirement down to zero would lose the fact the kitchen
     * sheet depends on, and the making charge would go on being charged against
     * a dish that appeared to need no ingredients.
     */
    public function setCustomerSupplied(
        CateringEstimateLineCostBlock $snapshot,
        bool $supplied,
        ?float $suppliedQty = null,
    ): void {
        DB::connection('tenant')->transaction(function () use ($snapshot, $supplied, $suppliedQty) {
            [, $line, $locked] = $this->locks->editableSnapshot($snapshot);

            if (! $locked->isMaterial()) {
                throw new RuntimeException(
                    "'{$locked->label}' is a charge, not a material — there is nothing for a customer to bring. "
                    .'Making and packing are the work, and the work is still being done.'
                );
            }

            // KASHIF-ORDER-PUNCH §A: the item-level switch (legacy "Allow Party
            // Meat"). Gates NEW settings only — turning a flag off never
            // rewrites a split an old booking already agreed to.
            if (($supplied || ($suppliedQty !== null && $suppliedQty > 0))
                && ! $this->partySupplyAllowed($line)) {
                throw new RuntimeException(
                    'Party supply is OFF for this item — turn on "Allow Party Meat" on its Catering Products screen first.'
                );
            }

            // KASHIF-PARTIAL-SUPPLY-1: three states, one authority.
            //   full   — the flag, as always; a partial figure cannot coexist.
            //   split  — a positive quantity, clamped to what the dish needs;
            //            meaningless without a tracked material quantity.
            //   ours   — neither.
            // A split that reaches (or over-types past) the whole requirement
            // IS the full case, and is normalized to the flag so every reader —
            // rate impact, prints, the panel — sees one truth for it.
            if ($supplied) {
                $suppliedQty = null;
            } elseif ($suppliedQty !== null && $suppliedQty > 0) {
                if ($locked->event_material_qty === null) {
                    throw new RuntimeException(
                        "'{$locked->label}' has no tracked quantity on this line, so a partial split has no honest proportion. "
                        .'Set its quantity first, or mark the whole material customer-supplied.'
                    );
                }
                if ($suppliedQty >= $locked->physicalRequirement()) {
                    $supplied = true;
                    $suppliedQty = null;
                } else {
                    $suppliedQty = round($suppliedQty, 4);
                }
            } else {
                $suppliedQty = null;
            }

            $locked->forceFill([
                'is_customer_supplied' => $supplied,
                'customer_supplied_qty' => $suppliedQty,
            ])->save();

            $this->refreshSnapshotAmount($locked);
            $this->repriceLocked($line);
        });
    }

    /**
     * Record the operator's two additive answers in one locked change:
     * what WE provide plus what the PARTY provides equals what the kitchen
     * receives. The snapshot continues to store total physical requirement
     * and customer share, so every existing quotation/production/store reader
     * keeps one authority and derives our share as total minus customer.
     */
    public function setSupplySplit(
        CateringEstimateLineCostBlock $snapshot,
        float $ourQty,
        float $customerQty,
    ): void {
        if ($ourQty < 0 || $customerQty < 0) {
            throw new RuntimeException('Our quantity and party quantity cannot be negative.');
        }

        DB::connection('tenant')->transaction(function () use ($snapshot, $ourQty, $customerQty) {
            [, $line, $locked] = $this->locks->editableSnapshot($snapshot);

            if (! $locked->isMaterial()) {
                throw new RuntimeException("'{$locked->label}' is a charge, not a material, so it has no supply split.");
            }
            if ($customerQty > 0 && ! $this->partySupplyAllowed($line)) {
                throw new RuntimeException(
                    'Party supply is OFF for this item — turn on "Allow Party Meat" on its Catering Products screen first.'
                );
            }

            $ourQty = round($ourQty, 4);
            $customerQty = round($customerQty, 4);
            $total = round($ourQty + $customerQty, 4);

            $locked->forceFill([
                'event_material_qty' => $total,
                'is_overridden' => true,
                'is_customer_supplied' => $total > 0 && $ourQty === 0.0 && $customerQty > 0,
                'customer_supplied_qty' => $ourQty > 0 && $customerQty > 0 ? $customerQty : null,
            ])->save();

            $this->refreshSnapshotAmount($locked);
            $this->repriceLocked($line);
        });
    }

    /**
     * KASHIF-COSTPANEL-SIMPLE-1 — change what this part CHARGES, for this
     * booking only. The dish's own block never moves, and a hand-set rate
     * stops following the house rate book — otherwise tomorrow's book update
     * would silently overwrite a number somebody chose on purpose tonight.
     */
    public function setChargedRate(CateringEstimateLineCostBlock $snapshot, float $rate): void
    {
        if ($rate < 0) {
            throw new RuntimeException('A rate cannot be negative.');
        }

        DB::connection('tenant')->transaction(function () use ($snapshot, $rate) {
            [, $line, $locked] = $this->locks->editableSnapshot($snapshot);

            $locked->forceFill([
                'rate' => round($rate, 4),
                'commercial_rate_source' => \App\Models\Tenant\CateringProductCostBlock::SOURCE_MANUAL,
            ])->save();

            $this->refreshSnapshotAmount($locked);
            $this->repriceLocked($line);
        });
    }

    /** The item-level party switch; a free-text line has no item, so it stays allowed. */
    private function partySupplyAllowed($line): bool
    {
        if (! $line->product_id) {
            return true;
        }

        $allowed = \App\Models\Tenant\CateringProductProfile::where('product_id', $line->product_id)
            ->value('allow_party_supply');

        return $allowed === null || (bool) $allowed;
    }

    /** Put a material back on the dish's own ratio for this line. */
    public function resetMaterialQuantity(CateringEstimateLineCostBlock $snapshot): void
    {
        DB::connection('tenant')->transaction(function () use ($snapshot) {
            [, $line, $locked] = $this->locks->editableSnapshot($snapshot);

            $locked->forceFill([
                'event_material_qty' => $locked->default_material_qty,
                'is_overridden' => false,
            ])->save();

            $this->refreshSnapshotAmount($locked);
            $this->repriceLocked($line);
        });
    }

    /**
     * The dish quantity changed, so ratio-derived requirements follow it.
     *
     * A material the operator has deliberately overridden does NOT follow. They
     * said three kilos; silently making it four because the order grew would
     * discard a decision somebody made on purpose. It stays, visibly overridden,
     * until they reset it.
     */
    public function recalculateForQuantityLocked(CateringEstimateLine $line): void
    {
        $this->locks->assertInsideCriticalSection('recalculateForQuantityLocked');

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

        $this->repriceLocked($line->refresh());
    }

    /**
     * Recompute the line's calculated rate, lump sums and amount from its own
     * snapshot — never from the product master, which may have moved on.
     *
     * The quoted rate is left alone unless it has never been set: an operator's
     * agreed price is theirs, and repricing the blocks underneath it must not
     * quietly change what the customer was told.
     */
    public function repriceLocked(CateringEstimateLine $line): void
    {
        $this->locks->assertInsideCriticalSection('repriceLocked');

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

        $this->recalculateDocumentLocked($line);
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
        if ($quotedRate < 0) {
            throw new RuntimeException('A quoted rate cannot be negative.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException(
                'Quoting a different rate from the calculated one needs a reason — '
                .'without it nobody can tell a discount from a mistake.'
            );
        }

        DB::connection('tenant')->transaction(function () use ($line, $quotedRate, $reason) {
            [$estimate, $locked] = $this->locks->editableLine($line);

            $locked->forceFill([
                'rate' => round($quotedRate, 2),
                'rate_override_reason' => trim($reason),
                'amount' => round($quotedRate * (float) $locked->quantity + (float) $locked->lump_sum_amount, 2),
            ])->save();

            $this->recalculateDocumentLocked($estimate);
        });
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
        DB::connection('tenant')->transaction(function () use ($line) {
            [, $locked] = $this->locks->editableLine($line);

            $locked->forceFill(['rate_override_reason' => null])->save();

            $this->repriceLocked($locked);
        });
    }

    /**
     * A changed line changes the quotation. Without this the screen shows a line
     * at 1,960 inside a document that still totals 1,910, and whichever figure
     * the customer is given, one of them is wrong.
     */
    private function recalculateDocumentLocked(mixed $lineOrEstimate): void
    {
        $estimate = $lineOrEstimate instanceof CateringEstimateLine
            ? $lineOrEstimate->estimate
            : $lineOrEstimate;

        if ($estimate) {
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

        $snapshot->forceFill([
            'material_cost' => $snapshot->computeMaterialCost(),
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
