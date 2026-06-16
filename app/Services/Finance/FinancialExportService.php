<?php

namespace App\Services\Finance;

use App\Models\Tenant\Account;
use App\Models\Tenant\JournalLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source for the two GL statements that don't have their own service
 * (Trial Balance + General Ledger line listing). Reused by the standalone report
 * controllers AND the FIN-12 export hub so the on-screen and exported figures match.
 */
class FinancialExportService
{
    /**
     * Trial balance as of a date.
     *
     * @return array{rows: array<int,array<string,mixed>>, total_debit: float, total_credit: float, difference: float}
     */
    public function trialBalance(string $asOf, ?int $branchId = null): array
    {
        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '<=', $asOf)
            ->when($branchId, fn ($q) => $q->where('journal_lines.branch_id', $branchId))
            ->groupBy('journal_lines.account_id')
            ->select(
                'journal_lines.account_id',
                DB::raw('COALESCE(SUM(journal_lines.debit), 0)  as total_debit'),
                DB::raw('COALESCE(SUM(journal_lines.credit), 0) as total_credit')
            )
            ->get()
            ->keyBy('account_id');

        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach (Account::orderBy('sort_order')->orderBy('code')->get() as $account) {
            $sum = $sums->get($account->id);
            if (! $sum) {
                continue;
            }

            $debit  = (float) $sum->total_debit;
            $credit = (float) $sum->total_credit;

            $debitBalance = 0.0;
            $creditBalance = 0.0;

            if ($account->normal_balance === 'debit') {
                $net = $debit - $credit;
                $net >= 0 ? $debitBalance = $net : $creditBalance = abs($net);
            } else {
                $net = $credit - $debit;
                $net >= 0 ? $creditBalance = $net : $debitBalance = abs($net);
            }

            if (round($debitBalance, 4) === 0.0 && round($creditBalance, 4) === 0.0) {
                continue;
            }

            $totalDebit  += $debitBalance;
            $totalCredit += $creditBalance;

            $rows[] = [
                'code'           => $account->code,
                'name'           => $account->name,
                'type'           => $account->type,
                'debit_balance'  => $debitBalance,
                'credit_balance' => $creditBalance,
            ];
        }

        return [
            'rows'         => $rows,
            'total_debit'  => round($totalDebit, 4),
            'total_credit' => round($totalCredit, 4),
            'difference'   => round($totalDebit - $totalCredit, 4),
        ];
    }

    /**
     * Posted journal lines for the General Ledger (optionally a single account).
     *
     * @return Collection<int, JournalLine>
     */
    public function generalLedgerLines(string $from, string $to, ?int $branchId = null, ?int $accountId = null, int $limit = 5000): Collection
    {
        return JournalLine::query()
            ->select('journal_lines.*')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->when($accountId, fn ($q) => $q->where('journal_lines.account_id', $accountId))
            ->when($branchId, fn ($q) => $q->where('journal_lines.branch_id', $branchId))
            ->whereDate('journal_entries.entry_date', '>=', $from)
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->with(['account', 'branch', 'journalEntry'])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->limit($limit)
            ->get();
    }
}
