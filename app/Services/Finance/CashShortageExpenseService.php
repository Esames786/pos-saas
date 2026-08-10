<?php

namespace App\Services\Finance;

use App\Models\Tenant\Account;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\ExpenseCategory;
use App\Models\Tenant\ExpenseVoucher;
use Illuminate\Support\Facades\DB;

/**
 * CASH-SHORTAGE-1 (2026-08-10): when a shift/branch closes SHORT (counted < expected), raise a
 * DRAFT expense voucher for the missing cash so the finance team settles it later.
 *
 * Deliberately DRAFT-only: nothing posts to the GL automatically — a shortage is an operational
 * fact until finance decides how to treat it (recovered from staff, written off, counting error).
 * Posting the draft through the existing Expenses screen is what books Dr 6930 / Cr cash.
 *
 * Idempotent per source: the voucher number is derived from the source (shift / daily closing),
 * so re-running a close never creates a second voucher for the same shortage.
 */
class CashShortageExpenseService
{
    public const CATEGORY_CODE = 'DAILY-CLOSING-SHORT';
    public const CATEGORY_NAME = 'Daily Closing — Short Cash';
    public const ACCOUNT_CODE = '6930';

    /**
     * @param  string  $sourceType  'shift' | 'daily_closing'
     * @return ExpenseVoucher|null  null when the drawer was not short (over/exact) or amount ~0
     */
    public function recordShortage(
        Branch $branch,
        string $businessDate,
        float $shortAmount,
        string $sourceType,
        int $sourceId,
        ?int $userId = null,
        ?string $context = null
    ): ?ExpenseVoucher {
        // Only genuine shortages: an over-count or an exact count raises nothing.
        if ($shortAmount <= 0.009) {
            return null;
        }

        $cashAccount = $this->resolveCashAccount($branch);
        if (! $cashAccount) {
            // No cash/bank account configured — never block the close over bookkeeping.
            return null;
        }

        $category = $this->ensureCategory();
        $voucherNo = 'EXP-SHORT-' . str_replace('-', '', $businessDate) . '-' . strtoupper(substr($sourceType, 0, 1)) . $sourceId;

        return DB::connection('tenant')->transaction(function () use (
            $branch, $businessDate, $shortAmount, $userId, $context, $cashAccount, $category, $voucherNo
        ) {
            $existing = ExpenseVoucher::where('voucher_no', $voucherNo)->lockForUpdate()->first();
            if ($existing) {
                return $existing;   // idempotent — one shortage, one draft
            }

            $voucher = ExpenseVoucher::create([
                'voucher_no' => $voucherNo,
                'branch_id' => $branch->id,
                'cash_bank_account_id' => $cashAccount->id,
                'expense_date' => $businessDate,
                'payee_name' => null,
                'status' => 'draft',
                'subtotal' => $shortAmount,
                'tax_amount' => 0,
                'total_amount' => $shortAmount,
                'notes' => 'Auto-created from cash closing. ' . ($context ?: '')
                    . ' Counted cash was short by ' . number_format($shortAmount, 2)
                    . '. Review and post (or void) once the difference is explained.',
                'created_by_user_id' => $userId,
            ]);

            $voucher->lines()->create([
                'expense_category_id' => $category->id,
                'account_id' => $category->account_id,
                'description' => 'Short cash at closing (' . $businessDate . ')',
                'amount' => $shortAmount,
                'tax_amount' => 0,
                'line_total' => $shortAmount,
                'sort_order' => 1,
            ]);

            return $voucher;
        });
    }

    /** The system expense category, created on first shortage (linked to CoA 6930). */
    public function ensureCategory(): ExpenseCategory
    {
        $category = ExpenseCategory::where('code', self::CATEGORY_CODE)->first();
        if ($category) {
            return $category;
        }

        $accountId = Account::where('code', self::ACCOUNT_CODE)->value('id')
            ?: Account::where('code', '6800')->value('id');   // Miscellaneous Expense fallback

        return ExpenseCategory::create([
            'code' => self::CATEGORY_CODE,
            'name' => self::CATEGORY_NAME,
            'account_id' => $accountId,
            'description' => 'Cash missing at shift / branch closing. Auto-raised as a draft expense for finance to settle.',
            'is_system' => true,
            'is_active' => true,
            'sort_order' => 900,
        ]);
    }

    /** Branch's default cash drawer account, else any active cash account, else any active account. */
    private function resolveCashAccount(Branch $branch): ?CashBankAccount
    {
        return CashBankAccount::where('is_active', true)
            ->where(function ($q) use ($branch) {
                $q->where('branch_id', $branch->id)->orWhereNull('branch_id');
            })
            ->orderByRaw('CASE WHEN account_type = ? THEN 0 ELSE 1 END', ['cash'])
            ->orderByDesc('is_default')
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
            ->first();
    }
}
