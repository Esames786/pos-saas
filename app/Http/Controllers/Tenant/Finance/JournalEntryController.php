<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\JournalEntry;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    public function index(Request $request)
    {
        $query = JournalEntry::query();

        if ($request->filled('date_from')) {
            $query->whereDate('entry_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('entry_date', '<=', $request->date_to);
        }
        if ($request->filled('source_type')) {
            $query->where('source_type', $request->source_type);
        }
        if ($request->filled('q')) {
            $search = trim($request->q);
            $query->where(function ($q) use ($search) {
                $q->where('entry_no', 'like', "%{$search}%")
                  ->orWhere('source_no', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sourceTypes = JournalEntry::query()
            ->whereNotNull('source_type')
            ->distinct()
            ->orderBy('source_type')
            ->pluck('source_type');

        return view('tenant.finance.journal-entries.index', [
            'entries'     => $query->orderByDesc('entry_date')->orderByDesc('id')->limit(500)->get(),
            'sourceTypes' => $sourceTypes,
            'filters'     => $request->only(['date_from', 'date_to', 'source_type', 'q']),
        ]);
    }

    public function show(JournalEntry $journalEntry)
    {
        $journalEntry->load(['lines.account', 'lines.branch', 'postedBy', 'reversedEntry']);

        $reversal = JournalEntry::where('reversed_entry_id', $journalEntry->id)->first();

        return view('tenant.finance.journal-entries.show', compact('journalEntry', 'reversal'));
    }
}
