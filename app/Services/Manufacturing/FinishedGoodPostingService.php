<?php

namespace App\Services\Manufacturing;

use App\Models\Tenant\Branch;
use App\Models\Tenant\FinishedGoodReceipt;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\WipJob;
use App\Services\Finance\JournalService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * MFG-FIN-E — Finished Goods Receipt Posting (Phase E).
 *
 * Posting a finished goods receipt:
 *   1. Puts finished goods INTO inventory (postIn) at the WIP unit cost (accumulated_cost / accepted_quantity).
 *   2. Posts one balanced journal:
 *        Dr  Finished Goods Inventory (1430)   accepted_qty × unit_cost
 *        Cr  WIP Inventory (1420)               accepted_qty × unit_cost
 *
 * Settings-gated, idempotent, reversible. Does NOT affect scrap/rejection/
 * variance/COGS — those remain for a later phase. Only accepted_quantity enters
 * sellable FG stock; rejected/scrap quantities are tracking-only here.
 */
class FinishedGoodPostingService
{
    public const SOURCE_TYPE       = 'manufacturing_fg_receipt';
    public const MOVEMENT_FG_IN    = 'manufacturing_fg_receipt';
    public const MOVEMENT_REVERSAL = 'manufacturing_fg_receipt_reversal';

    public function __construct(
        private readonly ManufacturingPostingService $posting,
        private readonly InventoryService $inventory,
        private readonly JournalService $journal,
    ) {}

    /** Post Dr FG / Cr WIP for an accepted finished goods receipt. Throws on failure. */
    public function post(FinishedGoodReceipt $receipt, ?int $userId = null): FinishedGoodReceipt
    {
        $receipt->loadMissing(['finishedProduct', 'branch', 'wipJob']);

        // ── Preconditions ──────────────────────────────────────────────────────
        $settings = $this->posting->assertSettingsReady($receipt->branch_id);
        $this->posting->assertUnposted($receipt);

        if ($receipt->status === 'cancelled') {
            throw new RuntimeException('A cancelled FG receipt cannot be posted.');
        }
        if (! $receipt->branch_id) {
            throw new RuntimeException('FG receipt has no branch.');
        }

        $acceptedQty = (float) $receipt->accepted_quantity;
        if ($acceptedQty <= 0) {
            throw new RuntimeException('Accepted quantity must be greater than zero before posting.');
        }

        $product = $receipt->finishedProduct;
        if (! $product) {
            throw new RuntimeException('FG receipt has no finished product linked.');
        }
        if (! $product->is_stock_tracked) {
            throw new RuntimeException($product->name . ' is not stock-tracked. Enable stock tracking to post FG inventory.');
        }

        if (! $settings->finished_goods_inventory_account_id || ! $settings->wip_inventory_account_id) {
            throw new RuntimeException('Finished Goods / WIP inventory accounts are not mapped in posting settings.');
        }

        if ($this->posting->alreadyHasJournal(self::SOURCE_TYPE, $receipt->id)) {
            throw new RuntimeException('A posted journal already exists for this FG receipt.');
        }

        // ── Derive WIP unit cost ───────────────────────────────────────────────
        // FG is valued at actual accumulated WIP cost ÷ accepted qty.
        // Falls back to product default purchase price if WIP has no cost (e.g. no consumption was posted).
        $wip          = $receipt->wipJob;
        $wipCost      = $wip ? (float) $wip->accumulated_cost : 0.0;
        $costBasisQty = $wip && (float) $wip->planned_quantity > 0
            ? (float) $wip->planned_quantity
            : $acceptedQty;
        $unitCost     = ($wipCost > 0 && $costBasisQty > 0)
            ? round($wipCost / $costBasisQty, 4)
            : (float) ($product->default_purchase_price ?? 0);

        if ($unitCost <= 0) {
            throw new RuntimeException(
                'Cannot determine FG unit cost — no WIP accumulated cost and no purchase price on ' . $product->name . '.'
                . ' Post the related consumption records first, or set a purchase price on the product.'
            );
        }

        $totalCost = round($unitCost * $acceptedQty, 2);
        $branch    = $receipt->branch ?: Branch::findOrFail($receipt->branch_id);
        $variant   = $this->inventory->resolveVariant($product, null);

        // ── Atomic: stock in → journal → state ───────────────────────────────
        return DB::connection('tenant')->transaction(function () use ($receipt, $settings, $branch, $product, $variant, $acceptedQty, $unitCost, $totalCost, $userId) {

            // Stock IN for finished goods.
            $this->inventory->postIn(
                branch:        $branch,
                product:       $product,
                variant:       $variant,
                quantity:      $acceptedQty,
                unitCost:      $unitCost,
                movementType:  self::MOVEMENT_FG_IN,
                referenceType: 'finished_good_receipt',
                referenceId:   $receipt->id,
                referenceNo:   $receipt->fg_no,
                notes:         'FG receipt posted — ' . $receipt->fg_no,
                userId:        $userId,
            );

            // Balanced journal: Dr FG / Cr WIP.
            $journal = $this->journal->post(
                sourceType:  self::SOURCE_TYPE,
                sourceId:    $receipt->id,
                sourceNo:    $receipt->fg_no,
                description: 'Finished goods receipt ' . $receipt->fg_no,
                entryDate:   optional($receipt->receipt_date)->toDateString() ?? now()->toDateString(),
                lines: [
                    [
                        'account_id'  => (int) $settings->finished_goods_inventory_account_id,
                        'branch_id'   => $receipt->branch_id,
                        'description' => 'FG stock in ' . $receipt->fg_no,
                        'debit'       => $totalCost,
                        'credit'      => 0,
                    ],
                    [
                        'account_id'  => (int) $settings->wip_inventory_account_id,
                        'branch_id'   => $receipt->branch_id,
                        'description' => 'WIP transferred to FG ' . $receipt->fg_no,
                        'debit'       => 0,
                        'credit'      => $totalCost,
                    ],
                ],
                userId: $userId,
            );

            // Store the unit/total cost on the receipt for future reference.
            $receipt->forceFill([
                'unit_cost'  => $unitCost,
                'total_cost' => $totalCost,
            ])->save();

            // Update WIP completed_quantity and recalculate progress.
            if ($receipt->wipJob) {
                $wip = $receipt->wipJob;
                $newCompleted = min(
                    (float) $wip->planned_quantity,
                    (float) $wip->completed_quantity + $acceptedQty
                );
                $newCostedQty   = (float) $wip->costed_quantity + $acceptedQty;
                $newCostedTotal = ((float) $wip->average_unit_cost * (float) $wip->costed_quantity) + $totalCost;

                $wip->forceFill([
                    'completed_quantity' => $newCompleted,
                    'costed_quantity'    => $newCostedQty,
                    'average_unit_cost'  => $newCostedQty > 0 ? round($newCostedTotal / $newCostedQty, 4) : 0,
                ]);
                $wip->recalculateProgress();
                if ($newCompleted >= (float) $wip->planned_quantity) {
                    $wip->forceFill(['status' => 'ready_for_completion']);
                }
                $wip->save();
            }

            $this->posting->markDocumentPosted($receipt, $journal, $userId);

            return $receipt->fresh(['wipJob']);
        });
    }

    /** Reverse a posted FG receipt: remove FG stock, reverse journal, unwind WIP completed qty. */
    public function reverse(FinishedGoodReceipt $receipt, ?int $userId = null): FinishedGoodReceipt
    {
        $receipt->loadMissing(['finishedProduct', 'branch', 'wipJob']);

        $this->posting->assertCanReverse($receipt);

        if (! $receipt->journal_entry_id) {
            throw new RuntimeException('FG receipt has no journal to reverse.');
        }

        $original = JournalEntry::find($receipt->journal_entry_id);
        if (! $original) {
            throw new RuntimeException('Original FG receipt journal entry not found.');
        }

        $product     = $receipt->finishedProduct;
        $acceptedQty = (float) $receipt->accepted_quantity;
        $unitCost    = (float) $receipt->unit_cost;
        $branch      = $receipt->branch ?: Branch::findOrFail($receipt->branch_id);
        $variant     = $product ? $this->inventory->resolveVariant($product, null) : null;

        return DB::connection('tenant')->transaction(function () use ($receipt, $original, $branch, $product, $variant, $acceptedQty, $unitCost, $userId) {

            // Remove FG stock (postOutFefo — uses FEFO from what was just posted in).
            if ($product && $product->is_stock_tracked && $acceptedQty > 0) {
                try {
                    $ledgers = $this->inventory->postOutFefo(
                        branch:        $branch,
                        product:       $product,
                        variant:       $variant,
                        quantity:      $acceptedQty,
                        movementType:  self::MOVEMENT_REVERSAL,
                        referenceType: 'finished_good_receipt',
                        referenceId:   $receipt->id,
                        referenceNo:   $receipt->fg_no,
                        notes:         'Reversal of FG receipt ' . $receipt->fg_no,
                        userId:        $userId,
                    );
                } catch (\RuntimeException $e) {
                    throw new RuntimeException('Cannot reverse: ' . $e->getMessage() . ' (FG stock may have already been sold or transferred).');
                }
            }

            // Reverse the journal.
            $this->journal->reverse($original, 'FG receipt reversal ' . $receipt->fg_no, $userId);

            // Unwind WIP completed/costed quantities.
            if ($receipt->wipJob) {
                $wip = $receipt->wipJob;
                $newCompleted = max(0, (float) $wip->completed_quantity - $acceptedQty);
                $newCostedQty = max(0, (float) $wip->costed_quantity - $acceptedQty);
                $newCostedTotal = max(
                    0,
                    ((float) $wip->average_unit_cost * (float) $wip->costed_quantity) - ((float) $receipt->total_cost)
                );

                $wip->forceFill([
                    'completed_quantity' => $newCompleted,
                    'costed_quantity'    => $newCostedQty,
                    'average_unit_cost'  => $newCostedQty > 0 ? round($newCostedTotal / $newCostedQty, 4) : 0,
                    'status'             => 'in_progress',
                ]);
                $wip->recalculateProgress();
                $wip->save();
            }

            $this->posting->markDocumentReversed($receipt, $userId);

            return $receipt->fresh(['wipJob']);
        });
    }
}
