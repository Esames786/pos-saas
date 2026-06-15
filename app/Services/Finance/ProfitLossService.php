<?php

namespace App\Services\Finance;

use App\Models\Tenant\Account;
use App\Models\Tenant\JournalLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Profit & Loss statement built ONLY from posted GL journal lines (FIN-9).
 *
 * Categories (by account type + code):
 *   - Revenue        : type=income, normal_balance=credit  → credit - debit
 *   - Contra revenue : type=income, normal_balance=debit   → debit - credit  (e.g. 4200 Sales Discounts)
 *   - COGS           : type=expense, code starts with '5'  → debit - credit
 *   - Operating exp. : type=expense, code NOT starting '5' → debit - credit
 *
 * Sales returns already debit revenue accounts, so revenue naturally nets down.
 */
class ProfitLossService
{
    public function statement(array $filters = []): array
    {
        $dateFrom = ! empty($filters['date_from'])
            ? Carbon::parse($filters['date_from'])->toDateString()
            : now()->startOfMonth()->toDateString();
        $dateTo = ! empty($filters['date_to'])
            ? Carbon::parse($filters['date_to'])->toDateString()
            : now()->toDateString();
        $branchId = $filters['branch_id'] ?? null;

        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '>=', $dateFrom)
            ->whereDate('journal_entries.entry_date', '<=', $dateTo)
            ->when($branchId, fn ($q) => $q->where('journal_lines.branch_id', $branchId))
            ->groupBy('journal_lines.account_id')
            ->select(
                'journal_lines.account_id',
                DB::raw('COALESCE(SUM(journal_lines.debit), 0)  as total_debit'),
                DB::raw('COALESCE(SUM(journal_lines.credit), 0) as total_credit')
            )
            ->get()
            ->keyBy('account_id');

        $accounts = Account::orderBy('sort_order')->orderBy('code')->get();

        $revenueRows  = [];
        $discountRows = [];
        $cogsRows     = [];
        $expenseRows  = [];

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

            $isCogs = str_starts_with((string) $account->code, '5');

            if ($account->type === 'income') {
                if ($account->normal_balance === 'debit') {
                    // contra-revenue (e.g. Sales Discounts)
                    $amount = round($debit - $credit, 4);
                    if ($amount != 0.0) {
                        $discountRows[] = $this->row($account, $amount);
                    }
                } else {
                    $amount = round($credit - $debit, 4);
                    if ($amount != 0.0) {
                        $revenueRows[] = $this->row($account, $amount);
                    }
                }
            } elseif ($account->type === 'expense') {
                $amount = round($debit - $credit, 4);
                if ($amount == 0.0) {
                    continue;
                }
                if ($isCogs) {
                    $cogsRows[] = $this->row($account, $amount);
                } else {
                    $expenseRows[] = $this->row($account, $amount);
                }
            }
        }

        $grossRevenue   = round(array_sum(array_column($revenueRows, 'amount')), 4);
        $totalDiscounts = round(array_sum(array_column($discountRows, 'amount')), 4);
        $netRevenue     = round($grossRevenue - $totalDiscounts, 4);
        $totalCogs      = round(array_sum(array_column($cogsRows, 'amount')), 4);
        $grossProfit    = round($netRevenue - $totalCogs, 4);
        $totalExpenses  = round(array_sum(array_column($expenseRows, 'amount')), 4);
        $netProfit      = round($grossProfit - $totalExpenses, 4);

        return [
            'period'                => ['from' => $dateFrom, 'to' => $dateTo],
            'revenue_rows'          => $revenueRows,
            'gross_revenue'         => $grossRevenue,
            'discount_rows'         => $discountRows,
            'total_discounts'       => $totalDiscounts,
            'net_revenue'           => $netRevenue,
            'cogs_rows'             => $cogsRows,
            'total_cogs'            => $totalCogs,
            'gross_profit'          => $grossProfit,
            'expense_rows'          => $expenseRows,
            'total_expenses'        => $totalExpenses,
            'net_profit'            => $netProfit,
            'gross_margin_percent'  => $netRevenue > 0 ? round($grossProfit / $netRevenue * 100, 2) : 0.0,
            'net_margin_percent'    => $netRevenue > 0 ? round($netProfit / $netRevenue * 100, 2) : 0.0,
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
}
