<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Finance\GeneralLedgerController;
use App\Http\Controllers\Tenant\Finance\JournalEntryController;
use App\Models\Tenant\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * JOURNAL-EVENT-REF-1 — the three finance screens, driven through their real controllers.
 *
 * The resolver has its own guards; these are about what an accountant actually sees and can
 * filter by, and about the exports. A screen that filters while its download does not is a bug
 * — the lesson of JOURNAL-SOURCE-MULTI-1 — so each of the three CSVs is proved separately.
 */
class JournalEventScreenMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const EVENT = 'EV-20260909-0002';
    private const OTHER = 'EV-20260925-0069';
    private const CUSTOMER = 'mr. farhat hussain';

    private int $branchId;
    private array $acc = [];
    private int $eventId;
    private int $otherEventId;
    private int $invoiceId;
    private array $advanceIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'journal_lines', 'journal_entries', 'accounts', 'catering_advances',
            'catering_refunds', 'catering_final_invoices', 'catering_material_issues',
            'catering_events', 'branches',
        ]);

        $this->branchId = $this->makeBranch();

        foreach ([
            ['1300', 'Catering Receivable', 'asset', 'debit'],
            ['4160', 'Catering Revenue', 'revenue', 'credit'],
            ['4130', 'Service Charge', 'revenue', 'credit'],
            ['2100', 'Accounts Payable', 'liability', 'credit'],
        ] as [$code, $name, $type, $normal]) {
            $this->acc[$code] = DB::connection('tenant')->table('accounts')->insertGetId([
                'code' => $code, 'name' => $name, 'type' => $type,
                'normal_balance' => $normal, 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->eventId      = $this->makeEvent(self::EVENT, self::CUSTOMER);
        $this->otherEventId = $this->makeEvent(self::OTHER, 'mr. somebody else');

        // Event under test: one final invoice (UNIQUE per event) and two receipts.
        $this->invoiceId = DB::connection('tenant')->table('catering_final_invoices')->insertGetId([
            'invoice_no' => 'CI-20260909-0001', 'catering_event_id' => $this->eventId,
            'snapshot' => '{}', 'issued_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->advanceIds = [$this->makeAdvance($this->eventId), $this->makeAdvance($this->eventId)];

        // JE-...-0001 invoice, -0002 and -0003 its two receipts. These three are the whole trail.
        $this->entry('JE-20260909-0001', 'catering_final_invoice', $this->invoiceId, 'CI-20260909-0001', [
            ['1300', 118000, 0], ['4160', 0, 100000], ['4130', 0, 18000],
        ]);
        $this->entry('JE-20260909-0002', 'catering_advance', $this->advanceIds[0], '01M231MZYBK2EE79T5C8K6PEZR', [
            ['1300', 0, 50000], ['2100', 50000, 0],
        ]);
        $this->entry('JE-20260909-0003', 'catering_advance', $this->advanceIds[1], '01M231MZYBK2EE79T5C8K6PEZQ', [
            ['1300', 0, 20000], ['2100', 20000, 0],
        ]);

        // Another event's receipt, and an entry with no event at all. Both must stay out of
        // every filtered answer — without them a filter that matched everything would pass.
        $this->entry('JE-20260925-0100', 'catering_advance', $this->makeAdvance($this->otherEventId), 'OTHER', [
            ['1300', 0, 9000], ['2100', 9000, 0],
        ]);
        $this->entry('JE-20260909-0009', 'purchase_bill', 777, 'PB-1', [
            ['2100', 0, 4000], ['1300', 4000, 0],
        ]);

        view()->share('errors', new ViewErrorBag);
    }

    private function makeEvent(string $no, string $customer): int
    {
        return DB::connection('tenant')->table('catering_events')->insertGetId([
            'event_no' => $no, 'customer_name' => $customer,
            'booking_date' => '2026-09-09', 'event_date' => '2026-10-15',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeAdvance(int $eventId): int
    {
        return DB::connection('tenant')->table('catering_advances')->insertGetId([
            'catering_event_id' => $eventId, 'amount' => 1000, 'received_date' => '2026-09-09',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param list<array{0:string,1:float,2:float}> $lines */
    private function entry(string $no, string $sourceType, int $sourceId, ?string $sourceNo, array $lines): int
    {
        $id = DB::connection('tenant')->table('journal_entries')->insertGetId([
            'entry_no' => $no, 'entry_date' => '2026-09-09', 'status' => 'posted',
            'source_type' => $sourceType, 'source_id' => $sourceId, 'source_no' => $sourceNo,
            'description' => $no . ' ' . $sourceType,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as [$code, $debit, $credit]) {
            DB::connection('tenant')->table('journal_lines')->insert([
                'journal_entry_id' => $id, 'account_id' => $this->acc[$code],
                'branch_id' => $this->branchId, 'description' => $no . ' ' . $code,
                'debit' => $debit, 'credit' => $credit,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    // ---------- helpers that go through the real controllers ----------

    private function jeView(array $params)
    {
        return app(JournalEntryController::class)->index(Request::create('/finance/journal-entries', 'GET', $params));
    }

    /** Entry numbers the Journal Entries list actually prints. */
    private function jeListed(array $params): array
    {
        return collect($this->jeView($params)->getData()['entries'])->pluck('entry_no')->sort()->values()->all();
    }

    private function glView(array $params)
    {
        return app(GeneralLedgerController::class)->index(Request::create('/finance/general-ledger', 'GET', $params));
    }

    /** Entry numbers behind the General Ledger's lines — duplicates kept, one per line. */
    private function glEntryNos(array $params): array
    {
        return collect($this->glView($params + ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'])
            ->getData()['lines'])->map(fn ($l) => $l->journalEntry->entry_no)->sort()->values()->all();
    }

    /** Whatever a CSV export streamed. */
    private function streamed($response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function signIn(bool $withCateringPermission): void
    {
        DB::setDefaultConnection('tenant');
        $uid = $this->makeUser(['employee_code' => 'EV' . \Illuminate\Support\Str::random(4)]);
        $user = \App\Models\Tenant\User::on('tenant')->find($uid);
        if ($withCateringPermission) {
            \Spatie\Permission\Models\Permission::findOrCreate('tenant.catering.events.show', 'tenant');
            $user->givePermissionTo('tenant.catering.events.show');
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(\App\Models\Tenant\User::on('tenant')->find($uid), 'tenant');
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');
    }

    // ---------- the guards ----------

    /** Acceptance 1 — the complaint that started this: an invoice journal naming no event. */
    public function test_a_catering_journal_detail_names_its_event_and_customer(): void
    {
        $this->signIn(true);

        $entry = JournalEntry::on('tenant')->where('entry_no', 'JE-20260909-0001')->firstOrFail();
        $html  = app(JournalEntryController::class)->show($entry)->render();

        $this->assertStringContainsString(self::EVENT, $html, 'the event number must be on the page');
        $this->assertStringContainsString(self::CUSTOMER, $html, 'and the customer the booking was taken for');
        $this->assertStringContainsString('/catering/events/' . $this->eventId, $html,
            'with a link to the booking for a user allowed to open it');
    }

    /** Acceptance 2 + 7 — the Event # filter returns the whole trail and only that trail. */
    public function test_the_event_filter_returns_exactly_that_events_entries(): void
    {
        $this->assertSame(
            ['JE-20260909-0001', 'JE-20260909-0002', 'JE-20260909-0003'],
            $this->jeListed(['event_no' => self::EVENT])
        );
    }

    /** The number is the key. A partial would quietly answer a different question. */
    public function test_a_partial_or_unknown_event_number_matches_nothing_and_says_so(): void
    {
        foreach (['0002', 'EV-20261231-9999'] as $typed) {
            $view = $this->jeView(['event_no' => $typed]);

            $this->assertSame([], collect($view->getData()['entries'])->pluck('entry_no')->all(),
                "[$typed] must not match anything");
            $this->assertTrue($view->getData()['eventNotFound'],
                "[$typed] — an empty table looks exactly like a filter that worked, so it must be said out loud");
            $this->assertStringContainsString('No event <strong>' . $typed . '</strong> found', $view->render());
        }
    }

    /** A stray space either side is a paste, not a different event. */
    public function test_the_filter_survives_whitespace(): void
    {
        $this->assertSame(
            ['JE-20260909-0001', 'JE-20260909-0002', 'JE-20260909-0003'],
            $this->jeListed(['event_no' => '  ' . self::EVENT . '  '])
        );
    }

    /** Pasting an event number into the Search box found nothing — that is how this job started. */
    public function test_the_search_box_also_finds_entries_by_event_number(): void
    {
        $this->assertSame(
            ['JE-20260909-0001', 'JE-20260909-0002', 'JE-20260909-0003'],
            $this->jeListed(['q' => self::EVENT])
        );

        // And the matching that was already there must survive.
        $this->assertSame(['JE-20260909-0009'], $this->jeListed(['q' => 'PB-1']));
    }

    /** Acceptance 4 — nothing changes for an entry that has no event. */
    public function test_a_non_catering_row_renders_with_no_event_at_all(): void
    {
        $this->signIn(true);

        $html = $this->jeView(['q' => 'PB-1'])->render();

        $this->assertStringContainsString('PB-1', $html);
        foreach ([self::EVENT, self::OTHER] as $no) {
            $this->assertStringNotContainsString($no, $html, 'a purchase bill has no event to show');
        }
        $this->assertStringNotContainsString(self::CUSTOMER, $html);
        $this->assertStringNotContainsString('/catering/events/', $html);
    }

    /** Acceptance 6 — accounts staff may have no catering permission; the number still shows. */
    public function test_without_catering_permission_the_number_shows_but_not_the_link(): void
    {
        $this->signIn(false);

        $html = $this->jeView(['event_no' => self::EVENT])->render();

        $this->assertStringContainsString(self::EVENT, $html, 'the number is the useful part and stays');
        $this->assertStringContainsString(self::CUSTOMER, $html);
        $this->assertStringNotContainsString('/catering/events/', $html,
            'a link into a screen the user cannot open would only 403 them');
    }

    /** Acceptance 8 — the ledger, with and without an account chosen. */
    public function test_the_general_ledger_event_filter_shows_the_whole_trail_and_nothing_else(): void
    {
        // No account: every line of the event across all accounts — 3 + 2 + 2 = 7 lines.
        $all = $this->glEntryNos(['event_no' => self::EVENT]);
        $this->assertCount(7, $all, 'the invoice three lines plus two lines for each receipt');
        $this->assertSame(['JE-20260909-0001', 'JE-20260909-0002', 'JE-20260909-0003'],
            array_values(array_unique($all)));

        // One account: only that event's lines on it — one per entry here.
        $on1300 = $this->glEntryNos(['event_no' => self::EVENT, 'account_id' => $this->acc['1300']]);
        $this->assertSame(['JE-20260909-0001', 'JE-20260909-0002', 'JE-20260909-0003'], $on1300);

        // Unfiltered, the other event and the purchase bill are there — so the two assertions
        // above are really the filter biting, not a ledger that was empty anyway.
        $unfiltered = $this->glEntryNos([]);
        $this->assertContains('JE-20260925-0100', $unfiltered);
        $this->assertContains('JE-20260909-0009', $unfiltered);
    }

    /** The ledger says "not found" too, rather than handing back a full ledger. */
    public function test_an_unknown_event_number_on_the_ledger_matches_nothing(): void
    {
        $view = $this->glView(['event_no' => 'EV-20261231-9999', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31']);

        $this->assertCount(0, $view->getData()['lines'],
            'widening to every line would look like the answer to the question asked');
        $this->assertTrue($view->getData()['eventNotFound']);
    }

    /** The ledger shows the event on its lines, from the same resolver as the JE screens. */
    public function test_the_ledger_prints_the_event_on_its_lines(): void
    {
        $this->signIn(true);

        $html = $this->glView(['event_no' => self::EVENT, 'date_from' => '2026-01-01', 'date_to' => '2026-12-31'])->render();

        $this->assertStringContainsString(self::EVENT, $html);
        $this->assertStringContainsString(self::CUSTOMER, $html);
    }

    /**
     * Acceptance 9 — all three downloads. Proving the screen proves nothing about its exports:
     * the line-level CSV ignored the Source filter completely until JOURNAL-SOURCE-MULTI-1.
     */
    public function test_all_three_downloads_obey_the_event_filter(): void
    {
        $dates = ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'];

        $csvs = [
            'JE entries CSV' => $this->streamed($this->jeView(['event_no' => self::EVENT, 'export_csv' => '1'])),
            'JE lines CSV'   => $this->streamed($this->jeView(['event_no' => self::EVENT, 'export_csv' => 'lines'] + $dates)),
            'GL CSV'         => $this->streamed($this->glView(['event_no' => self::EVENT, 'export_csv' => '1'] + $dates)),
        ];

        foreach ($csvs as $which => $csv) {
            $this->assertStringContainsString('JE-20260909-0001', $csv, "$which: must really have rows");
            $this->assertStringContainsString('JE-20260909-0003', $csv, "$which: the whole trail");
            $this->assertStringNotContainsString('JE-20260925-0100', $csv, "$which: another event rode along");
            $this->assertStringNotContainsString('JE-20260909-0009', $csv, "$which: a non-catering entry rode along");
            // And the two appended columns carry the event, so the spreadsheet is self-explaining.
            $this->assertStringContainsString('Event #', $csv, "$which: header");
            $this->assertStringContainsString(self::EVENT, $csv, "$which: the event in the rows");
            $this->assertStringContainsString(self::CUSTOMER, $csv, "$which: the customer in the rows");
        }
    }

    /** Acceptance 10 — a void must not drop a receipt out of its event's trail. */
    public function test_a_reversal_stays_under_the_same_event(): void
    {
        // JournalService::reverse() writes <type>_reversal with the SAME source_id.
        $this->entry('JE-20260909-0004', 'catering_advance_reversal', $this->advanceIds[0], 'REV', [
            ['1300', 50000, 0], ['2100', 0, 50000],
        ]);

        $this->assertSame(
            ['JE-20260909-0001', 'JE-20260909-0002', 'JE-20260909-0003', 'JE-20260909-0004'],
            $this->jeListed(['event_no' => self::EVENT]),
            'the reversal belongs to the event just as much as the receipt it undid'
        );
        $this->assertContains('JE-20260909-0004', $this->glEntryNos(['event_no' => self::EVENT]));
    }

    /**
     * Acceptance 5 — the lookup is batched, so the page costs the same whatever it holds.
     *
     * A per-row lookup is the obvious way to write this and would be invisible on a fixture of
     * five rows; the JE list loads up to 500 entries and the ledger up to 5,000 lines.
     */
    public function test_neither_screen_queries_more_as_rows_grow(): void
    {
        $count = function (callable $render): int {
            $conn = DB::connection('tenant');
            $conn->flushQueryLog();
            $conn->enableQueryLog();
            $render();
            $n = count($conn->getQueryLog());
            $conn->disableQueryLog();

            return $n;
        };

        $je = fn () => $this->jeView([])->getData()['eventFor'];
        $gl = fn () => $this->glEntryNos([]);

        $jeSmall = $count($je);
        $glSmall = $count($gl);

        // Twenty more catering receipts spread over ten more events.
        for ($i = 1; $i <= 10; $i++) {
            $eventId = $this->makeEvent(sprintf('EV-20261001-%04d', $i), 'customer ' . $i);
            foreach ([1, 2] as $k) {
                $this->entry(sprintf('JE-20261001-%04d%d', $i, $k), 'catering_advance',
                    $this->makeAdvance($eventId), 'ULID' . $i . $k, [['1300', 0, 100], ['2100', 100, 0]]);
            }
        }

        $this->assertGreaterThan(20, count($this->jeListed([])), 'the bigger page really is bigger');
        $this->assertSame($jeSmall, $count($je), 'Journal Entries list: query count must stay flat');
        $this->assertSame($glSmall, $count($gl), 'General Ledger: query count must stay flat');
    }
}
