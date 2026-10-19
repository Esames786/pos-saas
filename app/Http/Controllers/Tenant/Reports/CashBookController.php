<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Concerns\NormalizesBranchIds;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Reports\CashBookService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CASH-BOOK-REPORT-1 — Reports → Cash Book: money in, money out and the balance left, per cash
 * drawer / bank, for any dates. Same filters drive the screen, the CSV and the print.
 */
class CashBookController extends Controller
{
    use NormalizesBranchIds;

    /** Above this many entries the screen opens day by day (a restaurant writes ~300 a day). */
    public const DAILY_ABOVE = 1000;

    public function index(Request $request, CashBookService $book)
    {
        $data = $request->validate([
            'date_from'     => ['nullable', 'date'],
            'date_to'       => ['nullable', 'date'],
            'account_ids'   => ['nullable', 'array'],
            'account_ids.*' => ['integer'],
            'direction'     => ['nullable', Rule::in(['all', 'in', 'out'])],
            'mode'          => ['nullable', Rule::in(['entries', 'daily'])],
            'format'        => ['nullable', Rule::in(['csv', 'print'])],
            'page'          => ['nullable', 'integer', 'min:1'],
        ]);

        $from = $data['date_from'] ?? today()->startOfMonth()->toDateString();
        $to = $data['date_to'] ?? today()->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];   // dates typed the wrong way round still mean that period
        }

        $branchIds = $this->normalizeBranchIds($request);
        $options = $book->accounts($branchIds);
        $chosenIds = array_values(array_intersect(array_map('intval', $data['account_ids'] ?? []), $options->pluck('id')->all()));
        $accounts = $chosenIds ? $options->whereIn('id', $chosenIds)->values() : $options;

        $summary = $book->summary($accounts, $from, $to);
        $direction = $data['direction'] ?? 'all';
        $entryCount = array_sum(array_column($summary, 'entries'));
        $mode = $data['mode'] ?? ($entryCount > self::DAILY_ABOVE ? 'daily' : 'entries');
        $totals = [
            'opening' => round(array_sum(array_column($summary, 'opening')), 2),
            'in'      => round(array_sum(array_column($summary, 'in')), 2),
            'out'     => round(array_sum(array_column($summary, 'out')), 2),
            'closing' => round(array_sum(array_column($summary, 'closing')), 2),
        ];
        $filters = ['date_from' => $from, 'date_to' => $to, 'account_ids' => $chosenIds, 'direction' => $direction, 'mode' => $mode];

        $format = $data['format'] ?? null;
        $daily = $mode === 'daily' ? $book->daily($summary, $from, $to, $direction) : [];
        $entries = $mode === 'entries'
            ? $book->entries($accounts, $from, $to, $direction, (int) ($data['page'] ?? 1), $format !== null)
            : null;

        if ($format === 'csv') {
            return $this->csv($summary, $daily, $entries, $mode, $from, $to);
        }

        return view($format === 'print' ? 'tenant.reports.cash-book.print' : 'tenant.reports.cash-book.index', [
            'summary'     => $summary,
            'totals'      => $totals,
            'entries'     => $entries,
            'daily'       => $daily,
            'mode'        => $mode,
            'entryCount'  => $entryCount,
            'filters'     => $filters,
            'accountOptions' => $options,
            'branches'    => Branch::where('status', 'active')->orderBy('name')->get(),
            'selectedBranchIds' => $branchIds ?? [],
            'typeLabels'  => CashBookService::TYPE_LABELS,
        ]);
    }

    private function csv(array $summary, array $daily, $entries, string $mode, string $from, string $to)
    {
        $name = 'cash-book-' . $from . '-to-' . $to . '.csv';

        return response()->streamDownload(function () use ($summary, $daily, $entries, $mode) {
            $fp = fopen('php://output', 'w');
            fputcsv($fp, ['Account', 'Opening', 'In', 'Out', 'Closing']);
            foreach ($summary as $s) {
                fputcsv($fp, [$s['account']->name, $s['opening'], $s['in'], $s['out'], $s['closing']]);
            }
            fputcsv($fp, []);
            if ($mode === 'daily') {
                fputcsv($fp, ['Account', 'Date', 'Opening', 'In', 'Out', 'Closing', 'Entries']);
                foreach ($daily as $id => $days) {
                    foreach ($days as $d) {
                        fputcsv($fp, [$summary[$id]['account']->name, $d['date'], $d['opening'], $d['in'], $d['out'], $d['closing'], $d['entries']]);
                    }
                }
            } else {
                fputcsv($fp, ['Account', 'Date', 'Type', 'Ref', 'From / to', 'In', 'Out', 'Balance']);
                foreach ($entries as $e) {
                    fputcsv($fp, [$summary[$e->cash_bank_account_id]['account']->name, $e->transaction_date, $e->type_label, $e->ref, $e->party,
                        $e->direction === 'in' ? round((float) $e->amount, 2) : '', $e->direction === 'out' ? round((float) $e->amount, 2) : '', $e->balance]);
                }
            }
            fclose($fp);
        }, $name, ['Content-Type' => 'text/csv']);
    }
}
