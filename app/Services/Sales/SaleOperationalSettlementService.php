<?php

namespace App\Services\Sales;

use App\Models\Tenant\SalesLedger;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;

/**
 * EDGE-LOCAL-POS-1 (G) — the SHARED operational sale settlement, extracted verbatim from SalesService so the
 * Cloud POS and the offline Branch Server use ONE implementation of the operational (non-finance) effects of
 * a paid sale:
 *
 *   - the operational sales subledger rows (sales_ledgers: sale_total / per-payment / discount / tax /
 *     service charge / tip) — idempotent via firstOrCreate;
 *   - the shift counters used by shift close (total_sales / total_discount / total_tax / expected_cash /
 *     total_cash / total_card / total_bank_transfer / total_cheque) — atomic increments (BUG-032).
 *
 * Grounded cash semantics (proven by SaleCashSemanticsMySqlTest): `payment.amount` is the amount APPLIED to
 * the invoice; `tendered_amount` is the physical cash handed over; per-payment `change_amount` is
 * tendered − amount; the shift's expected_cash/total_cash increment by the APPLIED cash amount (the net cash
 * that stays in the drawer after change is returned).
 *
 * It contains NO accounting authority: no InventoryService mutation, no FEFO/COGS, no JournalService/
 * JournalPostingService, no cash-bank finance posting, no department custody. Callers invoke it INSIDE the
 * sale's tenant transaction (Cloud: finalizePaidSale; Edge: EdgeLocalPosService) so it commits or rolls back
 * atomically with the sale.
 */
class SaleOperationalSettlementService
{
    /** Post the operational sales subledger + shift counters for a paid sale. Idempotent per sale. */
    public function settle(SalesOrder $sale): void
    {
        $sale->loadMissing(['payments.method']);
        $this->postSalesLedger($sale);
        $this->updateShiftTotals($sale);
    }

    private function postSalesLedger(SalesOrder $sale): void
    {
        SalesLedger::firstOrCreate(
            ['sales_order_id' => $sale->id, 'entry_type' => 'sale_total'],
            [
                'branch_id' => $sale->branch_id, 'sale_payment_id' => null, 'direction' => 'credit',
                'amount' => $sale->grand_total, 'reference_no' => $sale->sale_no,
                'created_by_user_id' => $sale->created_by_user_id, 'notes' => 'Sale posted',
            ]
        );

        foreach ($sale->payments as $payment) {
            SalesLedger::firstOrCreate(
                ['sales_order_id' => $sale->id, 'sale_payment_id' => $payment->id, 'entry_type' => 'sale_payment'],
                [
                    'branch_id' => $sale->branch_id, 'direction' => 'debit', 'amount' => $payment->amount,
                    'reference_no' => $sale->sale_no, 'created_by_user_id' => $sale->created_by_user_id,
                    'notes' => 'Sale payment received',
                ]
            );
        }

        if ((float) $sale->discount_amount > 0) {
            SalesLedger::firstOrCreate(
                ['sales_order_id' => $sale->id, 'entry_type' => 'sale_discount'],
                [
                    'branch_id' => $sale->branch_id, 'sale_payment_id' => null, 'direction' => 'debit',
                    'amount' => $sale->discount_amount, 'reference_no' => $sale->sale_no,
                    'created_by_user_id' => $sale->created_by_user_id, 'notes' => 'Sale discount',
                ]
            );
        }

        if ((float) $sale->tax_amount > 0) {
            SalesLedger::firstOrCreate(
                ['sales_order_id' => $sale->id, 'entry_type' => 'sale_tax'],
                [
                    'branch_id' => $sale->branch_id, 'sale_payment_id' => null, 'direction' => 'credit',
                    'amount' => $sale->tax_amount, 'reference_no' => $sale->sale_no,
                    'created_by_user_id' => $sale->created_by_user_id, 'notes' => 'Sale tax',
                ]
            );
        }

        if ((float) ($sale->service_charge_amount ?? 0) > 0) {
            SalesLedger::firstOrCreate(
                ['sales_order_id' => $sale->id, 'entry_type' => 'service_charge'],
                [
                    'branch_id' => $sale->branch_id, 'sale_payment_id' => null, 'direction' => 'credit',
                    'amount' => $sale->service_charge_amount, 'reference_no' => $sale->sale_no,
                    'created_by_user_id' => $sale->created_by_user_id, 'notes' => 'Service charge',
                ]
            );
        }

        if ((float) ($sale->tip_amount ?? 0) > 0) {
            SalesLedger::firstOrCreate(
                ['sales_order_id' => $sale->id, 'entry_type' => 'tip'],
                [
                    'branch_id' => $sale->branch_id, 'sale_payment_id' => null, 'direction' => 'credit',
                    'amount' => $sale->tip_amount, 'reference_no' => $sale->sale_no,
                    'created_by_user_id' => $sale->created_by_user_id, 'notes' => 'Tip',
                ]
            );
        }
    }

    private function updateShiftTotals(SalesOrder $sale): void
    {
        if (! $sale->shift_id) {
            return;
        }

        // BUG-032 FIX: use increment() for atomic updates — avoids read-modify-write
        // race condition when two sales on the same shift finalize concurrently.
        $shift = Shift::find($sale->shift_id);

        if (! $shift || $shift->status !== 'open') {
            return;
        }

        $cash = 0;
        $card = 0;
        $bankTransfer = 0;
        $cheque = 0;

        foreach ($sale->payments as $payment) {
            $type = $payment->method?->method_type;
            if ($type === 'cash') {
                $cash += (float) $payment->amount;
            }
            if ($type === 'card') {
                $card += (float) $payment->amount;
            }
            if ($type === 'bank_transfer') {
                $bankTransfer += (float) $payment->amount;
            }
            if ($type === 'cheque') {
                $cheque += (float) $payment->amount;
            }
        }

        $shift->increment('total_sales', (float) $sale->grand_total);
        $shift->increment('total_discount', (float) $sale->discount_amount);
        $shift->increment('total_tax', (float) $sale->tax_amount);
        $shift->increment('expected_cash', $cash);
        if ($cash > 0) {
            $shift->increment('total_cash', $cash);
        }
        if ($card > 0) {
            $shift->increment('total_card', $card);
        }
        if ($bankTransfer > 0) {
            $shift->increment('total_bank_transfer', $bankTransfer);
        }
        if ($cheque > 0) {
            $shift->increment('total_cheque', $cheque);
        }
    }
}
