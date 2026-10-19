<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Finance\JournalEntryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * JOURNAL-SOURCE-MULTI-1 — the Source filter takes several sources at once.
 *
 * It was a one-at-a-time dropdown, so a question as ordinary as "what did the suppliers cost us"
 * — supplier payments AND purchase bills — could only ever be half answered.
 *
 * Two things matter beyond the obvious. Old links carry ?source_type=supplier_payment as a plain
 * string and must keep working, because bookmarks and shared URLs already exist. And the
 * line-level CSV on this same screen used to ignore the filter completely; harmless while nobody
 * reached for an awkward dropdown, a trap once a tick-list invites use.
 */
class JournalSourceMultiSelectMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['journal_lines', 'journal_entries', 'accounts']);

        $accountId = DB::connection('tenant')->table('accounts')->insertGetId([
            'code' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability',
            'normal_balance' => 'credit', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([
            ['JE-1', 'supplier_payment'],
            ['JE-2', 'supplier_payment'],
            ['JE-3', 'purchase_bill'],
            ['JE-4', 'sales_order_paid'],
            ['JE-5', 'expense_voucher'],
        ] as [$no, $source]) {
            $entryId = DB::connection('tenant')->table('journal_entries')->insertGetId([
                'entry_no' => $no, 'entry_date' => now()->toDateString(), 'status' => 'posted',
                'source_type' => $source, 'description' => $no . ' ' . $source,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // Each entry needs a LINE as well: the line-level CSV reads journal_lines, so an
            // entry-only fixture would leave that export empty and the guard would pass on
            // nothing at all.
            DB::connection('tenant')->table('journal_lines')->insert([
                'journal_entry_id' => $entryId, 'account_id' => $accountId,
                'description' => $no . ' line', 'debit' => 100, 'credit' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        view()->share('errors', new ViewErrorBag);
    }

    /** The entry numbers the screen actually lists for these filter params. */
    private function listed(array $params): array
    {
        $view = app(JournalEntryController::class)->index(Request::create('/finance/journal-entries', 'GET', $params));

        return collect($view->getData()['entries'])->pluck('entry_no')->sort()->values()->all();
    }

    public function test_several_sources_can_be_ticked_at_once(): void
    {
        // The whole point: supplier payments AND purchase bills in one answer.
        $this->assertSame(['JE-1', 'JE-2', 'JE-3'],
            $this->listed(['source_type' => ['supplier_payment', 'purchase_bill']]));
    }

    public function test_an_old_single_value_link_still_works(): void
    {
        // ?source_type=supplier_payment — every bookmark and shared URL already out there.
        $this->assertSame(['JE-1', 'JE-2'], $this->listed(['source_type' => 'supplier_payment']));
    }

    public function test_ticking_nothing_means_every_source(): void
    {
        $all = ['JE-1', 'JE-2', 'JE-3', 'JE-4', 'JE-5'];

        $this->assertSame($all, $this->listed([]), 'no filter at all');
        $this->assertSame($all, $this->listed(['source_type' => []]), 'an empty tick-list');
        // A form can post an empty string; that is "all", not "entries whose source is blank".
        $this->assertSame($all, $this->listed(['source_type' => '']));
        $this->assertSame($all, $this->listed(['source_type' => ['', null]]));
    }

    public function test_one_tick_narrows_to_exactly_that_source(): void
    {
        $this->assertSame(['JE-4'], $this->listed(['source_type' => ['sales_order_paid']]));
    }

    public function test_the_screen_remembers_what_was_ticked(): void
    {
        $view = app(JournalEntryController::class)->index(
            Request::create('/finance/journal-entries', 'GET', ['source_type' => ['purchase_bill', 'expense_voucher']])
        );

        // Handed back as an ARRAY whatever arrived, so the blade can tick the boxes again without
        // caring how the request was shaped.
        $this->assertSame(['purchase_bill', 'expense_voucher'], $view->getData()['filters']['source_type']);

        $html = $view->render();
        $this->assertStringContainsString('name="source_type[]"', $html, 'it is a tick-list now');
        $this->assertStringContainsString('(2 chosen)', $html, 'and it says how many');
    }

    public function test_the_line_level_csv_obeys_the_same_filter(): void
    {
        // This export ignored the Source filter entirely. A filter that applies to the screen and
        // to one of the two export buttons beside it, but not the other, is worse than no filter.
        $csv = $this->csvFor(['source_type' => ['supplier_payment'], 'export_csv' => 'lines']);

        // The CSV writes the raw stored value (supplier_payment), not the screen's prettified
        // "supplier payment" — asserting the pretty one passed on a CSV that was simply empty.
        $this->assertStringContainsString('supplier_payment', $csv);
        $this->assertStringContainsString('JE-1', $csv, 'and it really has rows in it');
        $this->assertStringNotContainsString('sales_order_paid', $csv,
            'a source that was not ticked must not ride along in the export');
    }

    /** Run a CSV export and return what it streamed. */
    private function csvFor(array $params): string
    {
        $response = app(JournalEntryController::class)
            ->index(Request::create('/finance/journal-entries', 'GET', $params));

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
