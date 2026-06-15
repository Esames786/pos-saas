<?php

namespace App\Services\Finance;

use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CustomerPayment;
use App\Models\Tenant\ExpenseVoucher;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SupplierPayment;
use Throwable;

/**
 * Translates operational finance events into balanced double-entry journals (FIN-7).
 *
 * Every method is idempotent (via JournalService source keys) and SAFE: it returns
 * null and reports the problem rather than throwing, so a missing account or unmapped
 * event can never break the operational flow that triggered it.
 */
class JournalPostingService
{
    public function __construct(private JournalService $journal) {}

    /** Dr expense accounts / Cr cash-bank. Returns null if no cash/bank CoA link. */
    public function postExpenseVoucher(ExpenseVoucher $voucher, ?int $userId = null): ?JournalEntry
    {
        try {
            if (! $voucher->isPosted()) {
                return null;
            }

            $voucher->loadMissing(['lines', 'cashBankAccount']);

            $creditAccountId = $voucher->cashBankAccount?->account_id;
            if (! $creditAccountId) {
                report(new \RuntimeException("Expense voucher {$voucher->voucher_no} cash/bank account has no linked CoA account; journal skipped."));
                return null;
            }

            $lines = [];
            $fallbackExpenseId = $this->journal->accountId('6800'); // Miscellaneous Expense

            foreach ($voucher->lines as $line) {
                $lines[] = [
                    'account_id'  => $line->account_id ?: $fallbackExpenseId,
                    'branch_id'   => $voucher->branch_id,
                    'description' => $line->description ?: 'Expense',
                    'debit'       => (float) $line->line_total,
                    'credit'      => 0,
                ];
            }

            $lines[] = [
                'account_id'  => $creditAccountId,
                'branch_id'   => $voucher->branch_id,
                'description' => 'Paid from ' . ($voucher->cashBankAccount->name ?? 'cash/bank'),
                'debit'       => 0,
                'credit'      => (float) $voucher->total_amount,
            ];

            return $this->journal->post(
                'expense_voucher',
                $voucher->id,
                $voucher->voucher_no,
                'Expense voucher ' . $voucher->voucher_no,
                ($voucher->payment_date ?? $voucher->expense_date)?->toDateString() ?? now()->toDateString(),
                $lines,
                $userId
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /** Dr Accounts Payable / Cr cash-bank. Null if no cash/bank account on the payment. */
    public function postSupplierPayment(SupplierPayment $payment, ?int $userId = null): ?JournalEntry
    {
        try {
            if (! $payment->cash_bank_account_id) {
                return null; // no cash/bank source — nothing to journal
            }

            $creditAccountId = $this->cashBankCoaId($payment->cash_bank_account_id);
            if (! $creditAccountId) {
                return null;
            }

            $lines = [
                ['account_code' => '2100', 'branch_id' => $payment->branch_id, 'description' => 'Accounts Payable', 'debit' => (float) $payment->amount, 'credit' => 0],
                ['account_id' => $creditAccountId, 'branch_id' => $payment->branch_id, 'description' => 'Supplier payment ' . $payment->payment_no, 'debit' => 0, 'credit' => (float) $payment->amount],
            ];

            return $this->journal->post(
                'supplier_payment',
                $payment->id,
                $payment->payment_no,
                'Supplier payment ' . $payment->payment_no,
                $payment->payment_date?->toDateString() ?? now()->toDateString(),
                $lines,
                $userId
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /** Dr cash-bank / Cr Accounts Receivable. Null if no cash/bank account on the payment. */
    public function postCustomerPayment(CustomerPayment $payment, ?int $userId = null): ?JournalEntry
    {
        try {
            if (! $payment->cash_bank_account_id) {
                return null;
            }

            $debitAccountId = $this->cashBankCoaId($payment->cash_bank_account_id);
            if (! $debitAccountId) {
                return null;
            }

            $lines = [
                ['account_id' => $debitAccountId, 'branch_id' => $payment->branch_id, 'description' => 'Customer payment ' . $payment->payment_no, 'debit' => (float) $payment->amount, 'credit' => 0],
                ['account_code' => '1300', 'branch_id' => $payment->branch_id, 'description' => 'Accounts Receivable', 'debit' => 0, 'credit' => (float) $payment->amount],
            ];

            return $this->journal->post(
                'customer_payment',
                $payment->id,
                $payment->payment_no,
                'Customer payment ' . $payment->payment_no,
                $payment->payment_date?->toDateString() ?? now()->toDateString(),
                $lines,
                $userId
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /**
     * Dr cash/bank (by payment-method mapping) / Cr sales revenue + tax + service + tips,
     * with sales discount as a contra-income debit, for a fully-paid POS sale (FIN-7B).
     *
     * Revenue is computed as the balancing figure so the entry always balances.
     * COGS is deferred (see FIN-7C). Returns null for non-paid sales or credit sales.
     */
    public function postPaidSale(SalesOrder $sale, ?int $userId = null): ?JournalEntry
    {
        try {
            $grand = round((float) $sale->grand_total, 4);
            if ($grand <= 0) {
                return null;
            }

            // Must be fully paid; never journal a sale that still has a receivable.
            $balanceDue = round((float) ($sale->balance_due ?? 0), 4);
            $fullyPaid = $sale->payment_status === 'paid'
                || ($balanceDue <= 0 && (float) $sale->paid_amount + 0.01 >= $grand);
            if (! $fullyPaid) {
                return null;
            }

            // If this sale was already journaled as a credit sale, don't also post it as paid.
            if ($this->journal->findPostedForSource('sales_order_credit', $sale->id)) {
                return null;
            }

            $sale->loadMissing(['payments.method.cashBankAccount']);

            $discount = round((float) ($sale->discount_amount ?? 0), 4);
            $tax      = round((float) ($sale->tax_amount ?? 0), 4);
            $service  = round((float) ($sale->service_charge_amount ?? 0), 4);
            $tip      = round((float) ($sale->tip_amount ?? 0), 4);

            $undepositedId = $this->journal->accountId('1500');

            // ── Debit side: allocate grand_total across payment methods' mapped accounts ──
            $lines = [];
            $remaining = $grand;
            foreach ($sale->payments as $payment) {
                if ($remaining <= 0) {
                    break;
                }
                $applied = min(round((float) $payment->amount, 4), $remaining);
                if ($applied <= 0) {
                    continue;
                }
                $accountId = $payment->method?->cashBankAccount?->account_id ?? $undepositedId;
                $lines[] = [
                    'account_id'  => $accountId,
                    'branch_id'   => $sale->branch_id,
                    'description' => 'Sale receipt (' . ($payment->method?->name ?? 'payment') . ')',
                    'debit'       => $applied,
                    'credit'      => 0,
                ];
                $remaining -= $applied;
            }
            if (empty($lines)) {
                $lines[] = ['account_id' => $undepositedId, 'branch_id' => $sale->branch_id, 'description' => 'Sale proceeds', 'debit' => $grand, 'credit' => 0];
                $remaining = 0.0;
            } elseif ($remaining > 0.0001) {
                $lines[] = ['account_id' => $undepositedId, 'branch_id' => $sale->branch_id, 'description' => 'Sale proceeds (unallocated)', 'debit' => round($remaining, 4), 'credit' => 0];
                $remaining = 0.0;
            }

            $revenueCode = in_array($sale->order_type, ['dine_in', 'takeaway', 'delivery'], true) ? '4120' : '4110';
            $revenue = round($grand + $discount - $tax - $service - $tip, 4);

            if ($revenue > 0) {
                if ($discount > 0) {
                    $lines[] = ['account_code' => '4200', 'branch_id' => $sale->branch_id, 'description' => 'Sales discount', 'debit' => $discount, 'credit' => 0];
                }
                if ($tax > 0) {
                    $lines[] = ['account_code' => '2200', 'branch_id' => $sale->branch_id, 'description' => 'Sales tax payable', 'debit' => 0, 'credit' => $tax];
                }
                if ($service > 0) {
                    $lines[] = ['account_code' => '4130', 'branch_id' => $sale->branch_id, 'description' => 'Service charge', 'debit' => 0, 'credit' => $service];
                }
                if ($tip > 0) {
                    $lines[] = ['account_code' => '4140', 'branch_id' => $sale->branch_id, 'description' => 'Tips', 'debit' => 0, 'credit' => $tip];
                }
                $lines[] = ['account_code' => $revenueCode, 'branch_id' => $sale->branch_id, 'description' => 'Sales revenue ' . $sale->sale_no, 'debit' => 0, 'credit' => $revenue];
            } else {
                // Pathological (revenue computes <= 0): keep it simple and balanced.
                $lines[] = ['account_code' => $revenueCode, 'branch_id' => $sale->branch_id, 'description' => 'Sales revenue ' . $sale->sale_no, 'debit' => 0, 'credit' => $grand];
            }

            return $this->journal->post(
                'sales_order_paid',
                $sale->id,
                $sale->sale_no,
                'Paid sale ' . $sale->sale_no,
                ($sale->sale_date ?? now())->toDateString(),
                $lines,
                $userId
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /** Dr Accounts Receivable / Cr Sales Revenue for the credit (unpaid) portion of a sale. */
    public function postCreditSale(SalesOrder $sale, ?int $userId = null): ?JournalEntry
    {
        try {
            if (! $sale->customer_id) {
                return null;
            }

            $amount = round((float) $sale->balance_due, 4);
            if ($amount <= 0) {
                return null;
            }

            $revenueCode = in_array($sale->order_type, ['dine_in', 'takeaway', 'delivery'], true) ? '4120' : '4110';

            $lines = [
                ['account_code' => '1300', 'branch_id' => $sale->branch_id, 'description' => 'Accounts Receivable', 'debit' => $amount, 'credit' => 0],
                ['account_code' => $revenueCode, 'branch_id' => $sale->branch_id, 'description' => 'Credit sale ' . $sale->sale_no, 'debit' => 0, 'credit' => $amount],
            ];

            return $this->journal->post(
                'sales_order_credit',
                $sale->id,
                $sale->sale_no,
                'Credit sale ' . $sale->sale_no,
                ($sale->sale_date ?? now())->toDateString(),
                $lines,
                $userId
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /**
     * Dr Inventory Asset / Cr Accounts Payable for a posted purchase bill.
     * NOT auto-hooked yet (demo bills aren't posted via a clean lifecycle) — kept
     * for FIN-7B / manual posting.
     */
    public function postPurchaseBill(PurchaseBill $bill, ?int $userId = null): ?JournalEntry
    {
        try {
            $amount = round((float) $bill->grand_total, 4);
            if ($amount <= 0) {
                return null;
            }

            $lines = [
                ['account_code' => '1400', 'branch_id' => $bill->branch_id, 'description' => 'Inventory Asset', 'debit' => $amount, 'credit' => 0],
                ['account_code' => '2100', 'branch_id' => $bill->branch_id, 'description' => 'Purchase bill ' . $bill->bill_no, 'debit' => 0, 'credit' => $amount],
            ];

            return $this->journal->post(
                'purchase_bill',
                $bill->id,
                $bill->bill_no,
                'Purchase bill ' . $bill->bill_no,
                $bill->bill_date?->toDateString() ?? now()->toDateString(),
                $lines,
                $userId
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /** Reverse the posted journal for an event (used by void flows). Idempotent; safe. */
    public function reverseForSource(string $sourceType, int $sourceId, string $reason, ?int $userId = null): ?JournalEntry
    {
        try {
            $entry = $this->journal->findPostedForSource($sourceType, $sourceId);
            if (! $entry) {
                return null;
            }

            return $this->journal->reverse($entry, $reason, $userId);
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    private function cashBankCoaId(int $cashBankAccountId): ?int
    {
        return CashBankAccount::whereKey($cashBankAccountId)->value('account_id');
    }
}
