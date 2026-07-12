<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\Branch;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\PurchaseReturnLine;
use App\Models\Tenant\StockBalance;
use App\Services\Finance\JournalPostingService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PURCHASE-RETURNS-1 — supplier purchase return lifecycle.
 *
 * draft (no stock/finance impact, editable/cancellable)
 *   → posted (immutable):
 *       stock OUT via InventoryService movement 'purchase_return' (FEFO),
 *       supplier ledger CREDIT (payable down — may go into supplier credit),
 *       GL Dr 2100 AP / Cr 1400 Inventory (mirror of the purchase bill).
 *
 * Never edits stock_balances by hand; GL is idempotent per source.
 */
class PurchaseReturnService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly PurchasingService $purchasingService,
    ) {}

    public function nextReturnNo(): string
    {
        return 'PRET-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    /**
     * @param array $header branch_id, supplier_id, goods_receipt_id?, purchase_order_id?, return_date, reason_code?, notes?
     * @param array<int, array> $lines product_id, product_variant_id?, source_line_id?, quantity, unit_cost, reason_code?, notes?
     */
    public function createDraft(array $header, array $lines, ?int $userId = null): PurchaseReturn
    {
        return DB::connection('tenant')->transaction(function () use ($header, $lines, $userId) {
            $return = PurchaseReturn::create([
                'branch_id'         => (int) $header['branch_id'],
                'supplier_id'       => (int) $header['supplier_id'],
                'purchase_order_id' => $header['purchase_order_id'] ?? null,
                'goods_receipt_id'  => $header['goods_receipt_id'] ?? null,
                'return_no'         => $this->nextReturnNo(),
                'return_date'       => $header['return_date'],
                'status'            => 'draft',
                'reason_code'       => $header['reason_code'] ?? null,
                'notes'             => $header['notes'] ?? null,
                'created_by'        => $userId,
            ]);

            $this->syncLines($return, $lines);
            $this->calculateTotals($return);

            return $return->fresh(['lines']);
        });
    }

    public function updateDraft(PurchaseReturn $return, array $header, array $lines): PurchaseReturn
    {
        if (! $return->canEdit()) {
            throw new RuntimeException('Only draft returns can be edited.');
        }

        return DB::connection('tenant')->transaction(function () use ($return, $header, $lines) {
            $return->update([
                'return_date' => $header['return_date'],
                'reason_code' => $header['reason_code'] ?? null,
                'notes'       => $header['notes'] ?? null,
            ]);

            $return->lines()->delete();
            $this->syncLines($return, $lines);
            $this->calculateTotals($return);

            return $return->fresh(['lines']);
        });
    }

    public function calculateTotals(PurchaseReturn $return): void
    {
        $return->load('lines');

        $subtotal = 0.0;
        $tax = 0.0;
        $discount = 0.0;

        foreach ($return->lines as $line) {
            $lineSubtotal = (float) $line->quantity * (float) $line->unit_cost;
            $lineTotal    = $lineSubtotal - (float) $line->discount_amount + (float) $line->tax_amount;
            $line->update(['line_total' => round($lineTotal, 4)]);

            $subtotal += $lineSubtotal;
            $tax      += (float) $line->tax_amount;
            $discount += (float) $line->discount_amount;
        }

        $return->update([
            'subtotal'       => round($subtotal, 4),
            'tax_total'      => round($tax, 4),
            'discount_total' => round($discount, 4),
            'grand_total'    => round($subtotal - $discount + $tax, 4),
        ]);
    }

    /**
     * Returnable = received − already returned (posted docs). Considers lines
     * on OTHER posted returns; intra-document duplicates of the same source
     * line are summed together.
     */
    public function returnableForGrnLine(GoodsReceiptLine $grnLine, ?int $excludeReturnId = null): float
    {
        $alreadyReturned = (float) PurchaseReturnLine::query()
            ->where('source_line_type', 'goods_receipt_line')
            ->where('source_line_id', $grnLine->id)
            ->whereHas('purchaseReturn', fn ($q) => $q
                ->where('status', 'posted')
                ->when($excludeReturnId, fn ($w) => $w->where('id', '!=', $excludeReturnId)))
            ->sum('quantity');

        return (float) $grnLine->quantity_received - $alreadyReturned;
    }

    public function validateReturnableQuantities(PurchaseReturn $return): void
    {
        $return->load('lines.product');

        if ($return->lines->isEmpty()) {
            throw new RuntimeException('At least one product line is required.');
        }

        // GRN-sourced lines: sum per source line, compare to returnable.
        $bySource = $return->lines->where('source_line_type', 'goods_receipt_line')->groupBy('source_line_id');
        foreach ($bySource as $sourceLineId => $lines) {
            $grnLine = GoodsReceiptLine::find($sourceLineId);
            if (! $grnLine) {
                throw new RuntimeException('A source receipt line no longer exists — refresh and rebuild the return.');
            }
            $requested  = (float) $lines->sum('quantity');
            $returnable = $this->returnableForGrnLine($grnLine, $return->id);
            if ($requested > $returnable + 0.0005) {
                $name = $lines->first()->product?->name ?? ('#' . $grnLine->product_id);
                throw new RuntimeException(
                    "Cannot return " . number_format($requested, 3) . " of {$name}: only "
                    . number_format(max($returnable, 0), 3) . ' returnable on this receipt line (received '
                    . number_format((float) $grnLine->quantity_received, 3) . ', already returned '
                    . number_format((float) $grnLine->quantity_received - $returnable, 3) . ').'
                );
            }
        }

        // Every line (sourced or standalone): official branch stock must cover it.
        $byProductVariant = $return->lines->groupBy(fn ($l) => $l->product_id . '-' . ($l->product_variant_id ?: 0));
        foreach ($byProductVariant as $lines) {
            $first = $lines->first();
            $onHand = (float) StockBalance::query()
                ->where('branch_id', $return->branch_id)
                ->where('product_id', $first->product_id)
                ->when($first->product_variant_id, fn ($q) => $q->where('product_variant_id', $first->product_variant_id))
                ->sum('quantity_on_hand');
            $requested = (float) $lines->sum('quantity');
            if ($requested > $onHand + 0.0005) {
                $name = $first->product?->name ?? ('#' . $first->product_id);
                throw new RuntimeException(
                    "Insufficient branch stock to return {$name}: on hand "
                    . number_format($onHand, 3) . ', returning ' . number_format($requested, 3)
                    . '. Stock may already be sold or transferred.'
                );
            }
        }
    }

    /**
     * Post: stock OUT + supplier ledger credit + GL. Locks the header —
     * double posting is impossible.
     */
    public function post(PurchaseReturn $return, ?int $userId = null): PurchaseReturn
    {
        $posted = DB::connection('tenant')->transaction(function () use ($return, $userId) {
            /** @var PurchaseReturn $doc */
            $doc = PurchaseReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if ($doc->isPosted()) {
                throw new RuntimeException("Return {$doc->return_no} is already posted.");
            }
            if ($doc->isCancelled()) {
                throw new RuntimeException("Return {$doc->return_no} is cancelled and cannot be posted.");
            }

            $this->calculateTotals($doc);
            $this->validateReturnableQuantities($doc);

            $branch = Branch::findOrFail($doc->branch_id);

            foreach ($doc->lines as $line) {
                $product = Product::findOrFail($line->product_id);
                $variant = $this->inventoryService->resolveVariant($product, $line->product_variant_id);

                // FEFO out — oldest-expiry batches leave first. The stored line
                // batch_no (from the GRN) is informational in v1.
                $this->inventoryService->postOutFefo(
                    branch: $branch,
                    product: $product,
                    variant: $variant,
                    quantity: (float) $line->quantity,
                    movementType: 'purchase_return',
                    referenceType: 'purchase_return',
                    referenceId: $doc->id,
                    referenceNo: $doc->return_no,
                    notes: 'Return to supplier' . ($line->reason_code ? ' (' . $line->reason_code . ')' : ''),
                    userId: $userId,
                );
            }

            // Supplier payable DOWN (mirror of the bill's debit). A fully paid
            // supplier simply goes into credit (negative balance) — standard
            // trade credit, consistent with the running-balance ledger.
            $this->purchasingService->postSupplierLedger(
                $doc->supplier,
                'purchase_return',
                'credit',
                (float) $doc->grand_total,
                PurchaseReturn::class,
                $doc->id,
                $doc->return_no,
                $doc->notes,
                $userId
            );

            $doc->update([
                'status'    => 'posted',
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $doc;
        });

        // GL OUTSIDE the operational transaction — same pattern as postBill:
        // journal posting is idempotent + never throws, so a GL hiccup can
        // never roll back the operational return.
        $entry = app(JournalPostingService::class)->postPurchaseReturn($posted, $userId);
        if ($entry) {
            $posted->update(['journal_entry_id' => $entry->id]);
        }

        return $posted->fresh(['lines', 'supplier', 'branch']);
    }

    public function cancelDraft(PurchaseReturn $return, ?int $userId = null): void
    {
        if (! $return->isDraft()) {
            throw new RuntimeException('Only draft returns can be cancelled.');
        }

        $return->update([
            'status'       => 'cancelled',
            'cancelled_by' => $userId,
            'cancelled_at' => now(),
        ]);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function syncLines(PurchaseReturn $return, array $lines): void
    {
        $created = 0;

        foreach ($lines as $line) {
            $qty = (float) ($line['quantity'] ?? 0);
            if (empty($line['product_id']) || $qty <= 0) {
                continue;
            }

            $product = Product::findOrFail((int) $line['product_id']);
            $variant = $this->inventoryService->resolveVariant(
                $product,
                ! empty($line['product_variant_id']) ? (int) $line['product_variant_id'] : null
            );

            $sourceLineId = ! empty($line['source_line_id']) ? (int) $line['source_line_id'] : null;
            $batchId = null;
            $unitCost = (float) ($line['unit_cost'] ?? 0);

            if ($sourceLineId) {
                $grnLine = GoodsReceiptLine::find($sourceLineId);
                if (! $grnLine || (int) $grnLine->product_id !== (int) $product->id) {
                    throw new RuntimeException('Invalid source receipt line for ' . $product->name . '.');
                }
                if ($return->goods_receipt_id && (int) $grnLine->goods_receipt_id !== (int) $return->goods_receipt_id) {
                    throw new RuntimeException('Source line does not belong to the selected receipt.');
                }
                if ($unitCost <= 0) {
                    $unitCost = (float) $grnLine->unit_cost;
                }
            }

            $return->lines()->create([
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'inventory_batch_id' => $batchId,
                'source_line_type'   => $sourceLineId ? 'goods_receipt_line' : null,
                'source_line_id'     => $sourceLineId,
                'quantity'           => $qty,
                'unit_cost'          => $unitCost,
                'tax_amount'         => (float) ($line['tax_amount'] ?? 0),
                'discount_amount'    => (float) ($line['discount_amount'] ?? 0),
                'reason_code'        => $line['reason_code'] ?? null,
                'notes'              => $line['notes'] ?? null,
            ]);
            $created++;
        }

        if ($created === 0) {
            throw new RuntimeException('At least one product line with quantity is required.');
        }
    }
}
