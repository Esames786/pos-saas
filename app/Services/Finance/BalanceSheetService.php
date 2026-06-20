<?php

namespace App\Services\Finance;

use App\Models\Tenant\Account;
use App\Models\Tenant\JournalLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Balance Sheet built ONLY from posted GL journal lines as of a date (FIN-10).
 *
 * Balances per account normal sign:
 *   asset     → debit - credit
 *   liability → credit - debit
 *   equity    → credit - debit
 *
 * Income/expense accounts are NOT listed as rows; their net (income - expenses,
 * where expenses = COGS 5xxx + operating 6xxx) folds into a report-only
 * "Current Earnings" equity line — because year-end closing isn't posted yet.
 * So: Assets == Liabilities + Equity + Current Earnings.
 */
class BalanceSheetService
{
    public function statement(array $filters = []): array
    {
        $asOf = ! empty($filters['as_of_date'])
            ? Carbon::parse($filters['as_of_date'])->toDateString()
            : now()->toDateString();
        $branchIds = $this->normalizeBranchIds($filters);

        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '<=', $asOf)
            ->when($branchIds, fn ($q) => $q->whereIn('journal_lines.branch_id', $branchIds))
            ->groupBy('journal_lines.account_id')
            ->select(
                'journal_lines.account_id',
                DB::raw('COALESCE(SUM(journal_lines.debit), 0)  as total_debit'),
                DB::raw('COALESCE(SUM(journal_lines.credit), 0) as total_credit')
            )
            ->get()
            ->keyBy('account_id');

        $accounts = Account::orderBy('sort_order')->orderBy('code')->get();

        $assetRows = [];
        $liabilityRows = [];
        $equityRows = [];
        $totalIncome = 0.0;
        $totalExpense = 0.0;

        foreach ($accounts as $account) {
            $sum = $sums->get($account->id);
            if (! $sum) {
                continue;
            }

            $debit  = (float) $sum->total_debit;
            $credit = (float) $sum->total_credit;
            if ($debit === 0.0 && $credit === 0.0) {
                continue;
            }

            switch ($account->type) {
                case 'asset':
                    $amount = round($debit - $credit, 4);
                    if ($amount != 0.0) {
                        $assetRows[] = $this->row($account, $amount);
                    }
                    break;

                case 'liability':
                    $amount = round($credit - $debit, 4);
                    if ($amount != 0.0) {
                        $liabilityRows[] = $this->row($account, $amount);
                    }
                    break;

                case 'equity':
                    $amount = round($credit - $debit, 4);
                    if ($amount != 0.0) {
                        $equityRows[] = $this->row($account, $amount);
                    }
                    break;

                case 'income':
                    // income natural balance is credit
                    $totalIncome += ($credit - $debit);
                    break;

                case 'expense':
                    // expenses (COGS 5xxx + operating 6xxx) natural balance is debit
                    $totalExpense += ($debit - $credit);
                    break;
            }
        }

        $currentEarnings = round($totalIncome - $totalExpense, 4);

        $totalAssets      = round(array_sum(array_column($assetRows, 'amount')), 4);
        $totalLiabilities = round(array_sum(array_column($liabilityRows, 'amount')), 4);
        $totalEquity      = round(array_sum(array_column($equityRows, 'amount')), 4);
        $totalLiabEquity  = round($totalLiabilities + $totalEquity + $currentEarnings, 4);
        $difference       = round($totalAssets - $totalLiabEquity, 4);

        return [
            'as_of_date'               => $asOf,
            'asset_rows'               => $assetRows,
            'liability_rows'           => $liabilityRows,
            'equity_rows'              => $equityRows,
            'current_earnings'         => $currentEarnings,
            'total_assets'             => $totalAssets,
            'total_liabilities'        => $totalLiabilities,
            'total_equity'             => $totalEquity,
            'total_liabilities_equity' => $totalLiabEquity,
            'difference'               => $difference,
            'is_balanced'              => abs($difference) <= 0.01,
        ];
    }

    private function row(Account $account, float $amount): array
    {
        return [
            'account_id' => $account->id,
            'code'       => $account->code,
            'name'       => $account->name,
            'amount'     => $amount,
        ];
    }

    private function normalizeBranchIds(array $filters): ?array
    {
        if (! empty($filters['branch_ids']) && is_array($filters['branch_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['branch_ids'])));
            return $ids ?: null;
        }
        if (! empty($filters['branch_id'])) {
            return [(int) $filters['branch_id']];
        }
        return null;
    }
}
