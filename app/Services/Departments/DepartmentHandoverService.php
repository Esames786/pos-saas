<?php

namespace App\Services\Departments;

use App\Models\Tenant\Account;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\Department;
use App\Models\Tenant\DepartmentHandover;
use App\Services\Finance\JournalService;
use App\Services\Reports\DepartmentReportService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THIRD-PARTY-DEPARTMENT-HANDOVER-1 — hand a third-party department's sales to its owner.
 *
 * Money-only. It NEVER touches stock, COGS, inventory or the sale itself. Two steps:
 *   1) postHandover — reclassify the department's sales for a period out of our income into the
 *      owner's payable:   Dr 4210 Third-Party Department Handover  /  Cr 24xx Payable — <owner>.
 *   2) postPayout — settle by cash/bank:   Dr 24xx Payable — <owner>  /  Cr 1110/1210 Cash/Bank,
 *      and move the operational cash/bank balance (money out).
 *
 * Both journal entries are idempotent per (source_type, handover id) and fully reversible.
 */
class DepartmentHandoverService
{
    public const PARENT_PAYABLE_CODE = '2400';
    public const HANDOVER_CONTRA_CODE = '4210';

    public function __construct(
        private readonly JournalService $journal,
        private readonly DepartmentReportService $report,
    ) {
    }

    /** The department's net sales for the period (matches the Department Sales report figure). */
    public function periodSalesTotal(Department $dept, string $from, string $to): float
    {
        $result = $this->report->sales([
            'branch_id'     => $dept->branch_id,
            'department_id' => $dept->id,
            'date_from'     => $from,
            'date_to'       => $to,
        ]);

        foreach ($result['rows'] as $row) {
            if ((int) ($row['department_id'] ?? 0) === (int) $dept->id) {
                return round((float) $row['net'], 4);
            }
        }

        return 0.0;
    }

    /** A not-yet-reversed handover already covering this exact department + period, if any. */
    public function existingHandover(Department $dept, string $from, string $to): ?DepartmentHandover
    {
        return DepartmentHandover::query()
            ->where('department_id', $dept->id)
            ->whereDate('period_from', $from)
            ->whereDate('period_to', $to)
            ->where('status', '!=', DepartmentHandover::STATUS_REVERSED)
            ->first();
    }

    /**
     * Step 1 — reclassify the department's period sales into the owner's payable.
     * Dr 4210 Third-Party Department Handover / Cr 24xx Payable — <owner>.
     */
    public function postHandover(Department $dept, string $from, string $to, ?int $userId = null): DepartmentHandover
    {
        if (! $dept->is_third_party) {
            throw new RuntimeException('Only a third-party department can be handed over.');
        }
        if ($this->existingHandover($dept, $from, $to)) {
            throw new RuntimeException('This department already has a handover for that period. Reverse it first.');
        }

        $amount = $this->periodSalesTotal($dept, $from, $to);
        if ($amount <= 0) {
            throw new RuntimeException('There are no sales to hand over for this department in the selected period.');
        }

        return DB::connection('tenant')->transaction(function () use ($dept, $from, $to, $amount, $userId) {
            $payable = $this->ensurePayableAccount($dept);
            $contra  = $this->accountByCode(self::HANDOVER_CONTRA_CODE);

            $handover = DepartmentHandover::create([
                'branch_id'          => $dept->branch_id,
                'department_id'      => $dept->id,
                'period_from'        => $from,
                'period_to'          => $to,
                'handover_total'     => $amount,
                'payable_account_id' => $payable->id,
                'status'             => DepartmentHandover::STATUS_PENDING_PAYOUT,
                'created_by_user_id' => $userId,
            ]);

            $entry = $this->journal->post(
                sourceType:  'dept_handover',
                sourceId:    $handover->id,
                sourceNo:    'DH-' . $handover->id,
                description: 'Handover of ' . $dept->name . ' sales (' . $from . ' → ' . $to . ') to ' . ($dept->owner_name ?: 'owner'),
                entryDate:   $to,
                lines: [
                    ['account_id' => $contra->id,  'branch_id' => $dept->branch_id, 'description' => $dept->name . ' sales handed over', 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $payable->id, 'branch_id' => $dept->branch_id, 'description' => 'Owed to ' . ($dept->owner_name ?: $dept->name), 'debit' => 0, 'credit' => $amount],
                ],
                userId: $userId,
            );

            $handover->update(['reclass_journal_entry_id' => $entry->id]);

            return $handover->fresh();
        });
    }

    /**
     * Step 2 — pay the owner from a cash/bank account.
     * Dr 24xx Payable — <owner> / Cr 1110/1210 Cash/Bank, and move the operational balance out.
     */
    public function postPayout(DepartmentHandover $handover, int $cashBankAccountId, ?int $userId = null): DepartmentHandover
    {
        if ($handover->status !== DepartmentHandover::STATUS_PENDING_PAYOUT) {
            throw new RuntimeException('Only a pending handover can be paid out.');
        }

        return DB::connection('tenant')->transaction(function () use ($handover, $cashBankAccountId, $userId) {
            $cash = CashBankAccount::whereKey($cashBankAccountId)->lockForUpdate()->first();
            if (! $cash || ! $cash->account_id) {
                throw new RuntimeException('The selected cash/bank account is not linked to a ledger account.');
            }

            $amount = round((float) $handover->handover_total, 4);

            $entry = $this->journal->post(
                sourceType:  'dept_handover_payout',
                sourceId:    $handover->id,
                sourceNo:    'DHP-' . $handover->id,
                description: 'Payout to ' . ($handover->department->owner_name ?: $handover->department->name) . ' for handover DH-' . $handover->id,
                entryDate:   now()->toDateString(),
                lines: [
                    ['account_id' => $handover->payable_account_id, 'branch_id' => $handover->branch_id, 'description' => 'Settle handover DH-' . $handover->id, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $cash->account_id,             'branch_id' => $handover->branch_id, 'description' => 'Paid ' . ($handover->department->owner_name ?: 'owner') . ' from ' . $cash->name, 'debit' => 0, 'credit' => $amount],
                ],
                userId: $userId,
            );

            // Operational cash/bank balance: money OUT.
            $newBalance = (float) $cash->current_balance - $amount;
            CashBankAccountTransaction::create([
                'cash_bank_account_id' => $cash->id,
                'transaction_date'     => now()->toDateString(),
                'direction'            => 'out',
                'amount'               => $amount,
                'balance_after'        => $newBalance,
                'transaction_type'     => 'dept_handover_payout',
                'reference_type'       => 'dept_handover_payout',
                'reference_id'         => $handover->id,
                'notes'                => 'Handover payout DH-' . $handover->id,
                'created_by_user_id'   => $userId,
            ]);
            $cash->update(['current_balance' => $newBalance]);

            $handover->update([
                'status'                      => DepartmentHandover::STATUS_SETTLED,
                'payout_journal_entry_id'     => $entry->id,
                'payout_cash_bank_account_id' => $cash->id,
                'paid_at'                     => now(),
            ]);

            return $handover->fresh();
        });
    }

    /** Reverse a handover (and its payout, if settled), restoring income and cash/bank. */
    public function reverse(DepartmentHandover $handover, string $reason, ?int $userId = null): DepartmentHandover
    {
        if ($handover->status === DepartmentHandover::STATUS_REVERSED) {
            throw new RuntimeException('This handover is already reversed.');
        }

        return DB::connection('tenant')->transaction(function () use ($handover, $reason, $userId) {
            // Reverse the payout first (restores cash/bank), then the reclass (restores income).
            if ($handover->payout_journal_entry_id && $handover->payoutEntry) {
                $this->journal->reverse($handover->payoutEntry, $reason, $userId);
                $this->reversePayoutCashBank($handover, $userId);
            }
            if ($handover->reclass_journal_entry_id && $handover->reclassEntry) {
                $this->journal->reverse($handover->reclassEntry, $reason, $userId);
            }

            $handover->update([
                'status'              => DepartmentHandover::STATUS_REVERSED,
                'reversed_at'         => now(),
                'reversed_by_user_id' => $userId,
                'reversal_reason'     => $reason,
            ]);

            return $handover->fresh();
        });
    }

    private function reversePayoutCashBank(DepartmentHandover $handover, ?int $userId): void
    {
        if (! $handover->payout_cash_bank_account_id) {
            return;
        }
        $cash = CashBankAccount::whereKey($handover->payout_cash_bank_account_id)->lockForUpdate()->first();
        if (! $cash) {
            return;
        }
        $amount = round((float) $handover->handover_total, 4);
        $newBalance = (float) $cash->current_balance + $amount; // money back IN

        CashBankAccountTransaction::create([
            'cash_bank_account_id' => $cash->id,
            'transaction_date'     => now()->toDateString(),
            'direction'            => 'in',
            'amount'               => $amount,
            'balance_after'        => $newBalance,
            'transaction_type'     => 'dept_handover_payout_reversal',
            'reference_type'       => 'dept_handover_payout_reversal',
            'reference_id'         => $handover->id,
            'notes'                => 'Reversal of handover payout DH-' . $handover->id,
            'created_by_user_id'   => $userId,
        ]);
        $cash->update(['current_balance' => $newBalance]);
    }

    /** The per-owner payable sub-account (child of 2400), created on first handover. */
    public function ensurePayableAccount(Department $dept): Account
    {
        if ($dept->payable_account_id) {
            $existing = Account::find($dept->payable_account_id);
            if ($existing) {
                return $existing;
            }
        }

        $parent = $this->ensureParentPayable();

        // Allocate the next free child code (2401, 2402, …).
        $maxChild = (int) Account::where('parent_id', $parent->id)->max('code');
        $nextCode = (string) max($maxChild + 1, (int) self::PARENT_PAYABLE_CODE + 1);

        $account = Account::create([
            'code'           => $nextCode,
            'name'           => 'Payable — ' . $dept->name,
            'type'           => 'liability',
            'normal_balance' => 'credit',
            'parent_id'      => $parent->id,
            'is_system'      => false,
            'is_active'      => true,
            'sort_order'     => 136,
        ]);

        $dept->update(['payable_account_id' => $account->id]);

        return $account;
    }

    private function ensureParentPayable(): Account
    {
        return Account::firstOrCreate(
            ['code' => self::PARENT_PAYABLE_CODE],
            [
                'name'           => 'Payable to Third-Party Departments',
                'type'           => 'liability',
                'normal_balance' => 'credit',
                'parent_id'      => optional(Account::where('code', '2000')->first())->id,
                'is_system'      => true,
                'is_active'      => true,
                'sort_order'     => 135,
            ]
        );
    }

    private function accountByCode(string $code): Account
    {
        $account = Account::where('code', $code)->first();
        if (! $account) {
            throw new RuntimeException("Required account {$code} is missing from the chart of accounts. Re-run the chart-of-accounts seeder.");
        }

        return $account;
    }
}
