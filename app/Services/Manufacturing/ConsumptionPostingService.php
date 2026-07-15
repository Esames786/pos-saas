<?php

namespace App\Services\Manufacturing;

use App\Models\Tenant\Branch;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\ManufacturingConsumptionRecord;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\StockLedger;
use App\Services\Finance\JournalService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * MFG-FIN-C — Manufacturing Consumption Posting.
 *
 * Posting a consumption record issues raw material from stock (FEFO), accumulates the
 * actual issued cost onto the linked WIP job, and posts ONE balanced journal:
 *     Dr  Work-In-Process Inventory      total actual issue cost
 *     Cr  Raw Material Inventory          total actual issue cost
 *
 * Strict, settings-gated, idempotent and reversible. It posts NOTHING for finished
 * goods, scrap, rejection, rework, variance, COGS or sales — only consumption.
 */
class ConsumptionPostingService
{
    public const SOURCE_TYPE        = 'manufacturing_consumption';
    public const MOVEMENT_ISSUE     = 'manufacturing_material_issue';
    public const MOVEMENT_REVERSAL  = 'manufacturing_material_issue_reversal';
    public const LINE_REFERENCE_TYPE = 'manufacturing_consumption_line';

    public function __construct(
        private readonly ManufacturingPostingService $posting,
        private readonly InventoryService $inventory,
        private readonly JournalService $journal,
    ) {
    }

    /** Post Dr WIP / Cr Raw Material for a consumption record. Throws on any failure. */
    public function post(ManufacturingConsumptionRecord $record, ?int $userId = null): ManufacturingConsumptionRecord
    {
        $record->loadMissing(['lines.componentProduct', 'branch', 'wipJob']);

        // ── Preconditions (no mutation before all pass) ──────────────────────────
        $settings = $this->posting->assertSettingsReady($record->branch_id); // enabled + complete
        $this->posting->assertUnposted($record);

        if ($record->status === 'cancelled') {
            throw new RuntimeException('A cancelled consumption record cannot be posted.');
        }
        if (! $record->branch_id) {
            throw new RuntimeException('Consumption record has no branch.');
        }
        if ($record->lines->isEmpty()) {
            throw new RuntimeException('Consumption record has no lines to post.');
        }
        if (! $settings->raw_material_inventory_account_id || ! $settings->wip_inventory_account_id) {
            throw new RuntimeException('Raw Material / WIP inventory accounts are not mapped in posting settings.');
        }
        if ($this->posting->alreadyHasJournal(self::SOURCE_TYPE, $record->id)) {
            throw new RuntimeException('A posted journal already exists for this consumption record.');
        }

        foreach ($record->lines as $line) {
            $product = $line->componentProduct;
            if (! $product) {
                throw new RuntimeException('A consumption line has no component product.');
            }
            if ($product->status !== 'active') {
                throw new RuntimeException($product->name . ' is not active and cannot be consumed.');
            }
            if (! $product->is_stock_tracked) {
                throw new RuntimeException($product->name . ' is not stock-tracked and cannot be consumed.');
            }
            if (! $product->can_be_bom_component) {
                throw new RuntimeException($product->name . ' is not allowed as a BOM component.');
            }
            if ((float) $line->consumed_quantity <= 0) {
                throw new RuntimeException('Every consumption line must have a consumed quantity greater than zero.');
            }
            if ($this->posting->alreadyHasStockMovement(self::LINE_REFERENCE_TYPE, $line->id, self::MOVEMENT_ISSUE)) {
                throw new RuntimeException('A stock movement already exists for a line in this record.');
            }
        }

        $branch = $record->branch ?: Branch::findOrFail($record->branch_id);

        // ── Atomic: stock issue (FEFO) → WIP accrual → journal → state ───────────
        return DB::connection('tenant')->transaction(function () use ($record, $settings, $branch, $userId) {
            $totalCost = 0.0;

            foreach ($record->lines as $line) {
                $product = $line->componentProduct;
                $qty     = (float) $line->consumed_quantity;

                // BUG FIX (same as KitchenWastageController): stock lives under the
                // product's DEFAULT variant — a null variant makes postOutFefo find no
                // balances and fail "Insufficient stock" for every normally-stocked item.
                $variant = $this->inventory->resolveVariant($product, null);

                // FEFO stock-out. Throws "Insufficient stock for {name}" when short (block policy).
                $ledgers = $this->inventory->postOutFefo(
                    branch:        $branch,
                    product:       $product,
                    variant:       $variant,
                    quantity:      $qty,
                    movementType:  self::MOVEMENT_ISSUE,
                    referenceType: self::LINE_REFERENCE_TYPE,
                    referenceId:   $line->id,
                    referenceNo:   $record->consumption_no,
                    notes:         'Material issue — consumption ' . $record->consumption_no,
                    userId:        $userId,
                );

                $lineCost = (float) collect($ledgers)->sum(fn ($l) => (float) $l->total_cost);

                if ($lineCost <= 0) {
                    // Actual issue cost could not be derived — never post a zero-value GL line.
                    throw new RuntimeException('Actual issue cost for ' . $product->name . ' is zero; cannot post consumption.');
                }

                $line->forceFill([
                    'posted_quantity'   => $qty,
                    'actual_total_cost' => round($lineCost, 4),
                    'actual_unit_cost'  => $qty > 0 ? round($lineCost / $qty, 4) : 0,
                ])->save();

                $totalCost += $lineCost;
            }

            $totalCost = round($totalCost, 2);
            if ($totalCost <= 0) {
                throw new RuntimeException('Total consumption cost is zero; nothing to post.');
            }

            // Exactly one balanced journal: Dr WIP / Cr Raw Material.
            $journal = $this->journal->post(
                sourceType:  self::SOURCE_TYPE,
                sourceId:    $record->id,
                sourceNo:    $record->consumption_no,
                description: 'Manufacturing material issue / consumption ' . $record->consumption_no,
                entryDate:   optional($record->consumption_date)->toDateString() ?? now()->toDateString(),
                lines: [
                    [
                        'account_id'  => (int) $settings->wip_inventory_account_id,
                        'branch_id'   => $record->branch_id,
                        'description' => 'WIP material issue ' . $record->consumption_no,
                        'debit'       => $totalCost,
                        'credit'      => 0,
                    ],
                    [
                        'account_id'  => (int) $settings->raw_material_inventory_account_id,
                        'branch_id'   => $record->branch_id,
                        'description' => 'Raw material issue ' . $record->consumption_no,
                        'debit'       => 0,
                        'credit'      => $totalCost,
                    ],
                ],
                userId: $userId,
            );

            // WIP accrual: accumulate cost only. costed_quantity stays as-is (the quantity
            // basis is decided later by FG posting / WIP closing), so average_unit_cost is
            // left untouched here.
            if ($record->wip_job_id && $record->wipJob) {
                $wip = $record->wipJob;
                $wip->forceFill([
                    'accumulated_cost' => round((float) $wip->accumulated_cost + $totalCost, 4),
                ])->save();
            }

            $this->posting->markDocumentPosted($record, $journal, $userId);

            return $record->fresh(['lines', 'wipJob']);
        });
    }

    /** Reverse a posted consumption: add stock back, reverse the journal, unwind WIP cost. */
    public function reverse(ManufacturingConsumptionRecord $record, ?int $userId = null): ManufacturingConsumptionRecord
    {
        $record->loadMissing(['lines', 'branch', 'wipJob']);

        $this->posting->assertCanReverse($record); // must be posted

        if (! $record->journal_entry_id) {
            throw new RuntimeException('Consumption record has no journal to reverse.');
        }
        $original = JournalEntry::find($record->journal_entry_id);
        if (! $original) {
            throw new RuntimeException('Original consumption journal entry not found.');
        }

        $branch = $record->branch ?: Branch::findOrFail($record->branch_id);

        return DB::connection('tenant')->transaction(function () use ($record, $original, $branch, $userId) {
            $issues = StockLedger::query()
                ->where('reference_type', self::LINE_REFERENCE_TYPE)
                ->where('movement_type', self::MOVEMENT_ISSUE)
                ->whereIn('reference_id', $record->lines->pluck('id'))
                ->get();

            $reversedTotal = 0.0;

            foreach ($issues as $issue) {
                // Idempotent: skip an issue that already has a reversal.
                $alreadyReversed = StockLedger::query()
                    ->where('movement_type', self::MOVEMENT_REVERSAL)
                    ->where('reversal_of_id', $issue->id)
                    ->exists();
                if ($alreadyReversed) {
                    continue;
                }

                $product = Product::find($issue->product_id);
                if (! $product) {
                    continue;
                }
                $batch = $issue->batch; // restore the exact batch issued from

                $reversalLedger = $this->inventory->postIn(
                    branch:        $branch,
                    product:       $product,
                    variant:       $issue->product_variant_id ? ProductVariant::find($issue->product_variant_id) : null,
                    quantity:      (float) $issue->quantity,
                    unitCost:      (float) $issue->unit_cost,
                    movementType:  self::MOVEMENT_REVERSAL,
                    referenceType: self::LINE_REFERENCE_TYPE,
                    referenceId:   (int) $issue->reference_id,
                    referenceNo:   $record->consumption_no,
                    batchNo:       $batch?->batch_no,
                    expiryDate:    $batch?->expiry_date?->format('Y-m-d'),
                    notes:         'Reversal of material issue — consumption ' . $record->consumption_no,
                    userId:        $userId,
                );

                $reversalLedger->forceFill(['reversal_of_id' => $issue->id])->save();

                $reversedTotal += (float) $issue->total_cost;
            }

            // Reverse the journal (Dr Raw Material / Cr WIP). Original entry is never deleted.
            $reversalJournal = $this->journal->reverse($original, 'Consumption reversal ' . $record->consumption_no, $userId);

            // Unwind WIP accumulation, never below zero.
            if ($record->wip_job_id && $record->wipJob) {
                $wip = $record->wipJob;
                $wip->forceFill([
                    'accumulated_cost' => max(0, round((float) $wip->accumulated_cost - round($reversedTotal, 4), 4)),
                ])->save();
            }

            $this->posting->markDocumentReversed($record, $userId, $reversalJournal->id);

            return $record->fresh(['lines', 'wipJob']);
        });
    }
}
