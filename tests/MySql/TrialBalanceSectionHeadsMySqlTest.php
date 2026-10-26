<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Finance\TrialBalanceController;
use App\Services\Finance\ProfitLossService;
use App\Services\Finance\TrialBalanceLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * TB-SECTION-HEADS-1 — the Trial Balance grouped under the heads of the books, the way an accountant
 * reads it: Cost of Sales with 5000 → 5100 → 5100-1 / 5100-2, a subtotal per head. Display only: the
 * grand totals and the difference are the flat list's, to the paisa.
 */
class TrialBalanceSectionHeadsMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private array $acc = [];
    private int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['journal_lines', 'journal_entries', 'accounts', 'branches', 'suppliers']);
        $this->makeBranch(['name' => 'Main']);

        // code, name, type, normal balance, parent code (null = top level)
        foreach ([
            ['1110', 'Main Cash', 'asset', 'debit', null],
            ['2100', 'Accounts Payable', 'liability', 'credit', null],
            ['2100-2', 'Payable - Kitchen', 'liability', 'credit', '2100'],
            ['2900', 'Loan (wrongly under cash)', 'liability', 'credit', '1110'],
            ['3000', 'Owner Capital', 'equity', 'credit', null],
            ['4100', 'Sales', 'income', 'credit', null],
            ['4200', 'Sales Discounts', 'income', 'debit', null],
            ['5000', 'Cost of Goods Sold', 'expense', 'debit', null],
            ['5100', 'Product COGS', 'expense', 'debit', '5000'],
            ['5100-1', 'Beverages', 'expense', 'debit', '5100'],
            ['5100-2', 'Packing Material', 'expense', 'debit', '5100'],
            ['5900', 'Freight (wrongly under rent)', 'expense', 'debit', '6100'],
            ['6100', 'Rent', 'expense', 'debit', null],
            ['6900', 'Points at itself', 'expense', 'debit', '6900'],
        ] as [$code, $name, $type, $normal, $parent]) {
            $this->acc[$code] = DB::table('accounts')->insertGetId([
                'code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normal,
                'parent_id' => $parent && isset($this->acc[$parent]) ? $this->acc[$parent] : null,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('accounts')->where('id', $this->acc['6900'])->update(['parent_id' => $this->acc['6900']]);
        DB::table('accounts')->where('id', $this->acc['5900'])->update(['parent_id' => $this->acc['6100']]);

        $this->supplierId = DB::table('suppliers')->insertGetId(['code' => 'S-1', 'name' => 'Fresh Drinks Co', 'status' => 'active',
            'opening_balance' => 0, 'current_balance' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->entry('2026-09-30', [['1110', 50000, 0], ['3000', 0, 50000]]);            // opening for October
        $this->entry('2026-10-02', [['1110', 10000, 0], ['4100', 0, 10000]]);
        $this->entry('2026-10-02', [['4200', 500, 0], ['1110', 0, 500]]);
        $this->entry('2026-10-03', [['5100-1', 3000, 0], ['2100', 0, 3000, $this->supplierId]]);
        $this->entry('2026-10-03', [['5100-2', 1200, 0], ['2100-2', 0, 1200]]);
        $this->entry('2026-10-04', [['6100', 2000, 0], ['1110', 0, 2000]]);
        $this->entry('2026-10-04', [['6900', 100, 0], ['1110', 0, 100]]);
        $this->entry('2026-10-05', [['5900', 300, 0], ['1110', 0, 300]]);
        $this->entry('2026-10-05', [['1110', 5000, 0], ['2900', 0, 5000]]);

        view()->share('errors', new ViewErrorBag);
    }

    /** @param list<array{0:string,1:float,2:float,3?:int}> $lines */
    private function entry(string $date, array $lines): void
    {
        $id = DB::table('journal_entries')->insertGetId([
            'entry_no' => 'JE-' . uniqid(), 'entry_date' => $date, 'status' => 'posted',
            'description' => 'fixture', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $l) {
            DB::table('journal_lines')->insert([
                'journal_entry_id' => $id, 'account_id' => $this->acc[$l[0]], 'debit' => $l[1], 'credit' => $l[2],
                'supplier_id' => $l[3] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private const OCT = ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'];

    private function page(array $params = [])
    {
        return app(TrialBalanceController::class)->index(Request::create('/finance/trial-balance', 'GET', $params + self::OCT));
    }

    private function csv(array $params = []): string
    {
        $response = $this->page($params + ['export_csv' => 1]);
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /** @return array<string, array<string,mixed>> section key => section */
    private function sections(): array
    {
        return collect($this->page()->getData()['sections'])->keyBy('key')->all();
    }

    private function line(array $section, string $code, ?string $kind = null): array
    {
        return collect($section['lines'])->first(fn ($l) => $l['code'] === $code && ($kind === null || $l['kind'] === $kind))
            ?? $this->fail("no {$kind} line {$code} in {$section['label']}");
    }

    public function test_cost_of_sales_shows_its_parents_with_the_sum_of_their_children(): void
    {
        $cos = $this->sections()['cost_of_sales'];

        $this->assertSame('Cost of Sales', $cos['label']);
        $this->assertSame(['5000', '5100', '5100-1', '5100-2', '5900'], array_column($cos['lines'], 'code'), 'parents first, children beneath, by code');
        $this->assertSame(['head', 'head', 'account', 'account', 'account'], array_column($cos['lines'], 'kind'));
        $this->assertSame([0, 1, 2, 2, 0], array_column($cos['lines'], 'depth'));
        $this->assertSame(4200.0, $this->line($cos, '5000')['closing_debit'], '5000 = 5100-1 + 5100-2, though it has no postings');
        $this->assertSame(4200.0, $this->line($cos, '5100')['closing_debit']);
        $this->assertSame(4500.0, $cos['subtotal']['closing_debit'], 'Total Cost of Sales');

        $html = $this->page()->render();
        $this->assertStringContainsString('Total Cost of Sales', $html);
        $this->assertMatchesRegularExpression('/class="tb-head".*?5000.*?Cost of Goods Sold.*?4,200\.00 Dr/s', $html);
    }

    /** FINANCE-PDF-DOWNLOADS-1: the PDF's head total adds its ACCOUNT rows — 5000 and 5100 are summaries, not counted again. */
    public function test_the_pdf_head_totals_count_each_account_once_under_nested_heads(): void
    {
        $totals = TrialBalanceController::sectionTotals($this->page()->getData()['sections']);
        $this->assertSame(4500.0, $totals['cost_of_sales']['period_debit'], '3,000 + 1,200 + 300 — not 4,200 more for 5000 and 5100');
        $this->assertSame(4500.0, $totals['cost_of_sales']['closing_debit']);
    }

    public function test_an_operating_expense_is_not_cost_of_sales_and_the_pnl_agrees(): void
    {
        $s = $this->sections();
        $this->assertSame(['6100', '6900'], array_column($s['operating_expenses']['lines'], 'code'));

        // The P&L and the TB use one rule (AccountSections): the same accounts land in Cost of Sales.
        $pnl = app(ProfitLossService::class)->statement(self::OCT);
        $tbCos = collect($s['cost_of_sales']['lines'])->where('kind', 'account')->pluck('code')->sort()->values()->all();
        $this->assertSame($tbCos, collect($pnl['cogs_rows'])->pluck('code')->map(fn ($c) => (string) $c)->sort()->values()->all());
        $this->assertEqualsWithDelta($pnl['total_cogs'], $s['cost_of_sales']['subtotal']['period_debit'], 0.001);
    }

    public function test_a_parent_with_its_own_postings_shows_a_direct_row_and_the_head_is_the_sum(): void
    {
        $liab = $this->sections()['liabilities'];

        $this->assertSame(['head', 'direct', 'account', 'account'], array_column($liab['lines'], 'kind'));
        $this->assertSame('Accounts Payable (direct)', $liab['lines'][1]['name']);
        $this->assertSame(3000.0, $liab['lines'][1]['closing_credit'], "2100's own bill");
        $this->assertSame(1200.0, $this->line($liab, '2100-2')['closing_credit']);
        $this->assertSame(4200.0, $this->line($liab, '2100', 'head')['closing_credit'], 'head = (direct) + child');
    }

    public function test_grand_totals_and_difference_are_exactly_the_flat_lists(): void
    {
        $grouped = $this->page()->getData();
        $flat = $this->page(['group' => 0])->getData();

        $this->assertSame($flat['totals'], $grouped['totals']);
        $this->assertSame($flat['difference'], $grouped['difference']);
        $this->assertSame([], $flat['sections'], 'group=0 is the flat list');

        // Every account appears exactly once as an account row, so they add up to the grand totals.
        $accountLines = collect($grouped['sections'])->flatMap(fn ($s) => $s['lines'])->where('kind', '!=', 'head');
        $this->assertCount(count($flat['rows']), $accountLines);
        $this->assertEqualsWithDelta($flat['totals']['period_debit'], $accountLines->sum('period_debit'), 0.0001);
        $this->assertEqualsWithDelta($flat['totals']['period_credit'], $accountLines->sum('period_credit'), 0.0001);
        $this->assertEqualsWithDelta($flat['totals']['closing_debit'] - $flat['totals']['closing_credit'],
            $accountLines->sum('closing_debit') - $accountLines->sum('closing_credit'), 0.0001);
    }

    public function test_the_csv_and_the_print_carry_the_same_sections(): void
    {
        $csv = $this->csv();
        $this->assertStringContainsString('Section,Row,Level,Code,Account,Opening,Debit,Credit,Balance', $csv);
        $this->assertStringContainsString('"Cost of Sales",Head,0,5000,"Cost of Goods Sold",0.00,4200.00,0.00,"4200.00 Dr"', $csv);
        $this->assertStringContainsString('"Cost of Sales",Subtotal,0,,"Total Cost of Sales",0.00,4500.00,0.00,"4500.00 Dr"', $csv);

        // A spreadsheet SUM over the Account rows is the grand total.
        $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));
        $head = array_search(['Section', 'Row', 'Level', 'Code', 'Account', 'Opening', 'Debit', 'Credit', 'Balance'], $rows, true);
        $this->assertNotFalse($head);
        $accounts = array_filter($rows, fn ($r) => ($r[1] ?? '') === 'Account');   // the header says "Row", never "Account"
        $this->assertCount(12, $accounts, 'every account with postings, once (5000 and 5100 have none)');
        $totals = $this->page()->getData()['totals'];
        $this->assertEqualsWithDelta($totals['period_debit'], array_sum(array_column($accounts, 6)), 0.001);
        $this->assertEqualsWithDelta($totals['period_credit'], array_sum(array_column($accounts, 7)), 0.001);

        // The print: rendered from the same sections the controller hands the PDF.
        $data = $this->page()->getData();
        $print = view('tenant.finance.trial-balance.print', [
            'rows' => $data['rows'], 'totals' => $data['totals'], 'difference' => $data['difference'], 'parties' => $data['parties'],
            'grouped' => true, 'sections' => $data['sections'], 'from' => '2026-10-01', 'to' => '2026-10-31', 'branchLabel' => 'All', 'company' => 'Test',
        ])->render();
        foreach (['Assets', 'Liabilities', 'Equity', 'Revenue', 'Cost of Sales', 'Operating Expenses'] as $label) {
            $this->assertStringContainsString('<tr class="sect"><th colspan="8">' . $label . '</th></tr>', $print);
        }
        $this->assertStringContainsString('Total Cost of Sales', $print);
        $pdf = $this->page(['format' => 'pdf']);
        $this->assertStringStartsWith('%PDF', $pdf->getContent(), 'the grouped PDF renders');
    }

    public function test_party_detail_still_shows_under_its_control_account(): void
    {
        $html = $this->page()->render();
        $this->assertMatchesRegularExpression('/Accounts Payable \(direct\).*?tb-party.*?Fresh Drinks Co/s', $html, 'the supplier under 2100');

        $this->assertMatchesRegularExpression('/"Accounts Payable \(direct\)".*\n"?Liabilities"?,Party,2,,"[^"\n]*Fresh Drinks Co/', $this->csv());
    }

    public function test_a_bad_tree_never_breaks_the_page(): void
    {
        $s = $this->sections();

        $this->assertSame(0, $this->line($s['operating_expenses'], '6900')['depth'], 'a parent that is itself → top level');
        $this->assertSame(0, $this->line($s['cost_of_sales'], '5900')['depth'], 'a Cost of Sales child of an operating expense → top level of Cost of Sales');
        $this->assertSame(0, $this->line($s['liabilities'], '2900')['depth'], 'a liability under an asset → top level of Liabilities');

        // A loop (A → B → A) is cut once, not followed forever.
        DB::table('accounts')->where('id', $this->acc['5100'])->update(['parent_id' => $this->acc['5100-1']]);
        $cos = collect(app(TrialBalanceLayout::class)->sections(app(\App\Services\Finance\FinancialExportService::class)->trialBalancePeriod('2026-10-01', '2026-10-31')['rows']))->keyBy('key')['cost_of_sales'];
        $this->assertEqualsWithDelta(4500.0, $cos['subtotal']['closing_debit'], 0.001, 'nothing counted twice or lost');
    }

    public function test_the_flat_list_is_one_untick_away(): void
    {
        $html = $this->page(['group' => 0])->render();
        $this->assertStringContainsString('<th scope="col">Type</th>', $html);
        $this->assertStringNotContainsString('Total Cost of Sales', $html);
        $this->assertStringContainsString('Code,Account,Type,Opening,Debit,Credit,Balance', $this->csv(['group' => 0]));

        $grouped = $this->page()->render();
        $this->assertStringNotContainsString('<th scope="col">Type</th>', $grouped);
        $this->assertMatchesRegularExpression('/id="tb-group"[^>]*checked/', $grouped, 'grouped is the default');
    }
}
