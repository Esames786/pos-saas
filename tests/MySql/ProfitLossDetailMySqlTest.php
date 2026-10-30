<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Finance\ProfitLossController;
use App\Models\Tenant\ExpenseVoucher;
use App\Models\Tenant\PurchaseBill;
use App\Services\Finance\JournalPostingService;
use App\Services\Finance\ProfitLossReport;
use App\Services\Finance\ProfitLossService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * PNL-DETAIL-1 + FINANCE-PDF-DOWNLOADS-1 (khatribiryani, 26 / 21 Oct) — the P&L shows what each expense is made of and
 * its parent heads, the same on the screen, the CSV and the PDF. Display only: every total is statement()'s.
 *
 * Real postings (JournalPostingService for vouchers and bills, journals as the stock and manual paths post them), read
 * through ProfitLossController.
 */
class ProfitLossDetailMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const OCT = ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'];

    private array $acc = [];
    private int $branchId;
    private int $otherBranchId;
    private int $cashId;
    private int $otherCashId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'supplier_ledgers', 'purchase_bills',
            'expense_voucher_lines', 'expense_vouchers', 'expense_categories', 'cash_bank_accounts', 'suppliers', 'accounts', 'branches',
        ]);
        $this->branchId = $this->makeBranch(['name' => 'Main']);
        $this->otherBranchId = $this->makeBranch(['name' => 'Second']);
        // code, name, type, normal balance, parent
        foreach ([
            ['1110', 'Main Cash', 'asset', 'debit', null], ['1400', 'Inventory Asset', 'asset', 'debit', null],
            ['2100', 'Accounts Payable', 'liability', 'credit', null],
            ['4100', 'Restaurant Sales', 'income', 'credit', null], ['4200', 'Sales Discounts', 'income', 'debit', null],
            ['5100', 'Product COGS', 'expense', 'debit', null],
            ['6000', 'General Expenses', 'expense', 'debit', null], ['6010', 'Marketing & Social Media', 'expense', 'debit', '6000'],
            ['6020', 'Staff Food & Welfare', 'expense', 'debit', '6000'], ['6200', 'Salaries & Wages', 'expense', 'debit', null],
            ['6300', 'Utilities', 'expense', 'debit', null], ['6500', 'Packaging Expense', 'expense', 'debit', null],
            ['6800', 'Miscellaneous Expense', 'expense', 'debit', null],   // the voucher posting's fallback head; every chart has it
        ] as [$c, $n, $t, $nb, $parent]) {
            $this->acc[$c] = DB::table('accounts')->insertGetId(['code' => $c, 'name' => $n, 'type' => $t, 'normal_balance' => $nb,
                'parent_id' => $parent ? $this->acc[$parent] : null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->cashId = DB::table('cash_bank_accounts')->insertGetId(['account_id' => $this->acc['1110'], 'branch_id' => $this->branchId, 'code' => 'CB-MAIN', 'name' => 'Main Cash Drawer', 'created_at' => now(), 'updated_at' => now()]);
        $this->otherCashId = DB::table('cash_bank_accounts')->insertGetId(['account_id' => $this->acc['1110'], 'branch_id' => $this->otherBranchId, 'code' => 'CB-2', 'name' => 'Second Drawer', 'created_at' => now(), 'updated_at' => now()]);
        $supplier = DB::table('suppliers')->insertGetId(['code' => 'SUP-PK', 'name' => 'Packing House', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->journal('2026-10-02', 'sale', [['1110', 100000, 0], ['4100', 0, 100000]]);
        $this->journal('2026-10-02', 'sale', [['4200', 500, 0], ['1110', 0, 500]]);
        $this->journal('2026-10-03', 'stock_to_cost', [['5100', 30000, 0], ['1400', 0, 30000]]);             // only a journal: no detail
        $this->voucher('2026-10-04', '6000', [['6000-03', 'Social Media', 4000]]);                           // 6000's own postings
        $this->voucher('2026-10-04', '6010', [['6010-01', 'Facebook ads', 2500]]);
        $this->voucher('2026-10-05', '6020', [['6020-01', 'Staff Food', 1500], ['6020-02', 'Staff Friday Special', 700]]);
        $this->voucher('2026-10-06', '6200', [['6200-04', 'Daily wages', 7000], ['6200-03', 'Commission', 1200]]);
        $this->voucher('2026-10-06', '6200', [['6200-09', 'Second branch wages', 999]], $this->otherBranchId, $this->otherCashId);
        $this->voucher('2026-10-07', '6300', [['6300-2', 'Electric Bill', 3000]]);
        $this->journal('2026-10-08', 'manual_journal', [['6300', 400, 0], ['1110', 0, 400]]);                // no category: "Other"
        $bill = PurchaseBill::create(['bill_no' => 'BILL-PK-1', 'supplier_id' => $supplier, 'branch_id' => $this->branchId, 'bill_date' => '2026-10-09',
            'subtotal' => 900, 'grand_total' => 900, 'balance_due' => 900, 'status' => 'posted']);
        $this->journal('2026-10-09', 'purchase_bill', [['6500', 900, 0], ['2100', 0, 900]], $bill->id);     // a bill on an expense account

        view()->share('errors', new ViewErrorBag);
    }

    private function journal(string $date, string $type, array $lines, ?int $sourceId = null): int
    {
        $id = DB::table('journal_entries')->insertGetId(['entry_no' => 'JE-' . uniqid(), 'entry_date' => $date, 'status' => 'posted',
            'source_type' => $type, 'source_id' => $sourceId ?? random_int(1000, 999999), 'description' => $type, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($lines as [$code, $dr, $cr]) {
            DB::table('journal_lines')->insert(['journal_entry_id' => $id, 'account_id' => $this->acc[$code], 'branch_id' => $this->branchId,
                'debit' => $dr, 'credit' => $cr, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    private function voucher(string $date, string $account, array $lines, ?int $branchId = null, ?int $cashId = null): void
    {
        $total = array_sum(array_column($lines, 2));
        $vid = DB::table('expense_vouchers')->insertGetId(['voucher_no' => 'EXP-' . uniqid(), 'branch_id' => $branchId ?? $this->branchId,
            'cash_bank_account_id' => $cashId ?? $this->cashId, 'expense_date' => $date, 'status' => 'posted', 'subtotal' => $total,
            'total_amount' => $total, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($lines as [$code, $name, $amount]) {
            $cat = DB::table('expense_categories')->insertGetId(['account_id' => $this->acc[$account], 'code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('expense_voucher_lines')->insert(['expense_voucher_id' => $vid, 'expense_category_id' => $cat, 'account_id' => $this->acc[$account],
                'amount' => $amount, 'line_total' => $amount, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(JournalPostingService::class)->postExpenseVoucher(ExpenseVoucher::on('tenant')->findOrFail($vid));
    }

    private function page(array $params = [])
    {
        return app(ProfitLossController::class)->index(Request::create('/finance/profit-loss', 'GET', $params + self::OCT));
    }

    private function report(array $params = []): array
    {
        return $this->page($params)->getData()['report'];
    }

    private function line(array $report, string $part, string $code, string $kind = 'account'): array
    {
        return collect($report['parts'][$part])->first(fn ($l) => $l['code'] === $code && $l['kind'] === $kind)
            ?? $this->fail("no {$kind} line {$code} in {$part}");
    }

    /** label => amount */
    private function detail(array $line): array
    {
        return array_map(fn ($v) => round($v, 2), array_column($line['detail'], 'amount', 'label'));
    }

    private function csv(array $params = []): array
    {
        $response = $this->page($params + ['export_csv' => 1]);
        ob_start();
        $response->sendContent();
        $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", (string) ob_get_clean()))));
        $at = array_search(['Section', 'Row', 'Level', 'Code', 'Account', 'Detail', 'Amount'], $rows, true);
        $this->assertNotFalse($at, 'the CSV has the Level and Detail columns');

        return array_slice($rows, $at + 1);
    }

    // ── R2: what an account is made of ───────────────────────────────────────────────────────────────────────

    public function test_categories_under_an_expense_account_add_up_and_net_profit_is_unchanged(): void
    {
        $report = $this->report();
        $this->assertSame(['6200-04 · Daily wages' => 7000.0, '6200-03 · Commission' => 1200.0, '6200-09 · Second branch wages' => 999.0],
            $this->detail($this->line($report, 'expenses', '6200')), 'largest first, in "code · name"');
        $this->assertEqualsWithDelta(9199, $this->line($report, 'expenses', '6200')['amount'], 0.001);

        $flat = app(ProfitLossService::class)->statement(self::OCT);
        $data = $this->page()->getData()['pl'];
        $this->assertSame($flat['net_profit'], $data['net_profit'], 'Net Profit is the flat statement\'s, to the paisa');
        $this->assertSame($flat['total_expenses'], $data['total_expenses']);
    }

    public function test_a_journal_part_is_other_a_bill_names_its_supplier_and_a_journal_only_account_shows_no_detail(): void
    {
        $report = $this->report();
        $this->assertSame(['6300-2 · Electric Bill' => 3000.0, 'Other' => 400.0], $this->detail($this->line($report, 'expenses', '6300')),
            'the manual journal has no category: "Other", last, and the lines still make 3,400');
        $this->assertSame(['Packing House' => 900.0], $this->detail($this->line($report, 'expenses', '6500')), 'a bill names its supplier');
        $this->assertSame([], $this->line($report, 'cost_of_sales', '5100')['detail'], 'one "Other" line repeating the account says nothing');
        foreach ($report['parts'] as $lines) {
            foreach ($lines as $l) {
                if ($l['detail']) {
                    $this->assertEqualsWithDelta($l['amount'], array_sum(array_column($l['detail'], 'amount')), 0.001, "{$l['code']}: lines add up");
                }
            }
        }
    }

    // ── R1: parents over children ────────────────────────────────────────────────────────────────────────────

    public function test_a_parent_is_a_head_equal_to_its_rows_and_the_section_total_is_unchanged(): void
    {
        $report = $this->report();
        $head = $this->line($report, 'expenses', '6000', 'head');
        $this->assertEqualsWithDelta(4000 + 2500 + 2200, $head['amount'], 0.001, '6000 = its own + 6010 + 6020');
        $direct = $this->line($report, 'expenses', '6000', 'direct');
        $this->assertSame(['General Expenses (direct)', 4000.0, 1], [$direct['name'], round($direct['amount'], 2), $direct['depth']]);
        $this->assertSame(1, $this->line($report, 'expenses', '6020')['depth'], 'children indented under it');
        $this->assertSame([], $head['detail'], 'never detail under a head');
        $this->assertSame(['6020-01 · Staff Food' => 1500.0, '6020-02 · Staff Friday Special' => 700.0], $this->detail($this->line($report, 'expenses', '6020')));

        $accounts = array_filter($report['parts']['expenses'], fn ($l) => $l['kind'] !== 'head');
        $this->assertEqualsWithDelta($this->page()->getData()['pl']['total_expenses'], array_sum(array_column($accounts, 'amount')), 0.001,
            'the section total is the sum of its account rows, as before');
    }

    public function test_detail_off_hides_the_lines_and_keeps_the_heads(): void
    {
        $report = $this->report(['detail' => '0']);
        $this->assertFalse($report['detail']);
        $this->assertSame([], array_merge(...array_map(fn ($l) => $l['detail'], array_merge(...array_values($report['parts'])))));
        $this->line($report, 'expenses', '6000', 'head');

        $html = $this->page(['detail' => '0'])->render();
        $this->assertStringNotContainsString('class="pl-part', $html);
        $this->assertDoesNotMatchRegularExpression('/id="pl-detail"\s+checked/', $html);
        $this->assertMatchesRegularExpression('/id="pl-detail"\s+checked/', $this->page()->render(), 'on by default');
    }

    // ── R3: the screen, the CSV and the PDF agree ────────────────────────────────────────────────────────────

    public function test_the_screen_shows_heads_detail_and_the_pdf_button(): void
    {
        $html = $this->page()->render();
        $this->assertMatchesRegularExpression('#data-kind="head" data-code="6000">\s*<td[^>]*>6000 — General Expenses</td><td class="text-end">8,700\.00</td>#', $html);
        $this->assertMatchesRegularExpression('#class="pl-part small text-muted" data-part-of="6200">\s*<td[^>]*>6200-04 · Daily wages</td>\s*<td class="text-end">7,000\.00</td>#', $html);
        $this->assertStringContainsString('6300 — Utilities</td><td class="text-end">3,400.00</td>', $html, 'an account row reads as it always has');
        $this->assertStringContainsString('id="pl-pdf"', $html);
        $this->assertStringContainsString('Less: 4200 — Sales Discounts', $html);
    }

    public function test_the_csv_carries_heads_detail_and_totals_and_its_account_rows_sum_to_each_section(): void
    {
        $rows = $this->csv();
        $pl = $this->page()->getData()['pl'];
        foreach (['Revenue' => 'gross_revenue', 'Cost of Sales' => 'total_cogs', 'Operating Expenses' => 'total_expenses', 'Less: Sales Discounts' => 'total_discounts'] as $section => $key) {
            $sum = array_sum(array_map(fn ($r) => (float) $r[6], array_filter($rows, fn ($r) => $r[0] === $section && $r[1] === 'Account')));
            $this->assertEqualsWithDelta($pl[$key], $sum, 0.001, "{$section}: a SUM over its Account rows is its total");
        }
        $this->assertContains(['Operating Expenses', 'Head', '0', '6000', 'General Expenses', '', '8700.00'], $rows);
        $this->assertContains(['Operating Expenses', 'Detail', '1', '6200', 'Salaries & Wages', '6200-04 · Daily wages', '7000.00'], $rows);
        $this->assertContains(['', 'Total', '', '', 'Net Profit / Loss', '', number_format($pl['net_profit'], 2, '.', '')], $rows);
    }

    public function test_the_pdf_has_the_screens_tree_detail_and_totals_and_downloads(): void
    {
        $response = $this->page(['format' => 'pdf']);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="profit-loss-2026-10-01-to-2026-10-31.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // The PDF's page, rendered from the same build the controller hands it.
        $pl = app(ProfitLossService::class)->statement(self::OCT);
        $html = view('tenant.finance.profit-loss.print', ['pl' => $pl, 'report' => app(ProfitLossReport::class)->build($pl, null, true),
            'labels' => ProfitLossController::PART_LABELS, 'branchLabel' => null, 'company' => 'Test Kitchen'])->render();
        foreach (['Net Revenue' => $pl['net_revenue'], 'Total Cost of Sales' => $pl['total_cogs'], 'Gross Profit' => $pl['gross_profit'],
                     'Total Operating Expenses' => $pl['total_expenses'], 'Net Profit' => $pl['net_profit']] as $label => $v) {
            $this->assertMatchesRegularExpression('#<td>' . $label . '</td><td class="r">' . preg_quote(number_format($v, 2), '#') . '</td>#', $html, $label);
        }
        $this->assertStringContainsString('For the period 1 October 2026 to 31 October 2026 · Amounts in PKR', $html);
        $this->assertStringContainsString('6200-04 · Daily wages', $html);
        $this->assertStringContainsString('(500.00)', $html, 'the discount in brackets');
        $this->assertStringContainsString('<thead>', $html, 'the heading repeats on every page');
    }

    public function test_a_branch_filter_carries_into_the_screen_csv_and_pdf(): void
    {
        $only = ['branch_ids' => [$this->branchId]];
        $this->assertSame(['6200-04 · Daily wages' => 7000.0, '6200-03 · Commission' => 1200.0], $this->detail($this->line($this->report($only), 'expenses', '6200')),
            'the second branch\'s voucher is not in the Main branch\'s P&L');
        $this->assertNotContains('6200-09 · Second branch wages', array_column($this->csv($only), 5));
        $pdf = $this->page($only + ['format' => 'pdf']);
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_the_pdf_is_the_same_route_so_the_same_access(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'tenant.finance.profit-loss.index');
        $this->assertNotNull($route);
        $this->assertContains('route.permission', $route->gatherMiddleware(), 'whoever may open the P&L may download it; nobody else');
        $this->assertNull(collect(Route::getRoutes())->first(fn ($r) => str_contains((string) $r->getName(), 'profit-loss') && str_contains((string) $r->getName(), 'pdf')),
            'no separate PDF route and no new permission');
    }
}
