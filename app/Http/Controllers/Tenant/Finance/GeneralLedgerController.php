<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Account;
use App\Models\Tenant\Branch;
use App\Models\Tenant\JournalLine;
use Illuminate\Http\Request;

class GeneralLedgerController extends Controller
{
    public function index(Request $request)
    {
        $accountId = $request->input('account_id');
        $branchId  = $request->input('branch_id');
        $dateFrom  = $request->input('date_from');
        $dateTo    = $request->input('date_to');

        $account = $accountId ? Account::find($accountId) : null;

        $lines = JournalLine::query()
            ->select('journal_lines.*')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->when($accountId, fn ($q) => $q->where('journal_lines.account_id', $accountId))
            ->when($branchId, fn ($q) => $q->where('journal_lines.branch_id', $branchId))
            ->when($dateFrom, fn ($q) => $q->whereDate('journal_entries.entry_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('journal_entries.entry_date', '<=', $dateTo))
            ->with(['account', 'branch', 'journalEntry'])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->limit(2000)
            ->get();

        // Running balance is only meaningful when a single account is selected.
        $showRunning = (bool) $account;
        if ($showRunning) {
            $sign = $account->normal_balance === 'debit' ? 1 : -1;
            $running = 0.0;
            foreach ($lines as $line) {
                $running += $sign * ((float) $line->debit - (float) $line->credit);
                $line->running = $running;
            }
        }

        return view('tenant.finance.general-ledger.index', [
            'lines'       => $lines,
            'account'     => $account,
            'showRunning' => $showRunning,
            'accounts'    => Account::orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name']),
            'branches'    => Branch::orderBy('name')->get(['id', 'name']),
            'filters'     => $request->only(['account_id', 'branch_id', 'date_from', 'date_to']),
        ]);
    }
}
