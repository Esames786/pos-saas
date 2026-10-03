<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * JOURNAL-EVENT-REF-1 — which catering event a journal entry belongs to, and the other way round.
 *
 * An accountant opening a catering journal could not tell which event it was for: invoice entries
 * carry the event nowhere, and receipt entries hide it in the description behind a ULID source no.
 *
 * Looked up at DISPLAY TIME and never written into the journals. Posted entries stay exactly as
 * posted, every past entry benefits at once, and there is no migration or backfill.
 *
 * ONE class serves all three screens and both filters on purpose. The forward direction
 * (entry -> event) and the reverse (event -> entries) are the same mapping read two ways; split
 * across call sites they would drift, and a filter that disagrees with the column beside it is
 * worse than neither.
 */
class JournalEventResolver
{
    /**
     * Every catering journal source and the table its `source_id` points at.
     *
     * Confirmed against JournalPostingService: these eight are the complete set — a grep of the
     * whole app for a `catering_*` source_type returns exactly these. Anything else (POS sale,
     * purchase bill, supplier payment, expense) is not here, so it resolves to nothing and runs
     * no query at all.
     */
    private const SOURCE_TABLES = [
        'catering_advance'             => 'catering_advances',
        'catering_settlement'          => 'catering_advances',
        'catering_split_receipt'       => 'catering_advances',
        'catering_refund'              => 'catering_refunds',
        'catering_split_refund'        => 'catering_refunds',
        'catering_final_invoice'       => 'catering_final_invoices',
        'catering_advance_application' => 'catering_final_invoices',
        'catering_material_issue'      => 'catering_material_issues',
    ];

    /**
     * A reversal belongs to the same event as what it reverses.
     *
     * JournalService::reverse() writes `<type>_reversal` with the SAME `source_id`, so stripping
     * the suffix is all that is needed — and a voided receipt's reversal must show and filter
     * under its event, or voiding would make a receipt vanish from its own event's trail.
     */
    private function baseSourceType(?string $sourceType): ?string
    {
        if ($sourceType === null) {
            return null;
        }

        $base = preg_replace('/_reversal$/', '', $sourceType);

        return isset(self::SOURCE_TABLES[$base]) ? $base : null;
    }

    /**
     * Entry -> event, for a whole page of entries at once.
     *
     * Batched deliberately: the Journal Entries list loads up to 500 entries and the General
     * Ledger up to 5,000 lines, so a per-row lookup would be an N+1 that grows with the page.
     * This runs at most one query per source table plus one for the events, whatever the page size.
     *
     * @param  Collection  $entries  anything with `id`, `source_type`, `source_id`
     * @return array<int, array{event_id: int, event_no: string, customer_name: ?string}>
     */
    public function forEntries(Collection $entries): array
    {
        $byTable = [];
        foreach ($entries as $entry) {
            $base = $this->baseSourceType($entry->source_type ?? null);
            $sourceId = (int) ($entry->source_id ?? 0);
            if ($base === null || $sourceId <= 0) {
                continue;
            }
            $byTable[self::SOURCE_TABLES[$base]][(int) $entry->id] = $sourceId;
        }

        if (! $byTable) {
            return [];
        }

        $eventIdFor = [];
        foreach ($byTable as $table => $map) {
            $rows = $this->eventIdsBySourceId($table, array_values(array_unique($map)));
            foreach ($map as $entryId => $sourceId) {
                $eventId = $rows[$sourceId] ?? null;
                // catering_material_issues.catering_event_id is NULLABLE — a direct issue has no
                // event, and that is a legitimate state, not missing data. Those entries simply
                // resolve to nothing and the screens show a dash.
                if ($eventId) {
                    $eventIdFor[$entryId] = (int) $eventId;
                }
            }
        }

        if (! $eventIdFor) {
            return [];
        }

        $events = DB::connection('tenant')->table('catering_events')
            ->whereIn('id', array_values(array_unique($eventIdFor)))
            ->get(['id', 'event_no', 'customer_name'])
            ->keyBy('id');

        $out = [];
        foreach ($eventIdFor as $entryId => $eventId) {
            $event = $events->get($eventId);
            if (! $event) {
                continue;
            }
            $out[$entryId] = [
                'event_id'      => (int) $event->id,
                'event_no'      => (string) $event->event_no,
                // The name typed on the BOOKING, which is what the quotation and invoice carry.
                // Deliberately not the linked `customers` record: several kashifkitchen bookings
                // are unlinked or point at a wrong/fake customer, so that name would be blank or
                // wrong on some rows.
                'customer_name' => $event->customer_name !== null ? (string) $event->customer_name : null,
            ];
        }

        return $out;
    }

    /**
     * Event -> the (source_type, source_id) pairs of its entries, for the filters and the search.
     *
     * Returns null when no such event exists, so a caller can say "No event EV-... found" instead
     * of showing an empty table that looks like a working filter with no results.
     *
     * @return array{event: object, refs: list<array{types: list<string>, ids: list<int>}>}|null
     */
    public function entryRefsForEvent(string $eventNo): ?array
    {
        $eventNo = trim($eventNo);
        if ($eventNo === '') {
            return null;
        }

        // EXACT number, case-insensitive. The event number is the key; a partial like "0002" would
        // match dozens of events and quietly answer a different question than the one asked.
        $event = DB::connection('tenant')->table('catering_events')
            ->whereRaw('LOWER(event_no) = ?', [mb_strtolower($eventNo)])
            ->first(['id', 'event_no', 'customer_name']);

        if (! $event) {
            return null;
        }

        // One group per TABLE, carrying every source type that points at it (and its _reversal),
        // so the whole trail comes back: invoice, receipts, advance application, refunds, material
        // issues — and the reversal of any of them.
        $typesByTable = [];
        foreach (self::SOURCE_TABLES as $type => $table) {
            $typesByTable[$table][] = $type;
            $typesByTable[$table][] = $type . '_reversal';
        }

        $refs = [];
        foreach ($typesByTable as $table => $types) {
            $ids = $this->sourceIdsForEvent($table, (int) $event->id);
            if ($ids) {
                $refs[] = ['types' => array_values($types), 'ids' => $ids];
            }
        }

        return ['event' => $event, 'refs' => $refs];
    }

    /**
     * Narrow a journal query to one event's entries.
     *
     * `$refs` empty (the event exists but has no journals yet) deliberately matches NOTHING: an
     * event with no entries has no entries, and widening to everything would be a lie.
     */
    public function applyEventFilter($query, array $refs, string $table = 'journal_entries'): void
    {
        $query->where(function ($outer) use ($refs, $table) {
            if (! $refs) {
                $outer->whereRaw('1 = 0');

                return;
            }
            foreach ($refs as $ref) {
                $outer->orWhere(function ($q) use ($ref, $table) {
                    $q->whereIn($table . '.source_type', $ref['types'])
                        ->whereIn($table . '.source_id', $ref['ids']);
                });
            }
        });
    }

    /**
     * id -> catering_event_id for one source table.
     *
     * RAW TABLE QUERY ON PURPOSE for `catering_advances`: the CateringAdvance model carries a
     * `notVoided` global scope, and catering's money rules allow escaping it in only three places,
     * all display. A voided receipt's journal AND its reversal must still show their event — a
     * void must not erase a receipt from its event's trail — so this reads the table directly.
     *
     * It selects `id` and `catering_event_id` and NOTHING ELSE: no amounts, no sums, no status.
     * This is a label lookup, not a fourth money path, and must never become one.
     *
     * @param  list<int>  $ids
     * @return array<int, int|null>
     */
    private function eventIdsBySourceId(string $table, array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return DB::connection('tenant')->table($table)
            ->whereIn('id', $ids)
            ->pluck('catering_event_id', 'id')
            ->all();
    }

    /**
     * The source rows of one event in one table — the same raw-lookup rule as above.
     *
     * @return list<int>
     */
    private function sourceIdsForEvent(string $table, int $eventId): array
    {
        return DB::connection('tenant')->table($table)
            ->where('catering_event_id', $eventId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
