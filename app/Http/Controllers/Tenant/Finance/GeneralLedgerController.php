<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Concerns\NormalizesBranchIds;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Account;
use App\Models\Tenant\Branch;
use App\Services\Finance\FinancialExportService;
use App\Services\Finance\JournalEventResolver;
use App\Support\CsvStreamer;
use Illuminate\Http\Request;

class GeneralLedgerController extends Controller
{
    use NormalizesBranchIds;

    public function __construct(
        private FinancialExportService $exportService,
        private JournalEventResolver $events,
    ) {}

    public function index(Request $request)
    {
        $accountId = $request->input('account_id');
        $branchIds = $this->normalizeBranchIds($request);
        $dateFrom  = $request->input('date_from');
        $dateTo    = $request->input('date_to');

        $account = $accountId ? Account::find($accountId) : null;

        // JOURNAL-EVENT-REF-1: an Event # (whole or partial) narrows the ledger to the matching events' trails.
        // With no account chosen that is every line of the event across all accounts; the running
        // balance stays account-only, as it always was.
        $eventNo = trim((string) $request->input('event_no', ''));
        $eventNotFound = $eventNo !== '' && $this->events->entryRefsForEvent($eventNo) === null;

        $lines = $this->exportService->generalLedgerLines(
            $dateFrom ?: '2000-01-01',
            $dateTo ?: today()->format('Y-m-d'),
            $branchIds,
            $accountId ?: null,
            5000,
            [],
            $eventNo
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

        // Each line carries its journal entry, so the resolver is fed the ENTRIES behind the
        // lines — one batched lookup for the page, keyed by journal_entry_id.
        // Resolved BEFORE the CSV branch: the download needs the same map the screen shows.
        $eventFor = $this->events->forEntries(
            $lines->map(fn ($l) => $l->journalEntry)->filter()->unique('id')->values()
        );

        if ($request->boolean('export_csv')) {
            return $this->csv($lines, $account, $showRunning, $branchIds, $eventFor);
        }

        return view('tenant.finance.general-ledger.index', [
            'lines'             => $lines,
            'eventFor'          => $eventFor,
            'eventNotFound'     => $eventNotFound,
            'account'           => $account,
            'showRunning'       => $showRunning,
            'accounts'          => Account::orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name']),
            'branches'          => Branch::orderBy('name')->get(['id', 'name']),
            'selectedBranchIds' => $branchIds ?? [],
            'filters'           => $request->only(['account_id', 'branch_ids', 'date_from', 'date_to'])
                + ['event_no' => $eventNo],
        ]);
    }


    private function csv($lines, ?Account $account, bool $showRunning, ?array $branchIds, array $eventFor = [])
    {
        $branchName = $branchIds
            ? Branch::whereIn('id', $branchIds)->orderBy('name')->pluck('name')->implode(', ')
            : 'All Branches';

        $header = CsvStreamer::financeHeader('General Ledger', [
            'Account' => $account ? ($account->code . ' — ' . $account->name) : 'All accounts',
            'Branch'  => $branchName,
        ]);

        return CsvStreamer::download('general-ledger-' . now()->format('Y-m-d') . '.csv', $header, function ($fp) use ($lines, $showRunning, $eventFor) {
            $cols = ['Date', 'Entry No', 'Account Code', 'Account', 'Branch', 'Description', 'Debit', 'Credit'];
            if ($showRunning) {
                $cols[] = 'Running Balance';
            }
            // JOURNAL-EVENT-REF-1: appended LAST, after the running balance, so a spreadsheet
            // built on the old positions keeps working.
            $cols[] = 'Event #';
            $cols[] = 'Customer';
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
                $entryId = (int) ($line->journal_entry_id ?? 0);
                $row[] = $eventFor[$entryId]['event_no'] ?? '';
                $row[] = $eventFor[$entryId]['customer_name'] ?? '';
                fputcsv($fp, $row);
            }
        });
    }
}
