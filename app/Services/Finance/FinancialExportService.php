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
    public function trialBalance(string $asOf, array|int|null $branchIds = null): array
    {
        $branchIds = $this->normalizeBranchIds($branchIds);

        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '<=', $asOf)
            // BUG-053 FIX: include null-branch lines when a branch filter is applied.
            ->when($branchIds, fn ($q) => $q->where(
                fn ($q2) => $q2->whereIn('journal_lines.branch_id', $branchIds)
                               ->orWhereNull('journal_lines.branch_id')
            ))
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
     * TRIAL-BALANCE-PERIOD-1 — the trial balance for a period: per account Opening, the period's
     * Debit and Credit, and the closing Balance.
     *
     * The "as of" snapshot above drops an account whose net is zero, so a supplier billed and paid
     * in full vanished from 2100 even though 19,650 had passed through it — Kashif Kitchen's owner
     * could not see the payments at all. Here a row stays while ANY of its four figures is non-zero.
     *
     * Same base as trialBalance(): posted lines, by journal_entries.entry_date, BUG-053 branch rule.
     * Opening = everything before $from; Debit/Credit = $from..$to; Balance = Opening + Debit − Credit,
     * which is exactly trialBalance($to) — a test holds the two together. Sides follow the same rule
     * as trialBalance(): a positive debit-minus-credit is Dr, a negative one Cr, whatever the
     * account's normal balance. Income and expense openings carry forward: no year-end close is
     * posted in this system.
     *
     * One grouped query, not one per account.
     *
     * @return array{rows: list<array<string,mixed>>, totals: array<string,float>, difference: float}
     */
    public function trialBalancePeriod(string $from, string $to, array|int|null $branchIds = null): array
    {
        $branchIds = $this->normalizeBranchIds($branchIds);

        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->when($branchIds, fn ($q) => $q->where(
                fn ($q2) => $q2->whereIn('journal_lines.branch_id', $branchIds)
                               ->orWhereNull('journal_lines.branch_id')
            ))
            ->groupBy('journal_lines.account_id')
            ->selectRaw(
                'journal_lines.account_id,
                 COALESCE(SUM(CASE WHEN DATE(journal_entries.entry_date) <  ? THEN journal_lines.debit  ELSE 0 END), 0) AS open_debit,
                 COALESCE(SUM(CASE WHEN DATE(journal_entries.entry_date) <  ? THEN journal_lines.credit ELSE 0 END), 0) AS open_credit,
                 COALESCE(SUM(CASE WHEN DATE(journal_entries.entry_date) >= ? THEN journal_lines.debit  ELSE 0 END), 0) AS period_debit,
                 COALESCE(SUM(CASE WHEN DATE(journal_entries.entry_date) >= ? THEN journal_lines.credit ELSE 0 END), 0) AS period_credit',
                [$from, $from, $from, $from]
            )
            ->get()
            ->keyBy('account_id');

        $split = fn (float $net) => [max($net, 0.0), max(-$net, 0.0)];
        $totals = array_fill_keys([
            'opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'closing_debit', 'closing_credit',
        ], 0.0);
        $rows = [];

        // Account-code order: on-screen accounts get sort_order 0, so sort_order put 6810 above 1110.
        foreach (Account::orderBy('code')->get() as $account) {
            $sum = $sums->get($account->id);
            if (! $sum) {
                continue;
            }

            $openNet      = (float) $sum->open_debit - (float) $sum->open_credit;
            $periodDebit  = (float) $sum->period_debit;
            $periodCredit = (float) $sum->period_credit;
            $closeNet     = $openNet + $periodDebit - $periodCredit;

            if (round($openNet, 4) === 0.0 && round($periodDebit, 4) === 0.0
                && round($periodCredit, 4) === 0.0 && round($closeNet, 4) === 0.0) {
                continue;
            }

            [$openingDebit, $openingCredit] = $split($openNet);
            [$closingDebit, $closingCredit] = $split($closeNet);

            $row = [
                'account_id'     => $account->id,
                'code'           => $account->code,
                'name'           => $account->name,
                'type'           => $account->type,
                'opening_debit'  => $openingDebit,
                'opening_credit' => $openingCredit,
                'period_debit'   => $periodDebit,
                'period_credit'  => $periodCredit,
                'closing_debit'  => $closingDebit,
                'closing_credit' => $closingCredit,
            ];
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $row[$key];
            }
            $rows[] = $row;
        }

        $totals = array_map(fn ($v) => round($v, 4), $totals);

        return [
            'rows'       => $rows,
            'totals'     => $totals,
            'difference' => round($totals['closing_debit'] - $totals['closing_credit'], 4),
        ];
    }

    /** The first posted entry date — where a legacy "as of" link's period starts. */
    public function firstPostingDate(): ?string
    {
        $first = DB::connection('tenant')->table('journal_entries')->where('status', 'posted')->min('entry_date');

        return $first ? substr((string) $first, 0, 10) : null;
    }

    /**
     * Posted journal lines for the General Ledger (optionally a single account).
     *
     * @return Collection<int, JournalLine>
     */
    /**
     * JOURNAL-SOURCE-MULTI-1 — $sourceTypes is appended LAST and defaults to empty, so the two
     * other callers (FinancialExportController, GeneralLedgerController) keep their exact
     * behaviour; they pass positionally and never reach this argument.
     */
    public function generalLedgerLines(string $from, string $to, array|int|null $branchIds = null, ?int $accountId = null, int $limit = 5000, array $sourceTypes = [], string $eventNo = ''): Collection
    {
        // JOURNAL-EVENT-REF-1: $eventNo is appended LAST and defaults to empty, so the callers
        // that pass nothing behave exactly as before. This one function feeds the GL screen, the
        // GL CSV and the JE lines CSV, so filtering here covers all three at once.
        $eventRefs = null;
        $eventNo = trim($eventNo);
        if ($eventNo !== '') {
            $resolved = app(JournalEventResolver::class)->entryRefsForEvent($eventNo);
            // Unknown event, or an event with no journals: match NOTHING. Widening to every line
            // would hand back a full ledger that looks like the answer to the question asked.
            $eventRefs = $resolved === null ? [] : $resolved['refs'];
        }

        $branchIds = $this->normalizeBranchIds($branchIds);

        $query = JournalLine::query()
            ->select('journal_lines.*')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->when($accountId, fn ($q) => $q->where('journal_lines.account_id', $accountId))
            ->when($branchIds, fn ($q) => $q->whereIn('journal_lines.branch_id', $branchIds))
            ->when($sourceTypes, fn ($q) => $q->whereIn('journal_entries.source_type', $sourceTypes))
            ->when($eventRefs !== null, function ($q) use ($eventRefs) {
                app(JournalEventResolver::class)->applyEventFilter($q, $eventRefs);
            })
            ->whereDate('journal_entries.entry_date', '>=', $from)
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->with(['account', 'branch', 'journalEntry'])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id');

        // BUG-055 FIX: count total before limiting so callers can show a truncation warning.
        $totalCount  = (clone $query)->count();
        $results     = $query->limit($limit)->get();

        // Attach truncation metadata as a property on the collection so the
        // view/controller can display a warning without re-running the query.
        $results->truncated      = $totalCount > $limit;
        $results->total_count    = $totalCount;
        $results->returned_count = $results->count();

        return $results;
    }

    private function normalizeBranchIds(array|int|null $branchIds): ?array
    {
        if (is_int($branchIds)) {
            return [$branchIds];
        }
        if (is_array($branchIds) && count($branchIds) > 0) {
            return array_values(array_filter(array_map('intval', $branchIds)));
        }
        return null;
    }
}
