<?php

namespace App\Services\Edge;

use App\Models\Tenant\SalesOrder;
use Illuminate\Support\Facades\DB;

/**
 * OFFLINE-SYNC-ENGINE-1C (finance atomicity closure) — INGESTION-SPECIFIC strictness over the shared
 * finance authorities.
 *
 * JournalPostingService::postPaidSale() and postSalesCashBankMovement() follow the shared Cloud contract
 * of report-and-swallow: an internal error (a missing chart-of-accounts account, a deleted mapped cash
 * account, …) is logged and swallowed, and posting simply does not happen. That is acceptable for a live
 * Cloud cashier (the operator sees the sale and finance is repaired later), but it is NOT acceptable for
 * Edge exactly-once ingestion: an ingestion must never be marked APPLIED while a REQUIRED financial effect
 * is silently missing.
 *
 * This verifier is called by EdgeInboundSaleIngestionService INSIDE the outer ingest transaction, AFTER
 * postPaidSale + postSalesCashBankMovement. It asserts the durable finance postconditions the accepted
 * paid sale requires; if any is missing/malformed it throws an IngestionRefusal, which rolls the WHOLE
 * ingestion back (sale + FEFO/COGS + payments + registry claim + any GL/cash partials). It does NOT change
 * shared finance behaviour for normal Cloud POS/catering/manufacturing flows — it only reads and verifies.
 *
 * It detects MISSING DURABLE EVIDENCE; it does not rely on any posting service throwing.
 */
class EdgeFinancePostingVerifier
{
    public const CONN = 'tenant';

    /**
     * @throws IngestionRefusal when a required GL or cash-bank effect is absent/malformed.
     */
    public function verifyPaidSale(SalesOrder $sale): void
    {
        $this->verifyGeneralLedger($sale);
        $this->verifyCashBankMovements($sale);
    }

    /**
     * F1 — FINANCE-COMPLETE OR REFUSE for an ingested SALES RETURN: the official return document, its sales-ledger
     * entry, the GL reversal (balanced, non-empty), the cash/bank refund movement (for cash / bank refunds a mappable
     * account MUST exist — a refund that never leaves the books is not a refund), and the FEFO stock reversal for every
     * stock-tracked returned product. Anything missing → IngestionRefusal → the whole ingestion rolls back.
     */
    public function verifyPostedReturn(\App\Models\Tenant\SalesReturn $return): void
    {
        $conn = DB::connection(self::CONN);
        if ((string) $return->status !== 'posted' || (float) $return->grand_total <= 0) {
            throw new IngestionRefusal('RETURN_DOCUMENT_INVALID', "sales return {$return->id} is not a posted document with a positive total");
        }
        if (! $conn->table('sales_ledgers')->where('entry_type', 'sale_return')->where('reference_no', (string) $return->return_no)->exists()) {
            throw new IngestionRefusal('RETURN_LEDGER_MISSING', "sales return {$return->id} has no sales-ledger entry");
        }
        $journal = $conn->table('journal_entries')->where('source_type', 'sales_return')->where('source_id', (int) $return->id)->where('status', 'posted')->where('is_reversal', 0)->first();
        if (! $journal) {
            throw new IngestionRefusal('FINANCE_GL_MISSING', "the required sales_return journal for return {$return->id} was not posted");
        }
        $lines = $conn->table('journal_lines')->where('journal_entry_id', (int) $journal->id)->get();
        if ($lines->isEmpty()) {
            throw new IngestionRefusal('FINANCE_GL_EMPTY', "the sales_return journal for return {$return->id} has no monetary lines");
        }
        $debit = round((float) $lines->sum('debit'), 2);
        $credit = round((float) $lines->sum('credit'), 2);
        if (abs($debit - $credit) > 0.01) {
            throw new IngestionRefusal('FINANCE_GL_UNBALANCED', "the sales_return journal for return {$return->id} is unbalanced (debit {$debit} != credit {$credit})");
        }
        if (in_array((string) $return->refund_method, ['cash', 'bank_transfer'], true)) {
            $movement = $conn->table('cash_bank_account_transactions')->where('reference_type', 'sales_return')->where('reference_id', (int) $return->id)->where('transaction_type', 'sales_return_refund')->get();
            if ($movement->count() !== 1) {
                throw new IngestionRefusal('FINANCE_CASHBANK_MISSING', "return {$return->id} refunds by {$return->refund_method} but has {$movement->count()} cash/bank refund movements (a mappable cash/bank account is required)");
            }
            $m = $movement->first();
            if ((string) $m->direction !== 'out' || abs((float) $m->amount - (float) $return->grand_total) > 0.01) {
                throw new IngestionRefusal('FINANCE_CASHBANK_INVALID', "return {$return->id} cash/bank refund movement is malformed (direction/amount mismatch)");
            }
        }
        $return->loadMissing('lines.orderLine.product', 'order.lines.product');
        foreach ($return->lines as $rl) {
            $orderLine = $rl->orderLine;
            $stockProducts = [];
            if ($orderLine && ($orderLine->line_kind ?? 'standard') === 'combo_header' && $return->order) {
                foreach ($return->order->lines->where('parent_sales_order_line_id', $orderLine->id) as $child) {
                    if ($child->product?->is_stock_tracked) {
                        $stockProducts[] = (int) $child->product_id;
                    }
                }
            } elseif ($orderLine?->product?->is_stock_tracked) {
                $stockProducts[] = (int) $orderLine->product_id;
            }
            foreach ($stockProducts as $productId) {
                $has = $conn->table('stock_ledgers')->where('reference_type', 'sales_return')->where('reference_id', (int) $return->id)
                    ->where('product_id', $productId)->where('movement_type', 'sale_return')->where('direction', 'in')->exists();
                if (! $has) {
                    throw new IngestionRefusal('RETURN_STOCK_MISSING', "return {$return->id} restocks product {$productId} but no official stock reversal was posted");
                }
            }
        }
    }

    /**
     * A positive fully-paid sale MUST carry a posted, non-reversal `sales_order_paid` journal for THIS
     * Cloud sales_order, and its lines MUST balance (sum debit == sum credit) and be non-zero. This is the
     * exact journal postPaidSale posts when grand_total > 0 and the sale is fully paid — which every
     * ingested paid sale is (validated upstream; ingestion never creates a credit sale).
     */
    private function verifyGeneralLedger(SalesOrder $sale): void
    {
        $conn = DB::connection(self::CONN);

        $entry = $conn->table('journal_entries')
            ->where('source_type', 'sales_order_paid')
            ->where('source_id', $sale->id)
            ->where('status', 'posted')
            ->where('is_reversal', 0)
            ->first(['id']);

        if (! $entry) {
            throw new IngestionRefusal('FINANCE_GL_MISSING', "the required sales_order_paid journal for sale {$sale->id} was not posted (finance service swallowed an internal error)");
        }

        $sums = $conn->table('journal_lines')->where('journal_entry_id', $entry->id)
            ->selectRaw('COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS c')->first();
        $debit = round((float) $sums->d, 2);
        $credit = round((float) $sums->c, 2);

        if ($debit <= 0.0 || $credit <= 0.0) {
            throw new IngestionRefusal('FINANCE_GL_EMPTY', "the sales_order_paid journal for sale {$sale->id} has no monetary lines");
        }
        if (abs($debit - $credit) > 0.01) {
            throw new IngestionRefusal('FINANCE_GL_UNBALANCED', "the sales_order_paid journal for sale {$sale->id} is unbalanced (debit {$debit} != credit {$credit})");
        }
    }

    /**
     * For EVERY payment whose payment method maps to a real cash/bank account, exactly the established
     * idempotent movement MUST exist: reference_type=sale_payment, reference_id=payment.id,
     * transaction_type=sales_payment, direction='in', amount == payment.amount. A payment method with NO
     * mapped account intentionally posts none (the shared GL fallback covers it) — that is respected, not
     * invented.
     */
    private function verifyCashBankMovements(SalesOrder $sale): void
    {
        $conn = DB::connection(self::CONN);
        $sale->loadMissing('payments.method');

        foreach ($sale->payments as $payment) {
            $mappedAccountId = $payment->method?->cash_bank_account_id;
            if (! $mappedAccountId) {
                continue; // no mapped account -> no cash-bank movement is required (existing Cloud semantics)
            }

            $rows = $conn->table('cash_bank_account_transactions')
                ->where('reference_type', 'sale_payment')
                ->where('reference_id', $payment->id)
                ->where('transaction_type', 'sales_payment')
                ->get(['direction', 'amount', 'cash_bank_account_id']);

            if ($rows->count() !== 1) {
                throw new IngestionRefusal('FINANCE_CASHBANK_MISSING', "payment {$payment->id} maps to a cash/bank account but has {$rows->count()} required movements (expected exactly one; finance service swallowed an internal error)");
            }
            $row = $rows->first();
            if ((string) $row->direction !== 'in' || abs((float) $row->amount - (float) $payment->amount) > 0.01) {
                throw new IngestionRefusal('FINANCE_CASHBANK_INVALID', "payment {$payment->id} cash-bank movement is malformed (direction/amount mismatch)");
            }
        }
    }
}
