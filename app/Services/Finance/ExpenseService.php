<?php

namespace App\Services\Finance;

use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\ExpenseVoucher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Operational expense lifecycle (FIN-4).
 *
 * Posting an expense voucher moves money OUT of a cash/bank account and writes an
 * operational cash_bank_account_transactions row. Voiding reverses it. Since FIN-7
 * it also posts a balanced GL journal (and a reversal on void).
 */
class ExpenseService
{
    public function __construct(private JournalPostingService $journalPosting) {}

    /** Recalculate voucher subtotal/tax/total from its lines. */
    public function recalcTotals(ExpenseVoucher $voucher): ExpenseVoucher
    {
        $voucher->loadMissing('lines');

        $subtotal = 0.0;
        $tax      = 0.0;

        foreach ($voucher->lines as $line) {
            $subtotal += (float) $line->amount;
            $tax      += (float) $line->tax_amount;
        }

        $voucher->forceFill([
            'subtotal'     => $subtotal,
            'tax_amount'   => $tax,
            'total_amount' => $subtotal + $tax,
        ])->save();

        return $voucher;
    }

    /**
     * Post a draft voucher: deduct from cash/bank, record an expense_payment txn.
     * Idempotent — never creates a second payment transaction for the same voucher.
     */
    public function post(ExpenseVoucher $voucher, ?int $userId = null): ExpenseVoucher
    {
        $voucher = DB::transaction(function () use ($voucher, $userId) {
            $voucher = ExpenseVoucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if (! $voucher->isDraft()) {
                throw new RuntimeException('Only draft vouchers can be posted.');
            }

            $this->recalcTotals($voucher);
            $voucher->refresh();

            $alreadyPaid = CashBankAccountTransaction::query()
                ->where('reference_type', 'expense_voucher')
                ->where('reference_id', $voucher->id)
                ->where('transaction_type', 'expense_payment')
                ->exists();

            if (! $alreadyPaid) {
                $cash = CashBankAccount::whereKey($voucher->cash_bank_account_id)->lockForUpdate()->firstOrFail();

                $newBalance = (float) $cash->current_balance - (float) $voucher->total_amount;

                CashBankAccountTransaction::create([
                    'cash_bank_account_id' => $cash->id,
                    'transaction_date'     => $voucher->payment_date?->toDateString() ?? $voucher->expense_date?->toDateString() ?? now()->toDateString(),
                    'direction'            => 'out',
                    'amount'               => $voucher->total_amount,
                    'balance_after'        => $newBalance,
                    'transaction_type'     => 'expense_payment',
                    'reference_type'       => 'expense_voucher',
                    'reference_id'         => $voucher->id,
                    'notes'                => 'Expense voucher ' . $voucher->voucher_no,
                    'created_by_user_id'   => $userId,
                ]);

                $cash->update(['current_balance' => $newBalance]);
            }

            $voucher->update([
                'status'            => 'posted',
                'posted_by_user_id' => $userId,
                'posted_at'         => now(),
            ]);

            return $voucher->fresh();
        });

        // GL journal (FIN-7) — idempotent + safe (never throws into this flow).
        $this->journalPosting->postExpenseVoucher($voucher, $userId);

        return $voucher;
    }

    /**
     * Void a posted voucher: refund the cash/bank account, record a reversal txn.
     * Idempotent — never creates a second reversal for the same voucher.
     */
    public function void(ExpenseVoucher $voucher, ?int $userId = null, ?string $reason = null): ExpenseVoucher
    {
        $voucher = DB::transaction(function () use ($voucher, $userId, $reason) {
            $voucher = ExpenseVoucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if (! $voucher->isPosted()) {
                throw new RuntimeException('Only posted vouchers can be voided.');
            }

            $alreadyReversed = CashBankAccountTransaction::query()
                ->where('reference_type', 'expense_voucher')
                ->where('reference_id', $voucher->id)
                ->where('transaction_type', 'expense_void_reversal')
                ->exists();

            if (! $alreadyReversed) {
                $cash = CashBankAccount::whereKey($voucher->cash_bank_account_id)->lockForUpdate()->firstOrFail();

                $newBalance = (float) $cash->current_balance + (float) $voucher->total_amount;

                CashBankAccountTransaction::create([
                    'cash_bank_account_id' => $cash->id,
                    'transaction_date'     => now()->toDateString(),
                    'direction'            => 'in',
                    'amount'               => $voucher->total_amount,
                    'balance_after'        => $newBalance,
                    'transaction_type'     => 'expense_void_reversal',
                    'reference_type'       => 'expense_voucher',
                    'reference_id'         => $voucher->id,
                    'notes'                => 'Void reversal for expense voucher ' . $voucher->voucher_no,
                    'created_by_user_id'   => $userId,
                ]);

                $cash->update(['current_balance' => $newBalance]);
            }

            $voucher->update([
                'status'            => 'void',
                'voided_by_user_id' => $userId,
                'voided_at'         => now(),
                'void_reason'       => $reason,
            ]);

            return $voucher->fresh();
        });

        // Reverse the GL journal (FIN-7) — idempotent + safe.
        $this->journalPosting->reverseForSource('expense_voucher', $voucher->id, $reason ?? 'Expense voucher voided', $userId);

        return $voucher;
    }
}
