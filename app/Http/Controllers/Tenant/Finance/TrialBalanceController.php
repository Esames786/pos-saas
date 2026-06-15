<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Account;
use App\Models\Tenant\Branch;
use App\Models\Tenant\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrialBalanceController extends Controller
{
    public function index(Request $request)
    {
        $asOf     = $request->input('as_of_date', today()->format('Y-m-d'));
        $branchId = $request->input('branch_id');

        // Sum posted journal-line debits/credits per account, up to as-of date.
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

        $accounts = Account::orderBy('sort_order')->orderBy('code')->get();

        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($accounts as $account) {
            $sum = $sums->get($account->id);
            $debit  = (float) ($sum->total_debit ?? 0);
            $credit = (float) ($sum->total_credit ?? 0);

            if ($debit === 0.0 && $credit === 0.0) {
                continue; // only show accounts with activity
            }

            $debitBalance = 0.0;
            $creditBalance = 0.0;

            if ($account->normal_balance === 'debit') {
                $net = $debit - $credit;
                $net >= 0 ? $debitBalance = $net : $creditBalance = abs($net);
            } else {
                $net = $credit - $debit;
                $net >= 0 ? $creditBalance = $net : $debitBalance = abs($net);
            }

            // Skip accounts whose net balance is zero (e.g. fully reversed).
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

        return view('tenant.finance.trial-balance.index', [
            'rows'        => $rows,
            'totalDebit'  => $totalDebit,
            'totalCredit' => $totalCredit,
            'difference'  => round($totalDebit - $totalCredit, 4),
            'asOf'        => $asOf,
            'branches'    => Branch::orderBy('name')->get(['id', 'name']),
            'filters'     => $request->only(['as_of_date', 'branch_id']),
        ]);
    }
}
