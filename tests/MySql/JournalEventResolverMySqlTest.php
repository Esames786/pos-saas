<?php

namespace Tests\MySql;

use App\Services\Finance\JournalEventResolver;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * JOURNAL-EVENT-REF-1 — the mapping between a journal entry and its catering event.
 *
 * An accountant opening a catering journal could not tell which event it belonged to: invoice
 * entries carry the event nowhere, receipt entries hide it in the description behind a ULID.
 *
 * Every catering source type is covered here, AND its `_reversal`, because a void must not erase
 * a receipt from its event's trail. The reverse direction (event -> entries) is tested from the
 * same class as the forward one, which is the whole reason they live together.
 */
class JournalEventResolverMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $eventId;
    private string $eventNo = 'EV-20260909-0002';

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'journal_lines', 'journal_entries', 'catering_advances', 'catering_refunds',
            'catering_final_invoices', 'catering_material_issues', 'catering_events', 'branches',
        ]);

        $this->eventId = DB::connection('tenant')->table('catering_events')->insertGetId([
            'event_no' => $this->eventNo, 'customer_name' => 'mr. farhat hussain',
            'booking_date' => '2026-09-09', 'event_date' => '2026-10-15',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function resolver(): JournalEventResolver
    {
        return app(JournalEventResolver::class);
    }

    /** @var array<string, int> one source row per table+event — see the note below. */
    private array $sourceCache = [];

    /**
     * A source row in the given table, belonging to $eventId unless told otherwise.
     *
     * Reused per table+event on purpose: `catering_final_invoices` has a UNIQUE index on
     * `catering_event_id` — an event has at most ONE final invoice — and both
     * `catering_final_invoice` and `catering_advance_application` entries point at that same
     * row. Production is exactly this shape: JE-20260909-0001 and its advance applications all
     * carry source_id 1. Creating a second row per type would be a fixture the system cannot
     * actually produce.
     */
    private function source(string $table, ?int $eventId = null): int
    {
        $eventId ??= $this->eventId;
        $key = $table . ':' . $eventId;
        if (isset($this->sourceCache[$key])) {
            return $this->sourceCache[$key];
        }
        $u = uniqid();
        $rows = [
            'catering_advances' => [
                'catering_event_id' => $eventId, 'amount' => 1000, 'received_date' => '2026-09-09',
            ],
            'catering_refunds' => [
                'refund_no' => 'RF-' . $u, 'catering_event_id' => $eventId, 'amount' => 100,
                'refund_date' => '2026-09-10', 'reason' => 'test',
            ],
            'catering_final_invoices' => [
                'invoice_no' => 'CI-' . $u, 'catering_event_id' => $eventId,
                'snapshot' => '{}', 'issued_at' => now(),
            ],
            'catering_material_issues' => [
                'issue_no' => 'MI-' . $u, 'branch_id' => $this->makeBranch(),
                'catering_event_id' => $eventId, 'issued_at' => now(),
            ],
        ];

        return $this->sourceCache[$key] = DB::connection('tenant')->table($table)
            ->insertGetId($rows[$table] + ['created_at' => now(), 'updated_at' => now()]);
    }

    /** A posted journal entry pointing at a source row. */
    private function entry(string $sourceType, int $sourceId): int
    {
        return DB::connection('tenant')->table('journal_entries')->insertGetId([
            'entry_no' => 'JE-' . uniqid(), 'entry_date' => '2026-09-09', 'status' => 'posted',
            'source_type' => $sourceType, 'source_id' => $sourceId,
            'description' => $sourceType, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function resolve(int ...$entryIds): array
    {
        $entries = DB::connection('tenant')->table('journal_entries')
            ->whereIn('id', $entryIds)->get(['id', 'source_type', 'source_id']);

        return $this->resolver()->forEntries($entries);
    }

    public function test_every_catering_source_type_resolves_to_its_event(): void
    {
        $byTable = [
            'catering_advances'        => ['catering_advance', 'catering_settlement', 'catering_split_receipt'],
            'catering_refunds'         => ['catering_refund', 'catering_split_refund'],
            'catering_final_invoices'  => ['catering_final_invoice', 'catering_advance_application'],
            'catering_material_issues' => ['catering_material_issue'],
        ];

        foreach ($byTable as $table => $types) {
            foreach ($types as $type) {
                $entryId = $this->entry($type, $this->source($table));
                $got = $this->resolve($entryId);

                $this->assertArrayHasKey($entryId, $got, $type . ' must resolve');
                $this->assertSame($this->eventNo, $got[$entryId]['event_no'], $type);
                $this->assertSame('mr. farhat hussain', $got[$entryId]['customer_name'], $type);
            }
        }
    }

    public function test_a_reversal_resolves_to_the_same_event_as_what_it_reverses(): void
    {
        // JournalService::reverse() writes the type with a _reversal suffix and the SAME source_id.
        // If the suffix were not stripped, voiding a receipt would make it vanish from its event
        // trail — the one moment an accountant most needs to see it.
        foreach ([
            'catering_advance_reversal'             => 'catering_advances',
            'catering_settlement_reversal'          => 'catering_advances',
            'catering_split_receipt_reversal'       => 'catering_advances',
            'catering_refund_reversal'              => 'catering_refunds',
            'catering_split_refund_reversal'        => 'catering_refunds',
            'catering_final_invoice_reversal'       => 'catering_final_invoices',
            'catering_advance_application_reversal' => 'catering_final_invoices',
            'catering_material_issue_reversal'      => 'catering_material_issues',
        ] as $type => $table) {
            $entryId = $this->entry($type, $this->source($table));
            $got = $this->resolve($entryId);

            $this->assertArrayHasKey($entryId, $got, $type . ' must resolve');
            $this->assertSame($this->eventNo, $got[$entryId]['event_no'], $type);
        }
    }

    public function test_a_material_issue_without_an_event_resolves_to_nothing(): void
    {
        // catering_material_issues.catering_event_id is nullable: a direct issue has no event.
        // That is a legitimate state, not missing data — the screen shows a dash.
        $issueId = DB::connection('tenant')->table('catering_material_issues')->insertGetId([
            'issue_no' => 'MI-direct', 'branch_id' => $this->makeBranch(),
            'catering_event_id' => null, 'issued_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $entryId = $this->entry('catering_material_issue', $issueId);

        $entries = DB::connection('tenant')->table('journal_entries')
            ->where('id', $entryId)->get(['id', 'source_type', 'source_id']);

        DB::connection('tenant')->enableQueryLog();
        DB::connection('tenant')->flushQueryLog();
        $got = $this->resolver()->forEntries($entries);
        $queries = count(DB::connection('tenant')->getQueryLog());
        DB::connection('tenant')->disableQueryLog();

        $this->assertSame([], $got);
        // ONE query: the issue lookup. Letting a null event id through would add a pointless
        // second query for event id 0 — the row would still be dropped later, so the only way
        // to see that guard working is to count what it saves.
        $this->assertSame(1, $queries, 'a null event id must not cost an events query');
    }

    public function test_non_catering_entries_resolve_to_nothing_and_run_no_query(): void
    {
        $ids = [
            $this->entry('purchase_bill', 1),
            $this->entry('sales_order_paid', 2),
            $this->entry('supplier_payment', 3),
            $this->entry('expense_voucher', 4),
        ];

        $entries = DB::connection('tenant')->table('journal_entries')
            ->whereIn('id', $ids)->get(['id', 'source_type', 'source_id']);

        DB::connection('tenant')->enableQueryLog();
        DB::connection('tenant')->flushQueryLog();
        $got = $this->resolver()->forEntries($entries);
        $queries = count(DB::connection('tenant')->getQueryLog());
        DB::connection('tenant')->disableQueryLog();

        $this->assertSame([], $got);
        $this->assertSame(0, $queries, 'a page with no catering entries must not touch the database');
    }

    public function test_the_lookup_is_batched_and_does_not_grow_with_the_page(): void
    {
        // The JE list loads up to 500 entries and the GL up to 5,000 lines. A per-row lookup would
        // be an N+1 that only shows itself on a real page.
        $count = function (int $rows) {
            $ids = [];
            for ($i = 0; $i < $rows; $i++) {
                $ids[] = $this->entry('catering_advance', $this->source('catering_advances'));
            }
            $entries = DB::connection('tenant')->table('journal_entries')
                ->whereIn('id', $ids)->get(['id', 'source_type', 'source_id']);

            DB::connection('tenant')->enableQueryLog();
            DB::connection('tenant')->flushQueryLog();
            $this->resolver()->forEntries($entries);
            $n = count(DB::connection('tenant')->getQueryLog());
            DB::connection('tenant')->disableQueryLog();

            return $n;
        };

        $this->assertSame($count(2), $count(20),
            'resolving 20 rows must cost the same number of queries as resolving 2');
    }

    public function test_an_unknown_event_number_is_reported_rather_than_filtered_to_nothing(): void
    {
        // null lets the caller say "No event EV-... found" instead of showing an empty table that
        // looks like a working filter with no results.
        $this->assertNull($this->resolver()->entryRefsForEvent('EV-does-not-exist'));
        $this->assertNull($this->resolver()->entryRefsForEvent('   '));

        // A PARTIAL must not match: the event number is the key, and 0002 would hit dozens.
        $this->assertNull($this->resolver()->entryRefsForEvent('0002'));

        // Exact, case-insensitive, trimmed.
        $this->assertNotNull($this->resolver()->entryRefsForEvent('  ev-20260909-0002  '));
    }

    public function test_filtering_by_event_returns_its_whole_trail_including_reversals(): void
    {
        $mine = [
            $this->entry('catering_final_invoice', $this->source('catering_final_invoices')),
            $this->entry('catering_advance', $this->source('catering_advances')),
            $this->entry('catering_advance_reversal', $this->source('catering_advances')),
            $this->entry('catering_refund', $this->source('catering_refunds')),
        ];

        // Another event, and a non-catering entry — neither may ride along.
        $otherEventId = DB::connection('tenant')->table('catering_events')->insertGetId([
            'event_no' => 'EV-20260909-0009', 'customer_name' => 'someone else',
            'booking_date' => '2026-09-09', 'event_date' => '2026-10-20',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entry('catering_advance', $this->source('catering_advances', $otherEventId));
        $this->entry('purchase_bill', 1);

        $resolved = $this->resolver()->entryRefsForEvent($this->eventNo);
        $this->assertNotNull($resolved);

        $query = DB::connection('tenant')->table('journal_entries');
        $this->resolver()->applyEventFilter($query, $resolved['refs']);
        $got = $query->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        sort($mine);
        $this->assertSame($mine, $got, 'invoice, receipt, its reversal and refund — and nothing else');
    }

    public function test_an_event_with_no_journals_filters_to_nothing_not_everything(): void
    {
        $this->entry('catering_advance', $this->source('catering_advances'));

        DB::connection('tenant')->table('catering_events')->insert([
            'event_no' => 'EV-EMPTY-0001', 'customer_name' => 'no journals yet',
            'booking_date' => '2026-09-09', 'event_date' => '2026-11-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resolved = $this->resolver()->entryRefsForEvent('EV-EMPTY-0001');
        $this->assertNotNull($resolved, 'the event exists');
        $this->assertSame([], $resolved['refs']);

        $query = DB::connection('tenant')->table('journal_entries');
        $this->resolver()->applyEventFilter($query, $resolved['refs']);

        // Widening an empty filter to everything would be the worst kind of wrong: it looks like
        // a result.
        $this->assertSame(0, $query->count());
    }
}
