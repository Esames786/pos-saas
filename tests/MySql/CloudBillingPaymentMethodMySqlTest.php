<?php

namespace Tests\MySql;

use App\Models\Master\BillingPaymentMethod;
use Database\Seeders\BillingPaymentMethodSeeder;
use Illuminate\Support\Facades\DB;

/**
 * CLOUD-BILLING-1A — manual payment-method directory (master authority).
 * Proves the additive migration, the active/ordered scope, the exact query the tenant billing
 * page uses (active AND account-configured only), and seeder idempotency (never clobbers
 * admin-entered account details). No real account details are used — placeholders only.
 */
class CloudBillingPaymentMethodMySqlTest extends MySqlTenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('master')->table('billing_payment_methods')->delete();
    }

    public function test_migration_created_the_table(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::connection('master')->hasTable('billing_payment_methods')
        );
    }

    public function test_seeder_creates_the_three_methods_inactive_and_without_account_details(): void
    {
        (new BillingPaymentMethodSeeder)->run();

        $codes = BillingPaymentMethod::orderBy('sort_order')->pluck('code')->all();
        $this->assertSame(['easypaisa', 'jazzcash', 'nayapay'], $codes);

        foreach (BillingPaymentMethod::all() as $m) {
            $this->assertFalse($m->is_active, "$m->code must seed INACTIVE");
            $this->assertNull($m->account_number, "$m->code must seed with NO account number");
            $this->assertNull($m->account_title, "$m->code must seed with NO account title");
        }
    }

    public function test_active_ordered_scope_returns_only_active_in_sort_order(): void
    {
        BillingPaymentMethod::create(['code' => 'b', 'display_name' => 'B', 'is_active' => true, 'sort_order' => 2]);
        BillingPaymentMethod::create(['code' => 'a', 'display_name' => 'A', 'is_active' => true, 'sort_order' => 1]);
        BillingPaymentMethod::create(['code' => 'off', 'display_name' => 'Off', 'is_active' => false, 'sort_order' => 0]);

        $this->assertSame(['a', 'b'], BillingPaymentMethod::activeOrdered()->pluck('code')->all());
    }

    public function test_tenant_only_sees_active_methods_that_have_an_account(): void
    {
        // active + fully configured (title AND number) -> shown
        BillingPaymentMethod::create(['code' => 'easypaisa', 'display_name' => 'EasyPaisa', 'is_active' => true, 'sort_order' => 1, 'account_title' => 'Test Acct', 'account_number' => '0000000000']);
        // active but NO account at all -> hidden (incomplete)
        BillingPaymentMethod::create(['code' => 'jazzcash', 'display_name' => 'JazzCash', 'is_active' => true, 'sort_order' => 2]);
        // active with a number but NO account title -> hidden (1A-HARDEN requires BOTH fields)
        BillingPaymentMethod::create(['code' => 'sadapay', 'display_name' => 'SadaPay', 'is_active' => true, 'sort_order' => 3, 'account_number' => '2222222222']);
        // fully configured but inactive -> hidden
        BillingPaymentMethod::create(['code' => 'nayapay', 'display_name' => 'NayaPay', 'is_active' => false, 'sort_order' => 4, 'account_title' => 'Holder', 'account_number' => '1111111111']);

        // exact query the controller/view use (single source of truth = configured() scope)
        $shown = BillingPaymentMethod::activeOrdered()->configured()->pluck('code')->all();
        $this->assertSame(['easypaisa'], $shown);
    }

    public function test_reseeding_never_clobbers_admin_entered_account_or_active_flag(): void
    {
        (new BillingPaymentMethodSeeder)->run();

        // admin configures + activates EasyPaisa (placeholder account, not real details)
        BillingPaymentMethod::where('code', 'easypaisa')->update([
            'account_title' => 'Placeholder Holder',
            'account_number' => '0000000000',
            'is_active' => true,
        ]);

        // re-run seeder (e.g. a later deploy)
        (new BillingPaymentMethodSeeder)->run();

        $easy = BillingPaymentMethod::where('code', 'easypaisa')->first();
        $this->assertTrue($easy->is_active, 'admin activation preserved');
        $this->assertSame('0000000000', $easy->account_number, 'admin account preserved');
        $this->assertSame(3, BillingPaymentMethod::count(), 'no duplicate rows created');
    }
}
