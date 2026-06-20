<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Account;
use App\Models\Tenant\Branch;
use App\Services\Finance\FinancialExportService;
use App\Support\CsvStreamer;
use Illuminate\Http\Request;

class GeneralLedgerController extends Controller
{
    public function __construct(private FinancialExportService $exportService) {}

    public function index(Request $request)
    {
        $accountId = $request->input('account_id');
        $branchIds = $this->normalizeBranchIds($request);
        $dateFrom  = $request->input('date_from');
        $dateTo    = $request->input('date_to');

        $account = $accountId ? Account::find($accountId) : null;

        $lines = $this->exportService->generalLedgerLines(
            $dateFrom ?: '2000-01-01',
            $dateTo ?: today()->format('Y-m-d'),
            $branchIds,
            $accountId ?: null
        );

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

        if ($request->boolean('export_csv')) {
            return $this->csv($lines, $account, $showRunning, $branchIds);
        }

        return view('tenant.finance.general-ledger.index', [
            'lines'             => $lines,
            'account'           => $account,
            'showRunning'       => $showRunning,
            'accounts'          => Account::orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name']),
            'branches'          => Branch::orderBy('name')->get(['id', 'name']),
            'selectedBranchIds' => $branchIds ?? [],
            'filters'           => $request->only(['account_id', 'branch_ids', 'date_from', 'date_to']),
        ]);
    }

    private function normalizeBranchIds(Request $request): ?array
    {
        if ($request->filled('branch_ids')) {
            $ids = array_values(array_filter(array_map('intval', (array) $request->input('branch_ids'))));
            return $ids ?: null;
        }
        if ($request->filled('branch_id')) {
            return [(int) $request->input('branch_id')];
        }
        return null;
    }

    private function csv($lines, ?Account $account, bool $showRunning, ?array $branchIds)
    {
        $branchName = $branchIds
            ? Branch::whereIn('id', $branchIds)->orderBy('name')->pluck('name')->implode(', ')
            : 'All Branches';

        $header = CsvStreamer::financeHeader('General Ledger', [
            'Account' => $account ? ($account->code . ' — ' . $account->name) : 'All accounts',
            'Branch'  => $branchName,
        ]);

        return CsvStreamer::download('general-ledger-' . now()->format('Y-m-d') . '.csv', $header, function ($fp) use ($lines, $showRunning) {
            $cols = ['Date', 'Entry No', 'Account Code', 'Account', 'Branch', 'Description', 'Debit', 'Credit'];
            if ($showRunning) {
                $cols[] = 'Running Balance';
            }
            fputcsv($fp, $cols);

            foreach ($lines as $line) {
                $row = [
                    optional($line->journalEntry->entry_date)->format('Y-m-d'),
                    $line->journalEntry->entry_no ?? '',
                    $line->account->code ?? '',
                    $line->account->name ?? '',
                    $line->branch->name ?? '',
                    $line->description,
                    number_format((float) $line->debit, 2, '.', ''),
                    number_format((float) $line->credit, 2, '.', ''),
                ];
                if ($showRunning) {
                    $row[] = number_format((float) ($line->running ?? 0), 2, '.', '');
                }
                fputcsv($fp, $row);
            }
        });
    }
}
