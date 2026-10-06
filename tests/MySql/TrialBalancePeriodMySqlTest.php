<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Finance\TrialBalanceController;
use App\Services\Finance\FinancialExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\TenantFixtures;

/**
 * TRIAL-BALANCE-PERIOD-1 — From/To, and Opening · Debit · Credit · Balance per account.
 *
 * Kashif Kitchen's owner could not see a supplier's bills and payments on the Trial Balance: once
 * AB CHICKEN NEW was paid in full, 2100 netted to zero and the "as of" screen dropped the row. The
 * fixture is that shape — a 19,650 bill paid the same day on 3 Oct, a 22,500 bill on 6 Oct, an
 * opening cash balance from September.
 */
class TrialBalancePeriodMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private array $acc = [];
    private int $branchA;
    private int $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['journal_lines', 'journal_entries', 'accounts', 'branches']);
        $this->branchA = $this->makeBranch(['name' => 'Kitchen A']);
        $this->branchB = $this->makeBranch(['name' => 'Kitchen B']);

        // sort_order is deliberately upside down: an on-screen account gets 0, which put 6810 above 1110.
        foreach ([
            ['1110', 'Main Cash Drawer', 'asset', 'debit', 10],
            ['1400', 'Inventory Asset', 'asset', 'debit', 20],
            ['2100', 'Accounts Payable', 'liability', 'credit', 30],
            ['4100', 'Catering Revenue', 'revenue', 'credit', 40],
            ['6810', 'Site / Operating Expense', 'expense', 'debit', 0],
        ] as [$code, $name, $type, $normal, $sort]) {
            $this->acc[$code] = DB::table('accounts')->insertGetId([
                'code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normal,
                'sort_order' => $sort, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->entry('2026-09-09', [['1110', 451215, 0], ['4100', 0, 451215]]);          // opening for October
        $this->entry('2026-10-03', [['1400', 19650, 0], ['2100', 0, 19650]]);            // AB CHICKEN bill
        $this->entry('2026-10-03', [['2100', 19650, 0], ['1110', 0, 19650]]);            // ...paid the same day
        $this->entry('2026-10-04', [['6810', 2400, 0], ['1110', 0, 2400]], $this->branchB); // another kitchen
        $this->entry('2026-10-06', [['1400', 22500, 0], ['2100', 0, 22500]]);            // the bill still open
        $this->entry('2026-10-05', [['6810', 999, 0], ['1110', 0, 999]], null, 'draft');   // never posted

        view()->share('errors', new ViewErrorBag);
    }

    /** @param list<array{0:string,1:float,2:float}> $lines */
    private function entry(string $date, array $lines, ?int $branchId = null, string $status = 'posted'): void
    {
        $id = DB::table('journal_entries')->insertGetId([
            'entry_no' => 'JE-' . uniqid(), 'entry_date' => $date, 'status' => $status,
            'description' => 'fixture', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as [$code, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'journal_entry_id' => $id, 'account_id' => $this->acc[$code], 'branch_id' => $branchId,
                'debit' => $debit, 'credit' => $credit, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function page(array $params)
    {
        return app(TrialBalanceController::class)->index(Request::create('/finance/trial-balance', 'GET', $params));
    }

    /** Rows keyed by account code, as the controller hands them to the view. */
    private function rows(array $params): array
    {
        return collect($this->page($params)->getData()['rows'])->keyBy('code')->all();
    }

    private function service(): FinancialExportService
    {
        return app(FinancialExportService::class);
    }

    public function test_the_owners_complaint_a_paid_supplier_still_shows_its_movement(): void
    {
        // The old screen for that day: 2100 nets to zero and is simply not there.
        $old = collect($this->service()->trialBalance('2026-10-03')['rows'])->pluck('code')->all();
        $this->assertNotContains('2100', $old, 'the complaint, reproduced');

        $rows = $this->rows(['date_from' => '2026-10-03', 'date_to' => '2026-10-03']);
        $this->assertArrayHasKey('2100', $rows, 'now the row stays');
        $this->assertSame(19650.0, $rows['2100']['period_debit']);
        $this->assertSame(19650.0, $rows['2100']['period_credit']);
        $this->assertSame(0.0, $rows['2100']['closing_debit'] + $rows['2100']['closing_credit']);

        $html = $this->page(['date_from' => '2026-10-03', 'date_to' => '2026-10-03'])->render();
        $this->assertStringContainsString('19,650.00', $html);
    }

    public function test_one_day_opening_movement_and_balance(): void
    {
        $rows = $this->rows(['date_from' => '2026-10-06', 'date_to' => '2026-10-06']);

        $this->assertSame([0.0, 0.0, 0.0, 22500.0, 0.0, 22500.0], [
            $rows['2100']['opening_debit'], $rows['2100']['opening_credit'], $rows['2100']['period_debit'],
            $rows['2100']['period_credit'], $rows['2100']['closing_debit'], $rows['2100']['closing_credit'],
        ], '2100 — Opening 0.00 · Credit 22,500 · Balance 22,500 Cr');
        $this->assertSame([19650.0, 22500.0, 42150.0], [
            $rows['1400']['opening_debit'], $rows['1400']['period_debit'], $rows['1400']['closing_debit'],
        ], '1400 — Opening 19,650 Dr · Debit 22,500 · Balance 42,150 Dr');
        $this->assertSame(0.0, $rows['1110']['period_debit'] + $rows['1110']['period_credit'], '1110 had no movement that day');
        $this->assertSame(429165.0, $rows['1110']['closing_debit'], '…but still shows, by its opening/balance');

        $html = $this->page(['date_from' => '2026-10-06', 'date_to' => '2026-10-06'])->render();
        $this->assertStringContainsString('22,500.00 Cr', $html);
        $this->assertStringContainsString('42,150.00 Dr', $html);
    }

    public function test_the_closing_column_is_the_old_snapshot_for_any_to_date(): void
    {
        foreach (['2026-09-30', '2026-10-03', '2026-10-05', '2026-10-06'] as $to) {
            $old = collect($this->service()->trialBalance($to)['rows'])->keyBy('code');
            $new = collect($this->service()->trialBalancePeriod('2026-10-01', $to)['rows'])->keyBy('code')
                ->filter(fn ($r) => round($r['closing_debit'] + $r['closing_credit'], 4) > 0);

            $this->assertEqualsCanonicalizing($old->keys()->all(), $new->keys()->all(), "accounts with a balance at {$to}");
            foreach ($old as $code => $r) {
                $this->assertEqualsWithDelta($r['debit_balance'], $new[$code]['closing_debit'], 0.0001, "{$code} Dr at {$to}");
                $this->assertEqualsWithDelta($r['credit_balance'], $new[$code]['closing_credit'], 0.0001, "{$code} Cr at {$to}");
            }
        }
    }

    public function test_period_debits_equal_credits_and_drafts_stay_out(): void
    {
        $tb = $this->service()->trialBalancePeriod('2026-10-01', '2026-10-06');

        $this->assertSame($tb['totals']['period_debit'], $tb['totals']['period_credit']);
        $this->assertSame(0.0, $tb['difference'], 'the closing pair balances');
        $this->assertSame(2400.0, collect($tb['rows'])->firstWhere('code', '6810')['period_debit'],
            'the 999 draft is not in the books');
    }

    public function test_an_old_as_of_link_still_shows_what_it_used_to(): void
    {
        $view = $this->page(['as_of_date' => '2026-10-06']);

        $this->assertSame('2026-09-09', $view->getData()['from'], 'From = the first posting');
        $this->assertSame('2026-10-06', $view->getData()['to']);
        $old = $this->service()->trialBalance('2026-10-06');
        $this->assertSame($old['total_debit'], $view->getData()['totals']['closing_debit']);
        $this->assertSame($old['total_credit'], $view->getData()['totals']['closing_credit']);
    }

    public function test_the_default_period_is_this_month_to_today(): void
    {
        $view = $this->page([]);

        $this->assertSame(today()->startOfMonth()->format('Y-m-d'), $view->getData()['from']);
        $this->assertSame(today()->format('Y-m-d'), $view->getData()['to']);
    }

    public function test_rows_come_in_account_code_order(): void
    {
        $codes = collect($this->page(['date_from' => '2026-10-01', 'date_to' => '2026-10-06'])->getData()['rows'])->pluck('code')->map(fn ($c) => (string) $c)->all();

        $this->assertSame(['1110', '1400', '2100', '4100', '6810'], $codes, '1110 before 6810, whatever sort_order says');
    }

    public function test_a_branch_filter_keeps_null_branch_lines(): void
    {
        // BUG-053: company-wide lines carry no branch and must not vanish under a branch filter.
        $rows = $this->rows(['date_from' => '2026-10-01', 'date_to' => '2026-10-06', 'branch_ids' => [$this->branchA]]);

        $this->assertArrayHasKey('2100', $rows, 'null-branch lines kept');
        $this->assertArrayNotHasKey('6810', $rows, "Kitchen B's expense is not Kitchen A's");
    }

    public function test_the_csv_has_seven_columns_and_the_screens_numbers(): void
    {
        $response = $this->page(['date_from' => '2026-10-06', 'date_to' => '2026-10-06', 'export_csv' => 1]);
        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();

        $this->assertStringContainsString('Code,Account,Type,Opening,Debit,Credit,Balance', $csv);
        $this->assertStringContainsString('2100,"Accounts Payable",Liability,0.00,0.00,22500.00,"22500.00 Cr"', $csv);
        $this->assertStringContainsString('1400,"Inventory Asset",Asset,"19650.00 Dr",22500.00,0.00,"42150.00 Dr"', $csv);
    }

    public function test_the_account_links_to_its_general_ledger_for_the_same_dates(): void
    {
        $html = $this->page(['date_from' => '2026-10-03', 'date_to' => '2026-10-06'])->render();

        $this->assertStringContainsString(
            '/finance/general-ledger?account_id=' . $this->acc['2100'] . '&amp;date_from=2026-10-03&amp;date_to=2026-10-06', $html);
    }

    public function test_to_before_from_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->page(['date_from' => '2026-10-06', 'date_to' => '2026-10-01']);
    }

    public function test_the_financial_export_trial_balance_is_unchanged(): void
    {
        // FinancialExportController::sectionTrialBalance() reads this shape; it must not move.
        $tb = $this->service()->trialBalance('2026-10-06');

        $this->assertSame(['code', 'name', 'type', 'debit_balance', 'credit_balance'], array_keys($tb['rows'][0]));
        $this->assertSame(0.0, $tb['difference']);
    }

    public function test_the_query_count_does_not_grow_with_accounts(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->service()->trialBalancePeriod('2026-10-01', '2026-10-06');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $before = $count();
        for ($i = 1; $i <= 15; $i++) {
            $code = '68' . (50 + $i);
            $this->acc[$code] = DB::table('accounts')->insertGetId([
                'code' => $code, 'name' => 'Expense ' . $i, 'type' => 'expense', 'normal_balance' => 'debit',
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->entry('2026-10-02', [[$code, 10, 0], ['1110', 0, 10]]);
        }
        $this->assertSame($before, $count());
    }
}
