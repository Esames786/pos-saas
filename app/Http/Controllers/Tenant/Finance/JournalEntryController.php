<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\JournalEntry;
use App\Services\Finance\FinancialExportService;
use App\Support\CsvStreamer;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    public function __construct(private FinancialExportService $exportService) {}

    public function index(Request $request)
    {
        $query = JournalEntry::query();

        if ($request->filled('date_from')) {
            $query->whereDate('entry_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('entry_date', '<=', $request->date_to);
        }
        // JOURNAL-SOURCE-MULTI-1: the Source filter takes several sources at once. A SCALAR is
        // still accepted, because every link and bookmark already out there carries
        // ?source_type=supplier_payment and must keep working.
        $sourceFilter = $this->requestedSourceTypes($request);
        if ($sourceFilter) {
            $query->whereIn('source_type', $sourceFilter);
        }
        if ($request->filled('q')) {
            $search = trim($request->q);
            $query->where(function ($q) use ($search) {
                $q->where('entry_no', 'like', "%{$search}%")
                  ->orWhere('source_no', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // CSV exports: "1" = entry header list, "lines" = line-level detail.
        $export = $request->input('export_csv');
        if ($export === 'lines') {
            return $this->csvLines($request);
        }
        if ($request->boolean('export_csv')) {
            return $this->csvEntries($query);
        }

        $sourceTypes = JournalEntry::query()
            ->whereNotNull('source_type')
            ->distinct()
            ->orderBy('source_type')
            ->pluck('source_type');

        return view('tenant.finance.journal-entries.index', [
            'entries'     => $query->orderByDesc('entry_date')->orderByDesc('id')->limit(500)->get(),
            'sourceTypes' => $sourceTypes,
            'filters'     => $request->only(['date_from', 'date_to', 'q']) + ['source_type' => $sourceFilter],
        ]);
    }

    /**
     * JOURNAL-SOURCE-MULTI-1 — the Source filter, however it arrived.
     *
     * `?source_type=supplier_payment` (old links, bookmarks, anything already saved) and
     * `?source_type[]=a&source_type[]=b` (the tick-list) both land here. Blanks are dropped so an
     * untouched "All sources" stays empty rather than becoming a filter on the empty string.
     *
     * @return list<string>
     */
    private function requestedSourceTypes(Request $request): array
    {
        $raw = $request->input('source_type', []);

        return collect(is_array($raw) ? $raw : [$raw])
            ->map(fn ($v) => is_string($v) ? trim($v) : $v)
            ->filter(fn ($v) => $v !== '' && $v !== null)
            ->unique()
            ->values()
            ->all();
    }
    public function show(JournalEntry $journalEntry)
    {
        $journalEntry->load(['lines.account', 'lines.branch', 'postedBy', 'reversedEntry']);

        $reversal = JournalEntry::where('reversed_entry_id', $journalEntry->id)->first();

        return view('tenant.finance.journal-entries.show', compact('journalEntry', 'reversal'));
    }

    private function csvEntries($query)
    {
        $entries = (clone $query)->orderByDesc('entry_date')->orderByDesc('id')->limit(5000)->get();

        $header = CsvStreamer::financeHeader('Journal Entries');

        return CsvStreamer::download('journal-entries-' . now()->format('Y-m-d') . '.csv', $header, function ($fp) use ($entries) {
            fputcsv($fp, ['Entry No', 'Date', 'Source', 'Source No', 'Description', 'Status', 'Debit', 'Credit', 'Reversal']);
            foreach ($entries as $e) {
                fputcsv($fp, [
                    $e->entry_no,
                    optional($e->entry_date)->format('Y-m-d'),
                    $e->source_type,
                    $e->source_no,
                    $e->description,
                    $e->status,
                    number_format((float) $e->total_debit, 2, '.', ''),
                    number_format((float) $e->total_credit, 2, '.', ''),
                    $e->is_reversal ? 'yes' : '',
                ]);
            }
        });
    }

    private function csvLines(Request $request)
    {
        // JOURNAL-SOURCE-MULTI-1: this export used to ignore the Source filter entirely, so the
        // screen and its own line-level CSV disagreed. Harmless while the filter was one awkward
        // dropdown nobody reached for; a tick-list invites use, and a filter that silently does
        // not apply to one of the two buttons beside it is a trap.
        $lines = $this->exportService->generalLedgerLines(
            $request->input('date_from') ?: '2000-01-01',
            $request->input('date_to') ?: today()->format('Y-m-d'),
            null,
            null,
            5000,
            $this->requestedSourceTypes($request)
        );

        $header = CsvStreamer::financeHeader('Journal Lines (detail)');

        return CsvStreamer::download('journal-lines-' . now()->format('Y-m-d') . '.csv', $header, function ($fp) use ($lines) {
            fputcsv($fp, ['Entry No', 'Date', 'Source', 'Account Code', 'Account', 'Branch', 'Description', 'Debit', 'Credit']);
            foreach ($lines as $line) {
                fputcsv($fp, [
                    $line->journalEntry->entry_no ?? '',
                    optional($line->journalEntry->entry_date)->format('Y-m-d'),
                    $line->journalEntry->source_type ?? '',
                    $line->account->code ?? '',
                    $line->account->name ?? '',
                    $line->branch->name ?? '',
                    $line->description,
                    number_format((float) $line->debit, 2, '.', ''),
                    number_format((float) $line->credit, 2, '.', ''),
                ]);
            }
        });
    }
}
