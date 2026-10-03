<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\Branch;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierLedger;
use App\Models\Tenant\SupplierPayment;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchasingService
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    public function postSupplierLedger(
        Supplier $supplier,
        string $entryType,
        string $direction,
        float $amount,
        string $referenceType,
        int $referenceId,
        string $referenceNo,
        ?string $notes = null,
        ?int $userId = null
    ): SupplierLedger {
        // BUG-042 FIX: lock the supplier row before read-modify-write so concurrent
        // GRN postings and payments cannot corrupt the running balance.
        return DB::connection('tenant')->transaction(function () use (
            $supplier, $entryType, $direction, $amount,
            $referenceType, $referenceId, $referenceNo, $notes, $userId
        ) {
            $supplier = Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            $balance = $direction === 'debit'
                ? $supplier->current_balance + $amount
                : $supplier->current_balance - $amount;

            $ledger = SupplierLedger::create([
                'supplier_id'        => $supplier->id,
                'entry_type'         => $entryType,
                'direction'          => $direction,
                'amount'             => $amount,
                'balance_after'      => $balance,
                'reference_type'     => $referenceType,
                'reference_id'       => $referenceId,
                'reference_no'       => $referenceNo,
                'notes'              => $notes,
                'created_by_user_id' => $userId,
            ]);

            $supplier->update(['current_balance' => $balance]);

            return $ledger;
        });
    }

    public function postGrn(GoodsReceipt $grn, ?int $userId = null): void
    {
        $landed = $this->landedUnitCosts($grn);

        foreach ($grn->lines as $line) {
            $product = $line->product;
            $variant = $line->variant;
            $branch  = $grn->branch;

            $this->inventoryService->postIn(
                $branch,
                $product,
                $variant,
                (float) $line->quantity_received,
                $landed[$line->id] ?? (float) $line->unit_cost,
                'purchase',
                GoodsReceipt::class,
                $grn->id,
                $grn->grn_no,
                $line->batch_no,
                $line->expiry_date?->toDateString(),
                $line->notes,
                $userId
            );
        }
    }

    /**
     * GRN-EXTRA-CHARGES-1 — spread the receipt's extra charges into what the goods actually cost.
     *
     * Cartage, labour and unloading are paid to GET the goods here, so they belong in what those
     * goods cost — the landed cost. Without a home for them a counter books them as a product
     * line instead, which is exactly what happened: four GRNs carried "Spoon, qty 1, rate 400"
     * to record cartage, inflating a real item's stock by four.
     *
     * The split is BY VALUE, not by quantity: 400 of cartage on a receipt of 300 containers and
     * 500 spoons belongs mostly to the containers, because they are most of what was paid for.
     *
     * Only the INVENTORY cost moves. The line keeps the price the supplier charged, so the
     * receipt still reconciles against the supplier's own document.
     *
     * PRECISION, stated plainly: stock_ledgers.unit_cost is decimal(14,4) and the ledger stores
     * total = quantity x unit_cost, so a charge spread over a unit price CANNOT reconcile to the
     * paisa in general — 400 over 1,000 units is 0.4 per unit only by luck. The shortfall is
     * bounded at half a paisa per unit received (sum(qty) x 0.00005), e.g. 0.05 on a 1,500-unit
     * receipt. Carrying the residue exactly would mean writing total_cost independently of
     * unit_cost inside InventoryService, which every stock path in the system depends on — not
     * worth it for half a paisa. The guard asserts this bound rather than pretending to exactness.
     *
     * @return array<int, float> line id => landed unit cost
     */
    private function landedUnitCosts(GoodsReceipt $grn): array
    {
        $charges = round((float) ($grn->extra_charges ?? 0), 4);
        $costs = [];
        foreach ($grn->lines as $line) {
            $costs[$line->id] = (float) $line->unit_cost;
        }

        if ($charges <= 0 || ! $costs) {
            return $costs;
        }

        $values = [];
        foreach ($grn->lines as $line) {
            $values[$line->id] = (float) $line->quantity_received * (float) $line->unit_cost;
        }
        $total = array_sum($values);

        // A receipt of free samples has no value to split by; fall back to quantity so the charge
        // is still carried rather than silently dropped.
        if ($total <= 0) {
            foreach ($grn->lines as $line) {
                $values[$line->id] = (float) $line->quantity_received;
            }
            $total = array_sum($values);
            if ($total <= 0) {
                return $costs;
            }
        }

        // The last carrier takes whatever is left, so the SHARES add back up to the charge exactly
        // rather than drifting by a few ten-thousandths.
        //
        // Honest note: this is belt-and-braces and is NOT separately guarded, because its effect
        // cannot be observed. Share drift is ~0.0001; the unit_cost rounding above it is ~100x
        // larger and sets the floor for anything measurable through the ledger. Deleting these
        // lines leaves every test green — that was checked, not assumed. It is kept because it is
        // correct and costs nothing, not because a test is holding it in place.
        // The remainder goes to the last line that can CARRY it — a zero-quantity line is
        // skipped below, and handing it the remainder would drop that money on the floor.
        $carriers = $grn->lines->filter(fn ($l) => (float) $l->quantity_received > 0)->pluck('id')->all();
        if (! $carriers) {
            return $costs;
        }
        $lastId = end($carriers);
        $assigned = 0.0;

        foreach ($grn->lines as $line) {
            $qty = (float) $line->quantity_received;
            if ($qty <= 0) {
                continue;
            }

            $share = $line->id === $lastId
                ? round($charges - $assigned, 4)
                : round($charges * ($values[$line->id] / $total), 4);
            $assigned = round($assigned + $share, 4);

            $costs[$line->id] = round((float) $line->unit_cost + ($share / $qty), 4);
        }

        return $costs;
    }

    /**
     * GRN-VOID-1 — take back a receipt that has not been billed yet.
     *
     * A posted GRN is not editable and should not be: changing a quantity after the fact would
     * rewrite stock history that FEFO layers, average cost and any sale since then already depend
     * on, with nothing on the record to say it happened. Voiding is the honest alternative — the
     * original receipt stays exactly as it was and the stock is undone by its own entries.
     *
     * Three things must be true, and each refusal says which one was not:
     *  - no bill, because a bill is a payable the supplier has already been told about;
     *  - still posted, so voiding twice cannot double-reverse the stock;
     *  - the goods are still on hand, because what has been sold or issued cannot be unreceived.
     *
     * @throws RuntimeException when any of those does not hold
     */
    public function voidGrn(GoodsReceipt $grn, ?int $userId = null, ?string $reason = null): void
    {
        if ($grn->status !== 'posted') {
            throw new RuntimeException('This receipt is already voided.');
        }
        if ($grn->bill) {
            throw new RuntimeException(
                'A purchase bill has already been created from this receipt, so it cannot be voided. '
                . 'Use a Purchase Return instead.'
            );
        }

        DB::connection('tenant')->transaction(function () use ($grn, $userId, $reason) {
            foreach ($grn->lines as $line) {
                $received = (float) $line->quantity_received;
                if ($received <= 0) {
                    continue;
                }

                // The receipt's own ledger rows carry the LANDED cost it was posted at, which is
                // what has to come back out — recomputing it would quietly value the reversal
                // differently from the receipt and leave the stock carrying a cost nobody booked.
                $entries = StockLedger::query()
                    ->where('reference_type', GoodsReceipt::class)
                    ->where('reference_id', $grn->id)
                    ->where('product_id', $line->product_id)
                    ->where('direction', 'in')
                    ->whereNull('reversal_of_id')
                    ->get();

                foreach ($entries as $entry) {
                    $onHand = (float) StockBalance::query()
                        ->where('branch_id', $entry->branch_id)
                        ->where('product_id', $entry->product_id)
                        ->sum('quantity_on_hand');

                    if ($onHand < (float) $entry->quantity) {
                        throw new RuntimeException(
                            $line->product?->name . ' no longer has the quantity this receipt brought in '
                            . '(' . rtrim(rtrim(number_format((float) $entry->quantity, 3), '0'), '.') . ' needed, '
                            . rtrim(rtrim(number_format($onHand, 3), '0'), '.') . ' on hand), '
                            . 'so the receipt cannot be voided. Use a Purchase Return or a Stock Adjustment.'
                        );
                    }

                    $balance = $onHand - (float) $entry->quantity;

                    StockLedger::create([
                        'branch_id'          => $entry->branch_id,
                        'product_id'         => $entry->product_id,
                        'product_variant_id' => $entry->product_variant_id,
                        'inventory_batch_id' => $entry->inventory_batch_id,
                        'movement_type'      => 'adjustment_out',
                        'direction'          => 'out',
                        'quantity'           => $entry->quantity,
                        'unit_cost'          => $entry->unit_cost,
                        'total_cost'         => $entry->total_cost,
                        'balance_after'      => $balance,
                        'reference_type'     => GoodsReceipt::class,
                        'reference_id'       => $grn->id,
                        'reference_no'       => $grn->grn_no,
                        'reversal_of_id'     => $entry->id,
                        'notes'              => 'Goods receipt voided' . ($reason ? ' — ' . $reason : ''),
                        'created_by_user_id' => $userId,
                    ]);

                    StockBalance::query()
                        ->where('branch_id', $entry->branch_id)
                        ->where('product_id', $entry->product_id)
                        ->decrement('quantity_on_hand', (float) $entry->quantity);
                }
            }

            $grn->forceFill([
                'status'            => 'voided',
                'voided_at'         => now(),
                'voided_by_user_id' => $userId,
                'void_reason'       => $reason,
            ])->save();
        });
    }
    public function postBill(PurchaseBill $bill, ?int $userId = null): void
    {
        $this->postBillOperational($bill, $userId);
        // GL journal outside — safe + idempotent (JournalPostingService catches/reports).
        app(\App\Services\Finance\JournalPostingService::class)->postPurchaseBill($bill, $userId);
    }

    /**
     * BUG-044 FIX — operational-only bill posting (supplier ledger).
     * Called inside a DB transaction; GL journal is intentionally excluded so
     * a GL failure never rolls back the operational bill creation.
     */
    public function postBillOperational(PurchaseBill $bill, ?int $userId = null): void
    {
        $this->postSupplierLedger(
            $bill->supplier,
            'purchase_bill',
            'debit',
            (float) $bill->grand_total,
            PurchaseBill::class,
            $bill->id,
            $bill->bill_no,
            $bill->notes,
            $userId
        );
    }

    public function postPayment(SupplierPayment $payment, ?int $userId = null): void
    {
        $supplier = $payment->supplier;

        $this->postSupplierLedger(
            $supplier,
            'payment',
            'credit',
            (float) $payment->amount,
            SupplierPayment::class,
            $payment->id,
            $payment->payment_no,
            $payment->notes,
            $userId
        );

        if ($payment->purchase_bill_id) {
            $bill = PurchaseBill::find($payment->purchase_bill_id);
            if ($bill) {
                $newPaid = $bill->amount_paid + $payment->amount;
                $newBalance = max(0, $bill->grand_total - $newPaid);
                $status = $newBalance <= 0 ? 'paid' : 'partial';
                $bill->update([
                    'amount_paid' => $newPaid,
                    'balance_due' => $newBalance,
                    'status'      => $status,
                ]);
            }
        }
    }

    public function nextPoNo(): string
    {
        return 'PO-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    public function nextGrnNo(): string
    {
        return 'GRN-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    public function nextBillNo(): string
    {
        return 'BILL-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    public function nextPaymentNo(): string
    {
        return 'PAY-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }
}
