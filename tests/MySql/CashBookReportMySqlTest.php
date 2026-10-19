<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Reports\CashBookController;
use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Models\Tenant\User;
use App\Services\Reports\CashBookService;
use App\Services\Saas\TenantSubscriptionAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * CASH-BOOK-REPORT-1 — Reports → Cash Book: per cash drawer / bank, money in, money out and the
 * balance left, for any dates (asked for by Kashif Kitchen to check its cash register, 10 Oct).
 *
 * The book adds its balances up from its own entries. Cash here is stored at 999,999 on purpose —
 * kashifkitchen's Main Cash Drawer sits 502,500 above its rows, and the book must not repeat that.
 */
class CashBookReportMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const ROUTE = 'tenant.reports.cash-book.index';

    private int $cash;
    private int $bank;
    private int $petty;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['cash_bank_account_transactions', 'cash_bank_accounts', 'supplier_payments', 'suppliers',
            'expense_voucher_lines', 'expense_vouchers', 'expense_categories', 'catering_advances', 'catering_events',
            'journal_lines', 'journal_entries', 'branches', 'users']);
        $branch = $this->makeBranch(['status' => 'active']);
        $acc = fn (string $code, string $name, string $type, float $opening, float $stored) => DB::table('cash_bank_accounts')->insertGetId([
            'code' => $code, 'name' => $name, 'account_type' => $type, 'branch_id' => $branch, 'opening_balance' => $opening,
            'current_balance' => $stored, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->cash = $acc('CASH-MAIN', 'Main Cash Drawer', 'cash', 1000, 999999);
        $this->bank = $acc('BANK-MAIN', 'Main Bank Account', 'bank', 0, 5000);
        $this->petty = $acc('CASH-PETTY', 'Petty Cash', 'cash', 0, -700);

        $event = DB::table('catering_events')->insertGetId(['event_uuid' => (string) \Illuminate\Support\Str::ulid(), 'event_no' => 'EV-1', 'branch_id' => $branch,
            'customer_name' => 'Mr Ahmed', 'booking_date' => '2026-09-25', 'event_date' => '2026-10-20', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
        $advance = DB::table('catering_advances')->insertGetId(['advance_uuid' => (string) \Illuminate\Support\Str::ulid(), 'catering_event_id' => $event,
            'amount' => 2000, 'received_date' => '2026-10-01', 'created_at' => now(), 'updated_at' => now()]);
        $supplier = DB::table('suppliers')->insertGetId(['code' => 'S1', 'name' => 'Faisal Beef', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $payment = DB::table('supplier_payments')->insertGetId(['payment_no' => 'PAY-1', 'supplier_id' => $supplier, 'branch_id' => $branch,
            'payment_date' => '2026-10-02', 'amount' => 300, 'payment_method' => 'cash', 'created_at' => now(), 'updated_at' => now()]);
        $category = DB::table('expense_categories')->insertGetId(['code' => 'TRN', 'name' => 'Transport', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $voucher = DB::table('expense_vouchers')->insertGetId(['voucher_no' => 'EXP-1', 'branch_id' => $branch, 'cash_bank_account_id' => $this->cash, 'expense_date' => '2026-10-02',
            'payee_name' => 'Rickshaw', 'status' => 'posted', 'subtotal' => 200, 'total_amount' => 200, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('expense_voucher_lines')->insert(['expense_voucher_id' => $voucher, 'expense_category_id' => $category, 'description' => 'Kiraya',
            'amount' => 200, 'line_total' => 200, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $journal = DB::table('journal_entries')->insertGetId(['entry_no' => 'JE-1', 'entry_date' => '2026-10-01', 'source_type' => 'manual_journal',
            'description' => 'Bank deposit', 'status' => 'posted', 'total_debit' => 5000, 'total_credit' => 5000, 'created_at' => now(), 'updated_at' => now()]);

        $this->move($this->cash, '2026-09-30', 'in', 500, 'opening_balance', 'opening', null, 'Before the period');
        $this->move($this->cash, '2026-10-01', 'in', 2000, 'customer_payment', 'catering_advance', $advance, 'Catering receipt EV-1');
        $this->move($this->cash, '2026-10-02', 'out', 300, 'supplier_payment', 'supplier_payment', $payment, 'Supplier payment PAY-1');
        $this->move($this->cash, '2026-10-02', 'out', 200, 'expense_payment', 'expense_voucher', $voucher, 'Expense voucher EXP-1');
        $this->move($this->bank, '2026-10-01', 'in', 5000, 'manual_journal', 'manual_journal', $journal, 'Manual journal JE-1');
        $this->move($this->petty, '2026-10-03', 'out', 700, 'expense_payment', 'expense_voucher', $voucher, 'Expense voucher EXP-1');
        $this->move($this->cash, '2026-11-01', 'in', 9000, 'customer_payment', 'catering_advance', $advance, 'After the period');

        view()->share('errors', new ViewErrorBag);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant');
        parent::tearDown();
    }

    private function move(int $account, string $date, string $dir, float $amount, string $type, string $refType, ?int $refId, string $notes): void
    {
        DB::table('cash_bank_account_transactions')->insert(['cash_bank_account_id' => $account, 'transaction_date' => $date, 'direction' => $dir,
            'amount' => $amount, 'balance_after' => 0, 'transaction_type' => $type, 'reference_type' => $refType, 'reference_id' => $refId,
            'notes' => $notes, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function book(array $q = [])
    {
        return app(CashBookController::class)->index(
            Request::create('/reports/cash-book', 'GET', $q + ['date_from' => '2026-10-01', 'date_to' => '2026-10-31']),
            app(CashBookService::class));
    }

    public function test_each_account_opens_with_what_came_before_and_closes_from_its_own_entries(): void
    {
        $s = $this->book()->getData()['summary'];

        $this->assertSame(['opening' => 1500.0, 'in' => 2000.0, 'out' => 500.0, 'closing' => 3000.0],
            array_intersect_key($s[$this->cash], array_flip(['opening', 'in', 'out', 'closing'])),
            'opening 1,000 + 500 before the period; the stored 999,999 plays no part; November is outside');
        $this->assertSame(5000.0, $s[$this->bank]['closing']);
        $this->assertSame(-700.0, $s[$this->petty]['closing'], 'petty cash spent and never funded shows as it is');
        $this->assertSame(['opening' => 1500.0, 'in' => 7000.0, 'out' => 1200.0, 'closing' => 7300.0], $this->book()->getData()['totals']);
    }

    public function test_every_entry_says_who_and_carries_the_running_balance(): void
    {
        $rows = collect($this->book()->getData()['entries']->items())->where('cash_bank_account_id', $this->cash)->values();

        $this->assertSame([3500.0, 3200.0, 3000.0], $rows->pluck('balance')->all());
        $this->assertSame(['EV-1', 'PAY-1', 'EXP-1'], $rows->pluck('ref')->all());
        $this->assertSame(['Mr Ahmed', 'Faisal Beef', 'Rickshaw — Transport · Kiraya'], $rows->pluck('party')->all());
        $this->assertSame(['Customer receipt', 'Supplier payment', 'Expense'], $rows->pluck('type_label')->all());
    }

    public function test_showing_only_money_out_keeps_the_real_balance(): void
    {
        $rows = collect($this->book(['direction' => 'out', 'account_ids' => [$this->cash]])->getData()['entries']->items());

        $this->assertSame([3200.0, 3000.0], $rows->pluck('balance')->all(), 'the 2,000 in still counts toward the balance');
    }

    public function test_choosing_one_account_shows_only_that_account(): void
    {
        $data = $this->book(['account_ids' => [$this->bank]])->getData();

        $this->assertSame([$this->bank], array_keys($data['summary']));
        $this->assertSame(5000.0, $data['totals']['closing']);
    }

    public function test_day_wise_adds_up_to_the_entries(): void
    {
        $daily = $this->book(['mode' => 'daily'])->getData()['daily'][$this->cash];

        $this->assertSame([
            ['date' => '2026-10-01', 'opening' => 1500.0, 'in' => 2000.0, 'out' => 0.0, 'closing' => 3500.0, 'entries' => 1],
            ['date' => '2026-10-02', 'opening' => 3500.0, 'in' => 0.0, 'out' => 500.0, 'closing' => 3000.0, 'entries' => 2],
        ], $daily);
    }

    public function test_the_running_balance_carries_across_pages(): void
    {
        for ($i = 0; $i < CashBookService::PER_PAGE + 50; $i++) {
            $this->move($this->bank, '2026-10-05', 'in', 10, 'manual_journal', 'manual_journal', null, 'Row ' . $i);
        }
        $page2 = collect($this->book(['account_ids' => [$this->bank], 'page' => 2])->getData()['entries']->items());

        // 5,000 + 199 rows of 10 on page 1 (the 5,000 is row 1) → page 2 starts at 5,000 + 200 × 10.
        $this->assertSame(5000.0 + 200 * 10, $page2->first()->balance);
        $this->assertSame(5000.0 + 250 * 10, $page2->last()->balance);
    }

    public function test_a_busy_period_opens_day_wise_and_dates_the_wrong_way_round_still_work(): void
    {
        $rows = [];
        for ($i = 0; $i < CashBookController::DAILY_ABOVE + 1; $i++) {
            $rows[] = ['cash_bank_account_id' => $this->bank, 'transaction_date' => '2026-10-06', 'direction' => 'in', 'amount' => 1,
                'balance_after' => 0, 'transaction_type' => 'sales_payment', 'reference_type' => 'sale_payment', 'notes' => 'Sale', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('cash_bank_account_transactions')->insert($rows);

        $this->assertSame('daily', $this->book()->getData()['mode'], 'a restaurant month opens one line a day');
        $this->assertSame('entries', $this->book(['mode' => 'entries'])->getData()['mode'], 'asking for entries still gets them');
        $swapped = $this->book(['date_from' => '2026-10-31', 'date_to' => '2026-10-01'])->getData()['filters'];
        $this->assertSame(['2026-10-01', '2026-10-31'], [$swapped['date_from'], $swapped['date_to']]);
    }

    public function test_the_page_csv_and_print_show_the_same_book(): void
    {
        $html = $this->book()->render();
        $this->assertStringContainsString('id="cash-book-total-closing">7,300.00', $html);
        $this->assertStringContainsString('Faisal Beef', $html);
        $this->assertStringContainsString('Balance brought forward', $html);

        ob_start();
        $this->book(['format' => 'csv'])->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString('"Main Cash Drawer",1500,2000,500,3000', $csv);
        $this->assertStringContainsString('"Main Cash Drawer",2026-10-02,"Supplier payment",PAY-1,"Faisal Beef",,300,3200', $csv);

        $print = $this->book(['format' => 'print'])->render();
        $this->assertStringContainsString('window.print()', $print);
        $this->assertStringContainsString('Rickshaw — Transport · Kiraya', $print);
    }

    public function test_the_report_belongs_to_finance_and_sits_in_the_menu_that_exists(): void
    {
        Artisan::call('route:clear');
        Artisan::call('system:routes-sync');
        $this->assertSame('tenant.finance', DB::connection('master')->table('route_catalogs')->where('route_name', self::ROUTE)->value('module_key'));

        $financeOnly = $this->tenantWith(['finance']);   // Kashif Kitchen's shape
        $this->assertTrue(app(TenantSubscriptionAccessService::class)->check($financeOnly, self::ROUTE)['allowed']);
        $html = $this->sidebarFor($financeOnly);
        $this->assertStringContainsString('id="finance-cash-book-link"', $html);

        $both = $this->sidebarFor($this->tenantWith(['finance', 'reports']));
        $this->assertStringContainsString('id="reports-cash-book-link"', $both);
        $this->assertStringNotContainsString('id="finance-cash-book-link"', $both, 'one link, not two');
    }

    private function tenantWith(array $moduleKeys): Tenant
    {
        $master = DB::connection('master');
        $master->table('tenants')->where('tenant_code', 'like', 'cashbooktest-%')->delete();
        $plan = Plan::create(['code' => 'cashbooktest-' . uniqid(), 'name' => 'Cash Book Test', 'price' => 0, 'is_active' => true]);
        foreach ($moduleKeys as $key) {
            $module = Module::firstOrCreate(['key' => $key], ['name' => ucfirst($key), 'category' => 'Test', 'description' => $key,
                'route_module_keys' => ['tenant.' . $key], 'sort_order' => 900, 'is_core' => false, 'is_active' => true]);
            PlanModule::create(['plan_id' => $plan->id, 'module_id' => $module->id, 'is_enabled' => true]);
        }
        $tenant = Tenant::create(['tenant_code' => 'cashbooktest-' . uniqid(), 'business_name' => 'Cash Book Test', 'status' => 'active']);
        Subscription::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);
        $this->beforeApplicationDestroyed(function () use ($master, $tenant, $plan) {
            $master->table('subscriptions')->where('tenant_id', $tenant->id)->delete();
            $master->table('tenants')->where('id', $tenant->id)->delete();
            $master->table('plan_modules')->where('plan_id', $plan->id)->delete();
            $master->table('plans')->where('id', $plan->id)->delete();
        });

        return $tenant->fresh();
    }

    private function sidebarFor(Tenant $tenant): string
    {
        app()->instance('tenant', $tenant);
        Permission::findOrCreate(self::ROUTE, 'tenant');
        $user = User::on('tenant')->find($this->makeUser());
        $user->givePermissionTo(self::ROUTE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($user->id), 'tenant');
        Auth::shouldUse('tenant');

        return view('partials.sidebar')->render();
    }
}
