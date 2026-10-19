<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Finance\ExpenseVoucherController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * EXPENSE-LIST-CATEGORY-FILTER-1 — "how much went to 6810-2 Supervisor Fee from 1 to 10 Oct?"
 *
 * The Expenses list had no dates, no category and no total, so no screen could answer that.
 * Driven through the real controller. The fixture mirrors kashifkitchen on 2 Oct: account 6810
 * holds three sub-categories, one voucher splits across two categories, and there are void and
 * draft vouchers that must stay out of every total.
 */
class ExpenseListCategoryFilterMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private array $acc = [];
    private array $cat = [];
    private int $branchId;
    private int $cashId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'expense_voucher_lines', 'expense_vouchers', 'expense_categories',
            'cash_bank_accounts', 'accounts', 'branches',
        ]);

        $this->branchId = $this->makeBranch();
        foreach ([
            '1000' => 'Cash', '6810' => 'Site / Operating Expense',
            '6820' => 'Office Expense', '6840' => 'Marketing Expense',
        ] as $code => $name) {
            $this->acc[$code] = DB::table('accounts')->insertGetId([
                'code' => $code, 'name' => $name, 'type' => $code === '1000' ? 'asset' : 'expense',
                'normal_balance' => 'debit', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->cashId = DB::table('cash_bank_accounts')->insertGetId([
            'account_id' => $this->acc['1000'], 'code' => 'CASH', 'name' => 'Cash in hand',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([
            '6810-1' => ['6810', 'Site / Operating'], '6810-2' => ['6810', 'Supervisor Fee'],
            '6810-3' => ['6810', 'Vegetables'], '6820-1' => ['6820', 'Office & Stationery'],
            '6840-1' => ['6840', 'Marketing'],
        ] as $code => [$account, $name]) {
            $this->cat[$code] = DB::table('expense_categories')->insertGetId([
                'account_id' => $this->acc[$account], 'code' => $code, 'name' => $name,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->voucher('EXP-20261002-0001', 'posted', [['6810-1', 1890], ['6810-3', 1000]]);
        $this->voucher('EXP-20261002-0002', 'void', [['6810-1', 5000]]);
        $this->voucher('EXP-20261002-0003', 'posted', [['6810-2', 4800]], 'Shakeel');
        $this->voucher('EXP-20261002-0004', 'void', [['6820-1', 11920]]);
        $this->voucher('EXP-20261002-0005', 'draft', [['6810-2', 999]]);
        $this->voucher('EXP-20261002-0006', 'posted', [['6820-1', 500], ['6840-1', 11920]]);
        $this->voucher('EXP-20261002-0010', 'posted', [['6810-1', 2400]]);
        // Outside 1-10 Oct: in the list without dates, never inside a dated answer.
        $this->voucher('EXP-20261015-0001', 'posted', [['6810-2', 300]], null, '2026-10-15');

        view()->share('errors', new ViewErrorBag);
    }

    /** @param list<array{0:string,1:float}> $lines */
    private function voucher(string $no, string $status, array $lines, ?string $payee = null, string $date = '2026-10-02'): int
    {
        $total = array_sum(array_column($lines, 1));
        $id = DB::table('expense_vouchers')->insertGetId([
            'voucher_no' => $no, 'branch_id' => $this->branchId, 'cash_bank_account_id' => $this->cashId,
            'expense_date' => $date, 'payee_name' => $payee, 'status' => $status,
            'subtotal' => $total, 'total_amount' => $total, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $i => [$code, $amount]) {
            DB::table('expense_voucher_lines')->insert([
                'expense_voucher_id' => $id, 'expense_category_id' => $this->cat[$code],
                // What syncLines() does on save: copy the category's account onto the line.
                'account_id' => DB::table('expense_categories')->where('id', $this->cat[$code])->value('account_id'),
                'amount' => $amount, 'line_total' => $amount, 'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function page(array $params)
    {
        return app(ExpenseVoucherController::class)->index(Request::create('/finance/expenses', 'GET', $params));
    }

    private function listed(array $params): array
    {
        return collect($this->page($params)->getData()['vouchers'])->pluck('voucher_no')->sort()->values()->all();
    }

    private const OCT = ['date_from' => '2026-10-01', 'date_to' => '2026-10-10'];

    /** The owner's own question. */
    public function test_supervisor_fee_in_the_first_ten_days(): void
    {
        $view = $this->page(self::OCT + ['expense_category_id' => $this->cat['6810-2']]);

        // The 15 Oct voucher (300) is outside the dates. The draft 0005 (999) is listed under Status
        // All but never counted; with Status = Posted the answer is the single voucher.
        $this->assertSame(['EXP-20261002-0003', 'EXP-20261002-0005'],
            collect($view->getData()['vouchers'])->pluck('voucher_no')->sort()->values()->all());
        $this->assertSame(['EXP-20261002-0003'], $this->listed(self::OCT + ['status' => 'posted', 'expense_category_id' => $this->cat['6810-2']]));
        $this->assertSame(4800.0, $view->getData()['postedTotal']);
    }

    /** A Category (account) gathers every sub-category under it, counting line amounts. */
    public function test_a_category_adds_up_all_its_sub_categories(): void
    {
        $view = $this->page(self::OCT + ['account_id' => $this->acc['6810']]);

        // Void 0002 and draft 0005 are listed (Status = All) — they are kept out of the TOTAL only.
        $this->assertSame(
            ['EXP-20261002-0001', 'EXP-20261002-0002', 'EXP-20261002-0003', 'EXP-20261002-0005', 'EXP-20261002-0010'],
            $this->listed(self::OCT + ['account_id' => $this->acc['6810']])
        );
        $this->assertSame(2890.0 + 4800 + 2400, $view->getData()['postedTotal']);
    }

    /** Filtered by one category, a split voucher counts only its matching line. */
    public function test_a_split_voucher_counts_only_the_matching_line(): void
    {
        $view = $this->page(['expense_category_id' => $this->cat['6840-1']]);
        $row = collect($view->getData()['vouchers'])->firstWhere('voucher_no', 'EXP-20261002-0006');

        $this->assertSame(11920.0, (float) $row->matched_amount, 'the marketing share');
        $this->assertSame(12420.0, (float) $row->total_amount, 'and the voucher total beside it');
        $this->assertSame(11920.0, $view->getData()['postedTotal']);

        $html = $view->render();
        $this->assertStringContainsString('11,920.00', $html);
        $this->assertStringContainsString('12,420.00', $html);
        $this->assertStringContainsString('>Matched</th>', $html);
    }

    /** Void and draft stay visible but never inside the total, and the counts say so. */
    public function test_void_is_listed_but_not_totalled(): void
    {
        $view = $this->page(['expense_category_id' => $this->cat['6820-1']]);

        $this->assertSame(['EXP-20261002-0004', 'EXP-20261002-0006'],
            collect($view->getData()['vouchers'])->pluck('voucher_no')->sort()->values()->all());
        $this->assertSame(500.0, $view->getData()['postedTotal'], 'the void 11,920 must not count');
        $this->assertStringContainsString('1 posted · 0 draft · 1 void match these filters', $view->render());
    }

    public function test_no_category_filter_totals_every_posted_voucher_in_the_dates(): void
    {
        $view = $this->page(self::OCT);

        $this->assertSame(2890.0 + 4800 + 12420 + 2400, $view->getData()['postedTotal']);
        $this->assertContains('EXP-20261002-0002', $this->listed(self::OCT), 'void stays in the list');
        $this->assertNotContains('EXP-20261015-0001', $this->listed(self::OCT), 'outside the dates');
        $this->assertStringNotContainsString('>Matched</th>', $view->render(),
            'without a category filter the matched amount IS the total, so no extra column');
    }

    /** Both columns as code — name, one per line; the split voucher shows both of its own. */
    public function test_every_row_shows_category_and_sub_category(): void
    {
        $html = $this->page(['q' => 'EXP-20261002-0006'])->render();

        foreach (['6820 — Office Expense', '6840 — Marketing Expense',
                  '6820-1 — Office &amp; Stationery', '6840-1 — Marketing'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    /** Re-linking a category later must not move old vouchers: they stay where their journal went. */
    public function test_a_relinked_category_keeps_its_old_vouchers_under_the_posted_account(): void
    {
        DB::table('expense_categories')->where('id', $this->cat['6810-2'])
            ->update(['account_id' => $this->acc['6820']]);

        $this->assertContains('EXP-20261002-0003', $this->listed(['account_id' => $this->acc['6810']]));
        $this->assertNotContains('EXP-20261002-0003', $this->listed(['account_id' => $this->acc['6820']]));
        $this->assertStringContainsString('6810 — Site / Operating Expense',
            $this->page(['q' => 'EXP-20261002-0003'])->render());
    }

    /** The list stops at 500; the total must not. */
    public function test_the_total_covers_more_than_the_500_rows_shown(): void
    {
        $now = now();
        $vouchers = [];
        for ($i = 1; $i <= 505; $i++) {
            $vouchers[] = [
                'voucher_no' => sprintf('EXP-BULK-%04d', $i), 'branch_id' => $this->branchId,
                'cash_bank_account_id' => $this->cashId, 'expense_date' => '2026-09-01', 'status' => 'posted',
                'subtotal' => 10, 'total_amount' => 10, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('expense_vouchers')->insert($vouchers);
        DB::statement(
            'INSERT INTO expense_voucher_lines (expense_voucher_id, expense_category_id, account_id, amount, line_total, sort_order, created_at, updated_at)
             SELECT id, ?, ?, 10, 10, 0, NOW(), NOW() FROM expense_vouchers WHERE voucher_no LIKE ?',
            [$this->cat['6840-1'], $this->acc['6840'], 'EXP-BULK-%']
        );

        $view = $this->page(['date_to' => '2026-09-30', 'expense_category_id' => $this->cat['6840-1']]);

        $this->assertCount(500, $view->getData()['vouchers'], 'the page still stops at 500');
        $this->assertSame(5050.0, $view->getData()['postedTotal'], 'but the total counts all 505');
        $this->assertStringContainsString('505 posted', $view->render());
    }

    public function test_the_old_filters_still_work_and_everything_is_remembered(): void
    {
        $this->assertSame(['EXP-20261002-0002', 'EXP-20261002-0004'], $this->listed(['status' => 'void']));
        $this->assertSame(['EXP-20261002-0003'], $this->listed(['q' => 'Shakeel']));

        $params = self::OCT + ['status' => 'posted', 'q' => 'EXP', 'account_id' => $this->acc['6810'],
            'expense_category_id' => $this->cat['6810-2'], 'branch_id' => $this->branchId];
        $html = $this->page($params)->render();

        $this->assertStringContainsString('name="date_from" class="form-control" value="2026-10-01"', $html);
        $this->assertStringContainsString('name="date_to" class="form-control" value="2026-10-10"', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $this->acc['6810'] . '" selected>6810/', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $this->cat['6810-2'] . '" selected>6810-2/', $html);
    }

    /** A stale or hand-typed id is ignored, not an error and not "match nothing". */
    public function test_an_unknown_category_id_is_ignored(): void
    {
        $this->assertSame($this->listed([]), $this->listed(['account_id' => 999999, 'expense_category_id' => 999999]));
    }

    /** Inactive categories stay in the dropdown — old vouchers still carry them. */
    public function test_inactive_sub_categories_stay_selectable(): void
    {
        DB::table('expense_categories')->where('id', $this->cat['6810-3'])->update(['is_active' => 0]);

        $this->assertStringContainsString('6810-3 — Vegetables (inactive)', $this->page([])->render());
    }

    public function test_query_count_does_not_grow_with_rows(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->page(['expense_category_id' => $this->cat['6810-1']])->render();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $small = $count();
        for ($i = 1; $i <= 25; $i++) {
            $this->voucher(sprintf('EXP-MORE-%04d', $i), 'posted', [['6810-1', 10], ['6840-1', 5]]);
        }
        $this->assertSame($small, $count());
    }

    /**
     * Tax included: posting debits each line's account with line_total (amount + tax), so the
     * filtered total must say the same thing the General Ledger says for that account.
     */
    public function test_a_taxed_line_counts_what_the_ledger_was_debited(): void
    {
        $id = $this->voucher('EXP-20261003-0001', 'posted', [['6820-1', 1000]], null, '2026-10-03');
        DB::table('expense_voucher_lines')->where('expense_voucher_id', $id)
            ->update(['tax_amount' => 170, 'line_total' => 1170]);
        DB::table('expense_vouchers')->where('id', $id)->update(['tax_amount' => 170, 'total_amount' => 1170]);

        $view = $this->page(['date_from' => '2026-10-03', 'expense_category_id' => $this->cat['6820-1']]);

        $this->assertSame(1170.0, $view->getData()['postedTotal']);
        $this->assertSame(1170.0, (float) collect($view->getData()['vouchers'])->first()->matched_amount);
    }
}
