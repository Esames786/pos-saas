<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CHART-SEEDER-KEEP-NAMES-1 (9 Nov) — deploy.sh runs DefaultChartOfAccountsSeeder on every tenant on every deploy, and it used
 * to write each built-in head's default name, parent and sort order back over the tenant's own (a client renamed 6900 to
 * "1% Expense" and 6500 to "Printing & Stationery Expense"; both turned back after the next deploy).
 *
 * Now an existing built-in head keeps the tenant's name, parent and sort order; what postings depend on (type, normal
 * balance, system, active) is still held; a missing head is created with its defaults; a fresh chart is complete.
 */
class ChartSeederKeepsTenantNamesMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['journal_lines', 'journal_entries', 'cash_bank_account_transactions', 'cash_bank_accounts', 'expense_categories', 'accounts']);
    }

    public function test_a_redeploy_keeps_the_tenants_names_parents_and_order_of_built_in_heads(): void
    {
        $seeder = new DefaultChartOfAccountsSeeder();
        $seeder->run();
        $head5000 = Account::where('code', '5000')->firstOrFail();

        // What a client does on the Chart of Accounts screen.
        Account::where('code', '6500')->firstOrFail()->update(['name' => 'Printing & Stationery Expense', 'sort_order' => 0]);
        Account::where('code', '6900')->firstOrFail()->update(['name' => '1% Expense', 'parent_id' => $head5000->id]);
        Account::where('code', '6910')->firstOrFail()->update(['name' => 'Owner Drawing', 'sort_order' => 7]);

        $seeder->run();   // the next deploy

        $a6500 = Account::where('code', '6500')->firstOrFail();
        $a6900 = Account::where('code', '6900')->firstOrFail();
        $a6910 = Account::where('code', '6910')->firstOrFail();
        $this->assertSame('Printing & Stationery Expense', $a6500->name);
        $this->assertSame(0, $a6500->sort_order);
        $this->assertSame('1% Expense', $a6900->name);
        $this->assertSame($head5000->id, (int) $a6900->parent_id, 'the client moved it under 5000; it stays there');
        $this->assertSame('Owner Drawing', $a6910->name);
        $this->assertSame(7, $a6910->sort_order);
    }

    public function test_what_postings_depend_on_is_still_held_on_a_redeploy(): void
    {
        $seeder = new DefaultChartOfAccountsSeeder();
        $seeder->run();
        // A built-in head switched off by mistake, its type changed: postings to 6930 (closing short cash) would be refused.
        DB::connection('tenant')->table('accounts')->where('code', '6930')->update(['is_active' => 0, 'type' => 'asset', 'normal_balance' => 'credit', 'is_system' => 0]);

        $seeder->run();

        $a = Account::where('code', '6930')->firstOrFail();
        $this->assertTrue($a->is_active, 'active again, so a closing can post to it');
        $this->assertSame('expense', $a->type);
        $this->assertSame('debit', $a->normal_balance);
        $this->assertTrue($a->is_system);
        $this->assertSame('Cash Short / Over', $a->name, 'a name nobody changed stays the default');
    }

    public function test_a_missing_built_in_head_comes_back_with_its_defaults_and_a_fresh_chart_is_complete(): void
    {
        $seeder = new DefaultChartOfAccountsSeeder();
        $count = $seeder->run();
        $this->assertSame($count, Account::count(), 'a fresh tenant gets the whole default chart');
        $this->assertSame('Scrap / Waste Expense', Account::where('code', '6900')->value('name'));
        $this->assertSame((int) Account::where('code', '6000')->value('id'), (int) Account::where('code', '6900')->value('parent_id'));

        Account::where('code', '6920')->firstOrFail()->delete();   // an unused built-in head removed
        $seeder->run();

        $back = Account::where('code', '6920')->firstOrFail();
        $this->assertSame('Inventory Adjustment Expense', $back->name);
        $this->assertSame((int) Account::where('code', '6000')->value('id'), (int) $back->parent_id);
        $this->assertSame(380, $back->sort_order);
        $this->assertTrue($back->is_active && $back->is_system);
        $this->assertSame($count, Account::count());
    }
}
