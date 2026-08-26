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
