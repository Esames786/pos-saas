<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\JournalEntry;
use App\Services\Finance\FinancialExportService;
use App\Services\Finance\JournalEventResolver;
use App\Support\CsvStreamer;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    public function __construct(
        private FinancialExportService $exportService,
        private JournalEventResolver $events,
    ) {}

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
        // JOURNAL-EVENT-REF-1: the Event # filter takes the whole number or any part of it. When no
        // event number contains the text, that is reported to the view so
        // the screen can say so, rather than showing an empty table that looks like a result.
        $eventNo = trim((string) $request->input('event_no', ''));
        $eventNotFound = false;
        if ($eventNo !== '') {
            $resolved = $this->events->entryRefsForEvent($eventNo);
            if ($resolved === null) {
                $eventNotFound = true;
                $query->whereRaw('1 = 0');
            } else {
                $this->events->applyEventFilter($query, $resolved['refs']);
            }
        }

        if ($request->filled('q')) {
            $search = trim($request->q);
            // An event number pasted into the search box used to find nothing — that is how this
            // job started. It now ORs in that event's entries alongside the existing matching,
            // which is left exactly as it was.
            $eventRefs = $this->events->refsForEventNoLike($search);
            $query->where(function ($q) use ($search, $eventRefs) {
                $q->where('entry_no', 'like', "%{$search}%")
                  ->orWhere('source_no', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
                foreach ($eventRefs as $ref) {
                    $q->orWhere(function ($inner) use ($ref) {
                        $inner->whereIn('source_type', $ref['types'])
                              ->whereIn('source_id', $ref['ids']);
                    });
                }
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

        $entries = $query->orderByDesc('entry_date')->orderByDesc('id')->limit(500)->get();

        return view('tenant.finance.journal-entries.index', [
            'entries'       => $entries,
            'sourceTypes'   => $sourceTypes,
            // One batched lookup for the whole page, keyed by entry id.
            'eventFor'      => $this->events->forEntries($entries),
            'eventNotFound' => $eventNotFound,
            'filters'       => $request->only(['date_from', 'date_to', 'q'])
                + ['source_type' => $sourceFilter, 'event_no' => $eventNo],
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

        // Same resolver as the list, so the detail and the row it was opened from can never
        // disagree about which event the entry belongs to.
        $event = $this->events->forEntries(collect([$journalEntry]))[$journalEntry->id] ?? null;

        return view('tenant.finance.journal-entries.show', compact('journalEntry', 'reversal', 'event'));
    }

    private function csvEntries($query)
    {
        $entries = (clone $query)->orderByDesc('entry_date')->orderByDesc('id')->limit(5000)->get();
        $eventFor = $this->events->forEntries($entries);

        $header = CsvStreamer::financeHeader('Journal Entries');

        return CsvStreamer::download('journal-entries-' . now()->format('Y-m-d') . '.csv', $header, function ($fp) use ($entries, $eventFor) {
            // JOURNAL-EVENT-REF-1: Event # and Customer are APPENDED, so a spreadsheet built on
            // the old column positions keeps working.
            fputcsv($fp, ['Entry No', 'Date', 'Source', 'Source No', 'Description', 'Status', 'Debit', 'Credit', 'Reversal', 'Event #', 'Customer']);
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
                    $eventFor[$e->id]['event_no'] ?? '',
                    $eventFor[$e->id]['customer_name'] ?? '',
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
            $this->requestedSourceTypes($request),
            // JOURNAL-EVENT-REF-1: the same Event # the screen was filtered by. A screen that
            // filters while its download does not is a bug — the lesson of JOURNAL-SOURCE-MULTI-1.
            trim((string) $request->input('event_no', ''))
        );

        $header = CsvStreamer::financeHeader('Journal Lines (detail)');

        // One batched lookup for the whole export, keyed by journal_entry_id.
        $eventFor = $this->events->forEntries(
            $lines->map(fn ($l) => $l->journalEntry)->filter()->unique('id')->values()
        );

        return CsvStreamer::download('journal-lines-' . now()->format('Y-m-d') . '.csv', $header, function ($fp) use ($lines, $eventFor) {
            // JOURNAL-EVENT-REF-1: appended LAST, so old spreadsheets keep their positions.
            fputcsv($fp, ['Entry No', 'Date', 'Source', 'Account Code', 'Account', 'Branch', 'Description', 'Debit', 'Credit', 'Event #', 'Customer']);
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
                    $eventFor[(int) ($line->journal_entry_id ?? 0)]['event_no'] ?? '',
                    $eventFor[(int) ($line->journal_entry_id ?? 0)]['customer_name'] ?? '',
                ]);
            }
        });
    }
}
