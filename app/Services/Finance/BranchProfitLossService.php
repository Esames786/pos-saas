<?php

namespace App\Services\Finance;

use App\Models\Tenant\Branch;
use App\Models\Tenant\JournalLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Branch-wise Profit & Loss from posted GL journal lines (FIN-11).
 *
 * Same categorization as ProfitLossService, but grouped by journal_lines.branch_id.
 * Lines with a NULL branch are reported under "Unassigned / No Branch". The grand
 * total across all branches reconciles to the overall ProfitLossService statement.
 */
class BranchProfitLossService
{
    public function statement(array $filters = []): array
    {
        $dateFrom = ! empty($filters['date_from'])
            ? Carbon::parse($filters['date_from'])->toDateString()
            : now()->startOfMonth()->toDateString();
        $dateTo = ! empty($filters['date_to'])
            ? Carbon::parse($filters['date_to'])->toDateString()
            : now()->toDateString();
        $branchIds = $this->normalizeBranchIds($filters);

        // Sum debit/credit per (branch, account) for income+expense accounts in period.
        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '>=', $dateFrom)
            ->whereDate('journal_entries.entry_date', '<=', $dateTo)
            ->whereIn('accounts.type', ['income', 'expense'])
            ->when($branchIds, fn ($q) => $q->whereIn('journal_lines.branch_id', $branchIds))
            ->groupBy('journal_lines.branch_id', 'accounts.type', 'accounts.normal_balance', 'accounts.code')
            ->select(
                'journal_lines.branch_id',
                'accounts.type',
                'accounts.normal_balance',
                'accounts.code',
                DB::raw('COALESCE(SUM(journal_lines.debit), 0)  as total_debit'),
                DB::raw('COALESCE(SUM(journal_lines.credit), 0) as total_credit')
            )
            ->get();

        $branchNames = Branch::orderBy('name')->pluck('name', 'id');

        $buckets = []; // branch_id (or '_null') => running totals

        foreach ($sums as $s) {
            $key = $s->branch_id === null ? '_null' : (int) $s->branch_id;

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'branch_id'           => $s->branch_id,
                    'branch_name'         => $s->branch_id === null
                        ? 'Unassigned / No Branch'
                        : ($branchNames[$s->branch_id] ?? ('Branch #' . $s->branch_id)),
                    'gross_revenue'       => 0.0,
                    'discounts'           => 0.0,
                    'cogs'                => 0.0,
                    'operating_expenses'  => 0.0,
                ];
            }

            $debit  = (float) $s->total_debit;
            $credit = (float) $s->total_credit;
            $isCogs = str_starts_with((string) $s->code, '5');

            if ($s->type === 'income') {
                if ($s->normal_balance === 'debit') {
                    $buckets[$key]['discounts'] += ($debit - $credit);
                } else {
                    $buckets[$key]['gross_revenue'] += ($credit - $debit);
                }
            } elseif ($s->type === 'expense') {
                if ($isCogs) {
                    $buckets[$key]['cogs'] += ($debit - $credit);
                } else {
                    $buckets[$key]['operating_expenses'] += ($debit - $credit);
                }
            }
        }

        $rows = [];
        foreach ($buckets as $b) {
            $row = $this->finalizeRow($b);
            // Skip fully-empty branches (no activity at all).
            if ($row['gross_revenue'] == 0.0 && $row['cogs'] == 0.0 && $row['operating_expenses'] == 0.0 && $row['discounts'] == 0.0) {
                continue;
            }
            $rows[] = $row;
        }

        // Sort: real branches first (by net profit desc), Unassigned last.
        usort($rows, function ($a, $b) {
            if (($a['branch_id'] === null) !== ($b['branch_id'] === null)) {
                return $a['branch_id'] === null ? 1 : -1;
            }
            return $b['net_profit'] <=> $a['net_profit'];
        });

        $totals = $this->buildTotals($rows);

        return [
            'period' => ['from' => $dateFrom, 'to' => $dateTo],
            'rows'   => $rows,
            'totals' => $totals,
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

    private function finalizeRow(array $b): array
    {
        $grossRevenue = round($b['gross_revenue'], 4);
        $discounts    = round($b['discounts'], 4);
        $netRevenue   = round($grossRevenue - $discounts, 4);
        $cogs         = round($b['cogs'], 4);
        $grossProfit  = round($netRevenue - $cogs, 4);
        $opex         = round($b['operating_expenses'], 4);
        $netProfit    = round($grossProfit - $opex, 4);

        return [
            'branch_id'            => $b['branch_id'],
            'branch_name'          => $b['branch_name'],
            'gross_revenue'        => $grossRevenue,
            'discounts'            => $discounts,
            'net_revenue'          => $netRevenue,
            'cogs'                 => $cogs,
            'gross_profit'         => $grossProfit,
            'operating_expenses'   => $opex,
            'net_profit'           => $netProfit,
            'gross_margin_percent' => $netRevenue > 0 ? round($grossProfit / $netRevenue * 100, 2) : 0.0,
            'net_margin_percent'   => $netRevenue > 0 ? round($netProfit / $netRevenue * 100, 2) : 0.0,
        ];
    }

    private function buildTotals(array $rows): array
    {
        $sum = fn (string $k) => round(array_sum(array_column($rows, $k)), 4);

        $grossRevenue = $sum('gross_revenue');
        $netRevenue   = $sum('net_revenue');
        $grossProfit  = $sum('gross_profit');
        $netProfit    = $sum('net_profit');

        return [
            'gross_revenue'        => $grossRevenue,
            'discounts'            => $sum('discounts'),
            'net_revenue'          => $netRevenue,
            'cogs'                 => $sum('cogs'),
            'gross_profit'         => $grossProfit,
            'operating_expenses'   => $sum('operating_expenses'),
            'net_profit'           => $netProfit,
            'gross_margin_percent' => $netRevenue > 0 ? round($grossProfit / $netRevenue * 100, 2) : 0.0,
            'net_margin_percent'   => $netRevenue > 0 ? round($netProfit / $netRevenue * 100, 2) : 0.0,
        ];
    }
}
