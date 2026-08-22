<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringCommercialRateApplication;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
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
 * FOUR THINGS ARE NEVER OFFERED AN IMPACT, each for its own reason:
 *
 *   manual rates          chosen for that dish on purpose. A premium counter at
 *                         140 is not a dish that forgot to update.
 *   legacy per-dish rates measured in rupees per kilo of BIRYANI, where the book
 *                         quotes rupees per kilo of CHICKEN. Not a bad
 *                         suggestion — a category error.
 *   customer-supplied     the customer is not being charged for that material,
 *                         so what it is charged at cannot move anything.
 *   unit mismatch         120 per KG offered to a block measured in GM. See
 *                         CateringCommercialRateBookService for why this is
 *                         refused rather than converted.
 *
 * AND NONE OF THE FOUR IS SHOWN A NUMBER. An excluded row with a difference of
 * +200 beside the word "ineligible" invites the reading "the system knows it
 * should be 200 more but will not do it" — when the truth is that 200 is not its
 * impact at all. Excluded rows show what they are, and no arithmetic.
 *
 * Every exclusion is re-checked at APPLY time. The preview's job is to inform a
 * choice; it is not a guard, because the ids come back from a form.
 */
class CateringCommercialRateImpactService
{
    /** A row whose projected numbers are real and may be applied right now. */
    public const STATE_APPLICABLE = 'applicable';

    /** Real numbers, but the quotation has been sent — it must be revised. */
    public const STATE_REVISION_REQUIRED = 'revision_required';

    /** The document is closed to commercial change. No numbers, no action. */
    public const STATE_LOCKED = 'locked';

    public const STATE_MANUAL = 'manual';

    public const STATE_CUSTOMER_SUPPLIED = 'customer_supplied';

    public const STATE_LEGACY_BASIS = 'legacy_basis';

    public const STATE_UNIT_MISMATCH = 'unit_mismatch';

    public function __construct(
        private readonly CateringCommercialRateBookService $book,
        private readonly CateringLineCostBlockService $lineBlocks,
        private readonly CateringCostBlockService $costBlocks,
        private readonly CateringEstimateService $estimates,
        // KASHIF-CATERING-LIFECYCLE-LOCK-1: applying a rate and ending the right
        // to apply one are two halves of the same race, so both go through one
        // lock order rather than each guarding itself.
        private readonly CateringDocumentLock $locks,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // DISHES — what future quotations would be priced at.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{
     *   material: array{id: int, name: string},
     *   recommended: ?float,
     *   recommended_unit: ?string,
     *   products: array<int, array>,
     *   ineligible: array<int, array>
     * }
     */
    public function productImpact(int $materialProductId, ?float $newRate = null, ?string $asOfDate = null): array
    {
        $material = Product::findOrFail($materialProductId);
        $bookRate = $this->book->effectiveRate($materialProductId, $asOfDate);
        $bookRate?->loadMissing('unit');
        $recommended = $newRate ?? ($bookRate === null ? null : (float) $bookRate->rate);

        $blocks = CateringProductCostBlock::query()
            ->with(['product:id,name,sku', 'unit:id,code'])
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
                'unit_code' => $block->unit?->code,
                'rate_basis' => $block->rateBasis(),
                'source' => $block->rateSource(),
            ];

            $state = $this->productBlockState($block, $bookRate);

            if ($state !== self::STATE_APPLICABLE) {
                $ineligible[] = $row + [
                    'state' => $state,
                    'reason' => $this->whyBlockIneligible($block, $bookRate, $state),
                ];

                continue;
            }

            // The dish's rate is the sum of its parts, so this part's movement
            // IS the dish's movement — ratio times the change, per unit of dish.
            $oldRate = $this->costBlocks->rateFor($block->product_id);
            $contributionNow = round($ratio * $applied, 4);
            $contributionNew = $recommended === null ? $contributionNow : round($ratio * $recommended, 4);
            $projected = round($oldRate - $contributionNow + $contributionNew, 2);

            $products[] = $row + [
                'state' => self::STATE_APPLICABLE,
                'recommended_rate' => $recommended,
                'old_calculated_rate' => round($oldRate, 2),
                'projected_calculated_rate' => $projected,
                'difference' => round($projected - $oldRate, 2),
            ];
        }

        return [
            'material' => ['id' => $material->id, 'name' => $material->name],
            'recommended' => $recommended,
            'recommended_unit' => $bookRate?->unit?->code,
            'products' => $products,
            'ineligible' => $ineligible,
        ];
    }

    /**
     * Why a dish block is or is not eligible — one answer, checked in the same
     * order everywhere so a row never explains itself differently in the preview
     * than it does when an apply refuses it.
     */
    private function productBlockState(CateringProductCostBlock $block, ?CateringMaterialCommercialRate $bookRate): string
    {
        if (! $block->isPerMaterialUnit()) {
            return self::STATE_LEGACY_BASIS;
        }
        if ($block->rateSource() !== CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK
            || $block->material_product_id === null) {
            return self::STATE_MANUAL;
        }
        if (! $this->book->blockUnitMatches($bookRate, $block)) {
            return self::STATE_UNIT_MISMATCH;
        }

        return self::STATE_APPLICABLE;
    }

    private function whyBlockIneligible(
        CateringProductCostBlock $block,
        ?CateringMaterialCommercialRate $bookRate,
        string $state
    ): string {
        return match ($state) {
            self::STATE_LEGACY_BASIS => 'Priced per unit of the dish, not per unit of the material — the house rate is a '
                .'different measurement and cannot be offered to it.',
            self::STATE_UNIT_MISMATCH => $this->book->unitMismatchReason($bookRate, $block->unit?->code),
            default => 'This rate was set by hand for this dish, so a house change leaves it alone.',
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // QUOTATIONS — what documents already priced at the old rate would become.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Worked out from each line's own SNAPSHOT, never from today's dish master.
     *
     * That distinction is the whole reason a snapshot exists. A quotation that
     * was priced when the dish had three blocks and an agreed quantity must be
     * projected as the document it is, not as the document the product screen
     * would produce today — otherwise "what would change" quietly folds in every
     * unrelated edit made to the dish since, and the operator is shown a number
     * they never asked about.
     *
     * So exactly one thing moves: the chosen material's rate. Every other
     * snapshot rate, the event's own quantity, who is supplying it, the making,
     * the charges and the line's lump sums are read as stored.
     *
     * @return array<int, array>
     */
    public function draftImpact(int $materialProductId, ?float $newRate = null, ?string $asOfDate = null): array
    {
        $bookRate = $this->book->effectiveRate($materialProductId, $asOfDate);
        $bookRate?->loadMissing('unit');
        $recommended = $newRate ?? ($bookRate === null ? null : (float) $bookRate->rate);

        if ($recommended === null) {
            return [];
        }

        $snapshots = CateringEstimateLineCostBlock::query()
            ->with(['line.estimate.event.finalInvoice'])
            ->where('material_product_id', $materialProductId)
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($snapshots as $snapshot) {
            $line = $snapshot->line;
            $estimate = $line?->estimate;
            if (! $estimate) {
                continue;
            }

            $state = $this->snapshotState($snapshot, $estimate, $bookRate);
            $showsImpact = in_array($state, [self::STATE_APPLICABLE, self::STATE_REVISION_REQUIRED], true);

            // The quantity THIS event settled on, which may not be the ratio's:
            // an operator who said twelve kilos is charged for twelve. BILLABLE,
            // not physical: a partially customer-supplied material is billed for
            // the balance only, so a rate change moves only that balance.
            $quantity = $snapshot->billableQty();
            $dishQty = (float) $line->quantity;
            $oldCalculated = (float) $line->calculated_rate;
            $quoted = (float) $line->rate;
            $isOverride = $line->rate_override_reason !== null;

            // ZERO AND "NOT APPLICABLE" ARE DIFFERENT ANSWERS, and only one of
            // them is a number. A customer-supplied material really does move by
            // nothing — the customer is not charged for it, so the impact IS
            // zero, and saying so is more useful than a dash. A manual rate, a
            // legacy per-dish rate or a mismatched unit have no impact figure at
            // all: any number shown against them would be an arithmetic answer
            // to a question that was never asked.
            $isZeroByNature = $state === self::STATE_CUSTOMER_SUPPLIED;

            $newAmount = match (true) {
                $showsImpact => round($quantity * $recommended, 2),
                $isZeroByNature => 0.0,
                default => null,
            };
            $projectedRate = match (true) {
                $showsImpact => $this->projectLineRate($line, $snapshot, $recommended),
                // Nothing about this line's calculation moves, so its projected
                // rate is the rate it already has.
                $isZeroByNature => round($oldCalculated, 2),
                default => null,
            };

            // What the CUSTOMER's total would do — which is not the same question
            // as what the calculation would do. A rate somebody agreed with a
            // customer does not move because the house did; only the calculation
            // underneath it moves, and saying otherwise on this screen would
            // promise a change that will not happen.
            $quotationDifference = match (true) {
                $showsImpact => $isOverride ? 0.0 : round(($projectedRate - $oldCalculated) * $dishQty, 2),
                $isZeroByNature => 0.0,
                default => null,
            };

            $rows[] = [
                'snapshot_id' => (int) $snapshot->id,
                'line_id' => (int) $snapshot->catering_estimate_line_id,
                'estimate_id' => (int) $estimate->id,
                'version_no' => (int) $estimate->version_no,
                'event_no' => $estimate->event?->event_no,
                'customer' => $estimate->event?->customer_name,
                'item_name' => $line->item_name,
                'status' => $estimate->status,
                'label' => $snapshot->label,
                'unit_code' => $snapshot->unit_code,
                'dish_quantity' => $dishQty,
                'material_qty' => $quantity,
                'applied_rate' => (float) $snapshot->rate,
                'recommended_rate' => $recommended,

                // The material's own line of the breakdown.
                'old_amount' => round((float) $snapshot->amount, 2),
                'new_amount' => $newAmount,
                'difference' => $newAmount === null ? null : round($newAmount - (float) $snapshot->amount, 2),

                // The decision numbers — what the dish rate on this quotation is,
                // what it would become, and what the customer is actually quoted.
                'old_calculated_rate' => round($oldCalculated, 2),
                'projected_calculated_rate' => $projectedRate,
                'quoted_rate' => round($quoted, 2),
                'quoted_is_override' => $isOverride,
                'quoted_override_reason' => $line->rate_override_reason,
                'quotation_difference' => $quotationDifference,

                'customer_supplied' => $snapshot->isCustomerSupplied(),
                'source' => $snapshot->rateSource(),
                'state' => $state,
                'state_label' => $this->stateLabel($state),
                'shows_impact' => $showsImpact,
                'eligible' => $state === self::STATE_APPLICABLE,
                'revisable' => $state === self::STATE_REVISION_REQUIRED,
                'reason' => $this->whyQuoteIneligible($snapshot, $estimate, $bookRate, $state),
            ];
        }

        return $rows;
    }

    /**
     * What the line's CALCULATED rate would become with only this material moved.
     *
     * Deliberately the same arithmetic CateringLineCostBlockService::reprice()
     * performs — amounts summed and divided by the dish quantity, lump sums
     * excluded because they do not scale. A second formula here would be a
     * second answer, and the preview would stop predicting the apply.
     */
    private function projectLineRate(CateringEstimateLine $line, CateringEstimateLineCostBlock $target, float $newRate): float
    {
        $dishQty = (float) $line->quantity;
        if ($dishQty <= 0) {
            return round((float) $line->calculated_rate, 2);
        }

        $perUnit = 0.0;

        foreach ($this->lineBlocks->snapshotsFor($line) as $snapshot) {
            if ($snapshot->isLumpSum()) {
                continue;
            }

            $amount = $snapshot->id === $target->id
                ? round($snapshot->billableQty() * $newRate, 2)
                : (float) $snapshot->amount;

            $perUnit += $amount / $dishQty;
        }

        return round($perUnit, 2);
    }

    private function snapshotState(
        CateringEstimateLineCostBlock $snapshot,
        CateringEstimate $estimate,
        ?CateringMaterialCommercialRate $bookRate
    ): string {
        // The material's own disqualifications come first: they are true
        // regardless of what state the document is in, and saying "revise it"
        // about a material the customer is bringing would be nonsense.
        if ($snapshot->isCustomerSupplied()) {
            return self::STATE_CUSTOMER_SUPPLIED;
        }
        if (! $snapshot->isPerMaterialUnit()) {
            return self::STATE_LEGACY_BASIS;
        }
        if ($snapshot->rateSource() !== CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK
            || $snapshot->material_product_id === null) {
            return self::STATE_MANUAL;
        }
        if (! $this->book->snapshotUnitMatches($bookRate, $snapshot)) {
            return self::STATE_UNIT_MISMATCH;
        }

        // Event-level closure outranks the quotation's own status. A draft can
        // outlive the booking that justified it — revise after invoicing and v2
        // is a draft on an invoiced event — and "it is a draft" would otherwise
        // be read as permission to reprice a booking that has already been
        // billed and closed.
        if (! $this->documentIsOpen($estimate)) {
            return self::STATE_LOCKED;
        }

        if ($estimate->isDraft()) {
            return self::STATE_APPLICABLE;
        }

        return $this->isRevisable($estimate) ? self::STATE_REVISION_REQUIRED : self::STATE_LOCKED;
    }

    /**
     * Is this booking still open to commercial change at all?
     *
     * Uses the authorities that already exist rather than inventing a status
     * rule: CateringEvent::isOpen() decides whether the event still accepts
     * commercial change, and an issued final invoice is the document that ends
     * the argument — immutable by its own model, and a price moving behind it
     * would leave the customer holding a bill nothing agrees with.
     */
    private function documentIsOpen(CateringEstimate $estimate): bool
    {
        // One definition, shared with every ordinary draft writer. Rate Impact
        // and the form save are siblings under the same authority; two copies of
        // this rule would eventually disagree, and the disagreement would be
        // exactly the gap somebody's price slips through.
        return $this->locks->isCommerciallyOpen($estimate);
    }

    /**
     * Can this version still become a new revision?
     *
     * Uses the lifecycle authorities that already exist rather than inventing a
     * status rule: revise() itself refuses a draft and a superseded version,
     * CateringEvent::isOpen() decides whether the event still accepts commercial
     * change at all, and an issued final invoice is the document that ends the
     * argument — it is immutable by its own model, and a revision behind it
     * would leave the customer holding a bill nothing agrees with.
     */
    public function isRevisable(CateringEstimate $estimate): bool
    {
        if (! in_array($estimate->status, [CateringEstimate::STATUS_SENT, CateringEstimate::STATUS_ACCEPTED], true)) {
            return false;
        }

        return $this->documentIsOpen($estimate);
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            self::STATE_APPLICABLE => 'Can be applied',
            self::STATE_REVISION_REQUIRED => 'Revision required',
            self::STATE_LOCKED => 'Locked',
            self::STATE_CUSTOMER_SUPPLIED => 'Customer supplied',
            self::STATE_LEGACY_BASIS => 'Priced per dish unit',
            self::STATE_UNIT_MISMATCH => CateringCommercialRateBookService::UNIT_MISMATCH,
            default => 'Manual rate — not linked',
        };
    }

    private function whyQuoteIneligible(
        CateringEstimateLineCostBlock $snapshot,
        CateringEstimate $estimate,
        ?CateringMaterialCommercialRate $bookRate,
        string $state
    ): ?string {
        return match ($state) {
            self::STATE_APPLICABLE => null,
            self::STATE_CUSTOMER_SUPPLIED => 'The customer is supplying this material, so it is not being charged for '
                .'— a change to what it is charged at moves nothing here.',
            self::STATE_LEGACY_BASIS => 'Priced per unit of the dish — a different measurement from the house rate.',
            self::STATE_MANUAL => 'This price was agreed for this quotation rather than taken from the house rate, '
                .'so a house change leaves it alone.',
            self::STATE_UNIT_MISMATCH => $this->book->unitMismatchReason($bookRate, $snapshot->unit_code),
            self::STATE_REVISION_REQUIRED => 'This quotation has been sent. It is never changed in place — '
                .'create a revision and the new version takes the house rate.',
            default => 'This quotation is closed to commercial change.',
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // APPLYING — the only part of this feature that moves money.
    // ─────────────────────────────────────────────────────────────────────────

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
    public function applyToProducts(int $materialProductId, array $blockIds, ?int $userId = null, ?string $asOfDate = null): int
    {
        $bookRate = $this->requireRate($materialProductId, $asOfDate);
        $rate = (float) $bookRate->rate;
        $applied = 0;

        DB::connection('tenant')->transaction(function () use ($materialProductId, $blockIds, $bookRate, $rate, $userId, &$applied) {
            $blocks = CateringProductCostBlock::query()
                ->with(['product:id,name', 'unit:id,code'])
                ->where('material_product_id', $materialProductId)
                ->whereIn('id', $blockIds ?: [0])
                ->get();

            foreach ($blocks as $block) {
                // Re-checked here, not merely filtered in the preview: a request
                // naming a manual block, a legacy per-dish block or a block
                // measured in another unit must not be able to overwrite a rate
                // through an id typed into a form.
                if ($this->productBlockState($block, $bookRate) !== self::STATE_APPLICABLE) {
                    continue;
                }

                $oldRate = (float) $block->rate;
                $oldCalculated = $this->costBlocks->rateFor($block->product_id);

                $block->forceFill(['rate' => $rate])->save();
                $applied++;

                $this->book->record([
                    'material_product_id' => $materialProductId,
                    'action' => CateringCommercialRateApplication::ACTION_PRODUCT_APPLIED,
                    'target_type' => CateringCommercialRateApplication::TARGET_PRODUCT_BLOCK,
                    'target_id' => $block->id,
                    'target_label' => trim(($block->product?->name ?? 'Dish').' · '.$block->label),
                    'old_commercial_rate' => $oldRate,
                    'new_commercial_rate' => $rate,
                    'old_calculated_rate' => round($oldCalculated, 2),
                    'new_calculated_rate' => round($this->costBlocks->rateFor($block->product_id), 2),
                    'performed_by_user_id' => $userId,
                ]);
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
    public function applyToDrafts(int $materialProductId, array $snapshotIds, ?int $userId = null, ?string $asOfDate = null): int
    {
        $bookRate = $this->requireRate($materialProductId, $asOfDate);
        $applied = 0;

        DB::connection('tenant')->transaction(function () use ($materialProductId, $snapshotIds, $bookRate, $userId, &$applied) {
            // STEP 1 — find out WHICH documents are in play. Ids only. Nothing
            // read here is trusted for a decision; it exists to decide what to
            // lock, because you cannot lock rows you have not identified.
            $targets = CateringEstimateLineCostBlock::query()
                ->join(
                    'catering_estimate_lines as l',
                    'l.id', '=', 'catering_estimate_line_cost_blocks.catering_estimate_line_id'
                )
                ->where('catering_estimate_line_cost_blocks.material_product_id', $materialProductId)
                ->whereIn('catering_estimate_line_cost_blocks.id', $snapshotIds ?: [0])
                ->get([
                    'catering_estimate_line_cost_blocks.id as snapshot_id',
                    'l.id as line_id',
                    'l.catering_estimate_id as estimate_id',
                ]);

            if ($targets->isEmpty()) {
                return;
            }

            // STEP 2 — take the locks, event then estimate, ascending. From here
            // on a concurrent Send, Accept, Revise, Invoice or Cancel on any of
            // these bookings queues behind us instead of interleaving with us.
            $estimates = $this->locks->estimates($targets->pluck('estimate_id')->all());

            // STEP 3 — re-read the snapshots UNDER the lock, and hand each line
            // the freshly-locked estimate. This is the step that actually closes
            // the race: the estimate a line carries is what its immutability
            // guard consults, and a relation loaded before the lock would still
            // be answering "draft" for a quotation that has since been sent.
            // Lines, then snapshots — the same rungs of the same ladder every
            // other writer climbs. Skipping the line level would still have been
            // deadlock-free, but "every path takes every level in one order" is a
            // property worth being able to state without a caveat.
            $lines = $this->locks->lines(
                CateringEstimateLine::query()
                    ->whereIn('id', $targets->pluck('line_id')->all())
                    ->pluck('id')->all()
            );

            $snapshots = $this->locks->snapshots($targets->pluck('snapshot_id')->all());

            foreach ($snapshots as $snapshot) {
                $line = $lines->get($snapshot->catering_estimate_line_id);
                if ($line === null) {
                    continue;
                }
                $line->setRelation('estimate', $estimates->get($line->catering_estimate_id));
                $snapshot->setRelation('line', $line);
            }

            // STEP 4 — every eligibility question is asked again, now against
            // rows nothing else can move until we commit.
            $applied = $this->applySnapshots(
                $snapshots,
                $bookRate,
                $userId,
                CateringCommercialRateApplication::ACTION_DRAFT_APPLIED
            );
        });

        return $applied;
    }

    /**
     * A SENT quotation takes the house rate the only way a sent quotation may
     * change anything: by becoming a new version.
     *
     * The sent document is never rewritten. revise() clones it — lines, agreed
     * rates and their reasons, lump sums, and the full cost-block breakdown
     * including each event's own material quantity and who is supplying it —
     * marks the original superseded, and hands back a draft. The house rate is
     * then applied to THAT draft's snapshots, exactly as it would be to any
     * other draft.
     *
     * The whole thing is one transaction. A revision that superseded v1 and then
     * failed to reprice v2 would leave the business with no current quotation at
     * all, which is a worse outcome than either doing it or not doing it.
     */
    public function applyThroughRevision(
        int $materialProductId,
        int $estimateId,
        ?int $userId = null,
        ?string $asOfDate = null
    ): CateringEstimate {
        $bookRate = $this->requireRate($materialProductId, $asOfDate);

        return DB::connection('tenant')->transaction(function () use ($materialProductId, $estimateId, $bookRate, $userId) {
            // Event first, then the estimate — the same order applyToDrafts uses,
            // so the two can never each hold what the other needs next. The
            // returned model is the locked one, carrying the locked event: a
            // re-query here would be an ordinary read and could still describe
            // the booking as it was before we waited.
            $estimate = $this->locks->estimates([$estimateId])->get($estimateId);

            if ($estimate === null) {
                throw new RuntimeException('That quotation no longer exists.');
            }

            $estimate->load(['lines.costBlocks']);

            if (! $this->isRevisable($estimate)) {
                throw new RuntimeException(
                    'This quotation cannot be revised — it is either still a draft, already superseded, '
                    .'or the event has been invoiced or closed.'
                );
            }

            // Prove there is something to do BEFORE superseding anything. A
            // revision created for a rate that turns out not to apply to any of
            // its lines is a new version of a document nobody asked to change.
            $eligible = $estimate->lines
                ->flatMap->costBlocks
                ->where('material_product_id', $materialProductId)
                ->filter(fn ($snapshot) => $this->snapshotState($snapshot, $estimate, $bookRate) === self::STATE_REVISION_REQUIRED);

            if ($eligible->isEmpty()) {
                throw new RuntimeException(
                    'Nothing on this quotation follows the house rate for this material, so a revision '
                    .'would change nothing.'
                );
            }

            $revision = $this->estimates->revise($estimate, $userId);

            $snapshots = CateringEstimateLineCostBlock::query()
                ->with('line')
                ->whereIn('catering_estimate_line_id', $revision->lines()->pluck('id'))
                ->where('material_product_id', $materialProductId)
                ->get();

            // The revision is a draft this transaction just created, but its
            // EVENT is the one we locked — so hand the lines that model rather
            // than letting each of them lazily read a possibly-stale copy.
            $revision->setRelation('event', $estimate->getRelation('event'));
            foreach ($snapshots as $snapshot) {
                $snapshot->line?->setRelation('estimate', $revision);
            }

            $this->applySnapshots(
                $snapshots,
                $bookRate,
                $userId,
                CateringCommercialRateApplication::ACTION_REVISION_APPLIED,
                'Revised from v'.$estimate->version_no
            );

            return $revision->refresh();
        });
    }

    /**
     * The one place a snapshot's rate actually moves, shared by the draft path
     * and the revision path so both fail closed identically.
     *
     * @param  iterable<int, CateringEstimateLineCostBlock>  $snapshots
     */
    private function applySnapshots(
        iterable $snapshots,
        CateringMaterialCommercialRate $bookRate,
        ?int $userId,
        string $action,
        ?string $note = null
    ): int {
        $rate = (float) $bookRate->rate;
        $applied = 0;
        $touchedLines = [];
        $before = [];

        foreach ($snapshots as $snapshot) {
            $line = $snapshot->line;
            $estimate = $line?->estimate;

            // Fail closed on every condition the preview filtered by. A sent
            // quotation, a hand-agreed price, a mismatched unit and a
            // customer-supplied material each stay where they are.
            if (! $estimate || $this->snapshotState($snapshot, $estimate, $bookRate) !== self::STATE_APPLICABLE) {
                continue;
            }

            $before[$snapshot->id] = [
                'rate' => (float) $snapshot->rate,
                'calculated' => (float) $line->calculated_rate,
                'label' => trim(($estimate->event?->event_no ?? 'Quotation').' v'.$estimate->version_no
                    .' · '.$line->item_name.' · '.$snapshot->label),
                'estimate_id' => $estimate->id,
                'material_product_id' => $snapshot->material_product_id,
            ];

            $snapshot->forceFill(['rate' => $rate])->save();
            $snapshot->forceFill([
                'material_cost' => $snapshot->computeMaterialCost(),
                'amount' => $snapshot->computeAmount((float) $line->quantity),
            ])->save();

            $applied++;
            $touchedLines[$snapshot->catering_estimate_line_id] = $line;
        }

        // Reprice each touched line once, which also re-adds its quotation.
        foreach ($touchedLines as $line) {
            $this->lineBlocks->repriceLocked($line->refresh());
        }

        // Recorded only now, so "new calculated rate" is the figure the line
        // actually ended up with rather than the one we expected it to.
        foreach ($before as $snapshotId => $was) {
            $snapshot = CateringEstimateLineCostBlock::with('line')->find($snapshotId);

            $this->book->record([
                'material_product_id' => $was['material_product_id'],
                'action' => $action,
                'target_type' => CateringCommercialRateApplication::TARGET_ESTIMATE_SNAPSHOT,
                'target_id' => $snapshotId,
                'target_label' => $was['label'],
                'catering_estimate_id' => $was['estimate_id'],
                'old_commercial_rate' => $was['rate'],
                'new_commercial_rate' => $rate,
                'old_calculated_rate' => round($was['calculated'], 2),
                'new_calculated_rate' => round((float) $snapshot?->line?->calculated_rate, 2),
                'performed_by_user_id' => $userId,
                'note' => $note,
            ]);
        }

        return $applied;
    }

    /** No book rate means there is nothing to apply — said once, in one place. */
    private function requireRate(int $materialProductId, ?string $asOfDate): CateringMaterialCommercialRate
    {
        $rate = $this->book->effectiveRate($materialProductId, $asOfDate);

        if ($rate === null) {
            throw new RuntimeException(
                'There is no commercial rate for this material yet, so there is nothing to apply.'
            );
        }

        return $rate->loadMissing('unit');
    }
}
