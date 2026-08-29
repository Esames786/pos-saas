<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringCommercialRateApplication as Audit;
use App\Models\Tenant\CateringEstimateLineCostBlock;
use App\Models\Tenant\CateringProductCostBlock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * KASHIF-CATERING-MAKING-1 — bulk-adjust the Making charge, and nothing else.
 *
 * "Making 500 → 600" is one commercial decision that touches many dishes, and
 * until charge_role existed there was no honest way to know which money it
 * touched. Only blocks EXPLICITLY classified charge_role='making' participate;
 * Packing, Waiter, Decoration, Setup and every other charge are structurally
 * out of reach — not filtered out, never in.
 *
 * The shape is Rate Impact's, deliberately: preview first, then SELECTIVE
 * apply to product masters and to draft snapshots under the same document
 * lock ladder. A sent quotation is never mutated in place — the operator's
 * road is the existing Create Revision flow, after which the new draft's
 * snapshot is adjustable like any other. A negotiated quoted rate + reason
 * survives every apply, because repriceLocked() is the only repricer and that
 * preservation is its contract.
 *
 * Money math: a PER-UNIT Making moves the calculated rate by exactly the rate
 * difference per finished-product unit. A LUMP-SUM Making is charged once and
 * NEVER divided into the per-unit rate — rateFor()/repriceLocked() already
 * hold that line; this service inherits it by delegating to them.
 *
 * Making preview/apply creates NO stock movement and NO GL — it edits a
 * charge figure and reprices documents through the existing snapshot
 * authority. Applications are audited in the commercial-rate application
 * book (same actor/old/new/calculated shape) with material_product_id NULL:
 * Making has no material, and an audit must not invent one.
 */
class CateringMakingAdjustmentService
{
    public function __construct(
        private readonly CateringCostBlockService $blocks,
        private readonly CateringLineCostBlockService $lineBlocks,
        private readonly CateringCommercialRateBookService $book,
        private readonly CateringDocumentLock $locks,
    ) {}

    /**
     * Everything a proposed Making rate WOULD change — no writes, no audit.
     *
     * @return array{
     *   proposed: ?float,
     *   products: array<int, array>,
     *   drafts: array<int, array>,
     *   ineligible_documents: array<int, array>,
     *   classified_count: int
     * }
     */
    public function preview(?float $proposed = null, string $mode = 'set'): array
    {
        $makingBlocks = CateringProductCostBlock::query()
            ->with('product.category:id,name')
            ->where('block_type', CateringProductCostBlock::TYPE_CHARGE)
            ->where('charge_role', CateringProductCostBlock::ROLE_MAKING)
            ->where('is_active', true)
            ->orderBy('product_id')
            ->get();

        $products = $makingBlocks->map(function (CateringProductCostBlock $block) use ($proposed, $mode) {
            $current = (float) $block->rate;
            $newMaking = $proposed === null ? null : $this->adjustedRate($current, $proposed, $mode);
            $oldCalculated = $this->blocks->rateFor((int) $block->product_id);

            // A lump sum is charged once and never joins the per-unit rate, so
            // changing it moves the one-off amount, not the calculated rate.
            $delta = $newMaking === null || $block->isLumpSum() ? 0.0 : round($newMaking - $current, 2);

            return [
                'block_id' => (int) $block->id,
                'product_id' => (int) $block->product_id,
                'product_name' => $block->product?->name,
                'category_id' => $block->product?->category_id,
                'category_name' => $block->product?->category?->name ?? 'Uncategorised',
                'label' => $block->label,
                'charge_basis' => $block->charge_basis,
                'current_making' => $current,
                'new_making' => $newMaking,
                'old_calculated_rate' => $oldCalculated,
                'new_calculated_rate' => $newMaking === null ? null : round($oldCalculated + $delta, 2),
                'difference' => $proposed === null ? null : $delta,
            ];
        })->values()->all();

        $snapshots = CateringEstimateLineCostBlock::query()
            ->with(['line.estimate.event:id,event_no', 'line.product.category:id,name'])
            ->where('block_type', CateringProductCostBlock::TYPE_CHARGE)
            ->where('charge_role', CateringProductCostBlock::ROLE_MAKING)
            ->get();

        $drafts = [];
        $ineligible = [];
        foreach ($snapshots as $snapshot) {
            $line = $snapshot->line;
            $estimate = $line?->estimate;
            if (! $line || ! $estimate) {
                continue;
            }

            $current = (float) $snapshot->rate;
            $oldCalculated = (float) ($line->calculated_rate ?? 0);
            $newMaking = $proposed === null ? null : $this->adjustedRate($current, $proposed, $mode);
            $delta = $newMaking === null || $snapshot->isLumpSum() ? 0.0 : round($newMaking - $current, 2);

            $row = [
                'snapshot_id' => (int) $snapshot->id,
                'event_no' => $estimate->event?->event_no,
                'version_no' => (int) $estimate->version_no,
                'status' => $estimate->status,
                'item_name' => $line->item_name,
                'category_id' => $line->product?->category_id,
                'category_name' => $line->product?->category?->name ?? 'Uncategorised',
                'quantity' => (float) $line->quantity,
                'charge_basis' => $snapshot->charge_basis,
                'current_making' => $current,
                'new_making' => $newMaking,
                'old_calculated_rate' => $oldCalculated,
                'new_calculated_rate' => $newMaking === null ? null : round($oldCalculated + $delta, 2),
                'difference' => $proposed === null ? null : $delta,
                'quoted_rate' => (float) $line->rate,
                'quoted_is_override' => $line->hasQuotedRateOverride(),
                'quoted_override_reason' => $line->rate_override_reason,
            ];

            if ($estimate->isDraft()) {
                $drafts[] = $row;
            } else {
                // Sent/accepted/superseded/invoiced: named, never touched. The
                // road to a new Making on a sent quotation is Create Revision.
                $row['reason'] = match ($estimate->status) {
                    'sent', 'accepted' => 'Quotation is '.$estimate->status.' — create a revision, then adjust the new draft.',
                    default => 'Quotation is '.$estimate->status.' and cannot change.',
                };
                $ineligible[] = $row;
            }
        }

        return [
            'proposed' => $proposed,
            'mode' => $mode,
            'products' => $products,
            'drafts' => $drafts,
            'ineligible_documents' => $ineligible,
            'classified_count' => $makingBlocks->count(),
        ];
    }

    /**
     * Put the new Making rate on the SELECTED product masters. Old quotation
     * snapshots are copies and are not touched — that is the whole point of
     * their existing.
     */
    public function applyToProducts(float $newRate, array $blockIds, ?int $userId = null, string $mode = 'set'): int
    {
        $this->assertRate($newRate);
        $applied = 0;

        DB::connection('tenant')->transaction(function () use ($newRate, $blockIds, $userId, $mode, &$applied) {
            $blocks = CateringProductCostBlock::query()
                ->with('product:id,name')
                ->whereIn('id', $blockIds ?: [0])
                ->where('block_type', CateringProductCostBlock::TYPE_CHARGE)
                ->where('charge_role', CateringProductCostBlock::ROLE_MAKING)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get();

            foreach ($blocks as $block) {
                $oldRate = (float) $block->rate;
                $targetRate = $this->adjustedRate($oldRate, $newRate, $mode);
                $oldCalculated = $this->blocks->rateFor((int) $block->product_id);

                $block->update(['rate' => $targetRate]);

                $this->book->record([
                    'material_product_id' => null,
                    'material_name' => null,
                    'action' => Audit::ACTION_MAKING_PRODUCT_APPLIED,
                    'target_type' => Audit::TARGET_PRODUCT_BLOCK,
                    'target_id' => $block->id,
                    'target_label' => trim(($block->product?->name ?? 'Product').' · '.$block->label),
                    'old_commercial_rate' => $oldRate,
                    'new_commercial_rate' => $targetRate,
                    'old_calculated_rate' => $oldCalculated,
                    'new_calculated_rate' => $this->blocks->rateFor((int) $block->product_id),
                    'performed_by_user_id' => $userId,
                    'note' => 'Making adjustment',
                ]);

                $applied++;
            }
        });

        return $applied;
    }

    /**
     * Put the new Making rate on the SELECTED draft snapshots — same lock
     * ladder as Rate Impact, so a concurrent Finalize queues behind us instead
     * of racing us, and every eligibility question is re-asked under the lock.
     */
    public function applyToDrafts(float $newRate, array $snapshotIds, ?int $userId = null, string $mode = 'set'): int
    {
        $this->assertRate($newRate);
        $applied = 0;

        DB::connection('tenant')->transaction(function () use ($newRate, $snapshotIds, $userId, $mode, &$applied) {
            $targets = CateringEstimateLineCostBlock::query()
                ->join('catering_estimate_lines as l', 'l.id', '=', 'catering_estimate_line_cost_blocks.catering_estimate_line_id')
                ->where('catering_estimate_line_cost_blocks.block_type', CateringProductCostBlock::TYPE_CHARGE)
                ->where('catering_estimate_line_cost_blocks.charge_role', CateringProductCostBlock::ROLE_MAKING)
                ->whereIn('catering_estimate_line_cost_blocks.id', $snapshotIds ?: [0])
                ->get([
                    'catering_estimate_line_cost_blocks.id as snapshot_id',
                    'l.id as line_id',
                    'l.catering_estimate_id as estimate_id',
                ]);

            if ($targets->isEmpty()) {
                return;
            }

            $estimates = $this->locks->estimates($targets->pluck('estimate_id')->all());
            $lines = $this->locks->lines($targets->pluck('line_id')->all());
            $snapshots = $this->locks->snapshots($targets->pluck('snapshot_id')->all());

            $before = [];
            $touchedLines = [];

            foreach ($snapshots as $snapshot) {
                $line = $lines->get($snapshot->catering_estimate_line_id);
                $estimate = $line ? $estimates->get($line->catering_estimate_id) : null;

                // Fail closed under the lock: only a DRAFT's Making snapshot
                // moves. A quotation sent while we queued stays exactly as its
                // customer received it.
                if (! $line || ! $estimate || ! $estimate->isDraft() || ! $snapshot->isMaking()) {
                    continue;
                }
                $line->setRelation('estimate', $estimate);
                $snapshot->setRelation('line', $line);

                $before[$snapshot->id] = [
                    'rate' => (float) $snapshot->rate,
                    'calculated' => (float) $line->calculated_rate,
                    'label' => trim(($estimate->event?->event_no ?? 'Quotation').' v'.$estimate->version_no
                        .' · '.$line->item_name.' · '.$snapshot->label),
                    'estimate_id' => $estimate->id,
                ];

                $targetRate = $this->adjustedRate((float) $snapshot->rate, $newRate, $mode);
                $snapshot->forceFill(['rate' => $targetRate])->save();
                $snapshot->forceFill([
                    'amount' => $snapshot->computeAmount((float) $line->quantity),
                ])->save();

                $touchedLines[$snapshot->catering_estimate_line_id] = $line;
                $applied++;
            }

            foreach ($touchedLines as $line) {
                $this->lineBlocks->repriceLocked($line->refresh());
            }

            // Recorded after the reprice, so "new calculated" is what the line
            // actually ended up with, not what we expected.
            foreach ($before as $snapshotId => $was) {
                $snapshot = CateringEstimateLineCostBlock::with('line')->find($snapshotId);

                $this->book->record([
                    'material_product_id' => null,
                    'material_name' => null,
                    'action' => Audit::ACTION_MAKING_DRAFT_APPLIED,
                    'target_type' => Audit::TARGET_ESTIMATE_SNAPSHOT,
                    'target_id' => $snapshotId,
                    'target_label' => $was['label'],
                    'catering_estimate_id' => $was['estimate_id'],
                    'old_commercial_rate' => $was['rate'],
                    'new_commercial_rate' => (float) ($snapshot?->rate ?? 0),
                    'old_calculated_rate' => $was['calculated'],
                    'new_calculated_rate' => (float) ($snapshot?->line?->calculated_rate ?? 0),
                    'performed_by_user_id' => $userId,
                    'note' => 'Making adjustment',
                ]);
            }
        });

        return $applied;
    }

    private function assertRate(float $rate): void
    {
        if ($rate < 0) {
            throw new RuntimeException('A Making rate cannot be negative.');
        }
    }

    private function adjustedRate(float $current, float $value, string $mode): float
    {
        $this->assertRate($value);

        return match ($mode) {
            'set' => round($value, 2),
            'increase' => round($current + $value, 2),
            'decrease' => round(max(0, $current - $value), 2),
            default => throw new RuntimeException('Unknown Making adjustment type.'),
        };
    }
}
