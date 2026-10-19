<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchasingSetting;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierLedger;
use App\Models\Tenant\User;
use App\Services\Finance\SupplierPayableService;
use App\Services\Purchasing\PurchasingService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * SUPPLIER-LEDGER-DRCR-LABELS-1 — supplier ka bill CREDIT me, payment DEBIT me.
 *
 * Supplier hamara lendar (creditor) hai: bill aaye to us ka khaata CREDIT hota hai, paisa diya to
 * DEBIT. Ledger ka screen `supplier_ledgers.direction` ko seedha Debit/Credit ke khaanon me daal
 * deta tha — magar wo column ek AAINA hai: 'debit' = "payable barha" (yani supplier CREDIT). Is liye
 * har bill Debit me aur har payment Credit me chhapti thi; ek client ke accountant ne printout par
 * pakda. GL aur Trial Balance sahi thay — sirf ye screen ulti thi.
 *
 * Ilaaj sirf dikhawe ka hai. Stored direction, services aur balance NAHI badle — ye imtihan wo bhi
 * pakadta hai. Safha ASLI HTTP se khulta hai, logged-in tenant user ke taur par.
 */
class SupplierLedgerDrCrMySqlTest extends MySqlTenantTestCase
{
    private int $tenantId;
    private int $ownerId;
    private int $branchId;
    private int $supplierId;
    private int $bankId;
    private string $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = 'supdrcr.' . config('tenancy.tenant_base_domain');

        $this->seedMaster();
        $this->seedSubscription();
        $this->bootTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)
                ->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('tenant_code', 'supdrcr')->delete();
        } catch (\Throwable) {
            // best effort — asli nateeja kabhi na chhupe
        }
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════════

    public function test_bill_credit_me_aur_balance_par_cr(): void
    {
        $bill = $this->postBill(32770);

        $html = $this->ledgerPage();
        $row = $this->cells($html, $bill->bill_no);

        $this->assertSame('—', $row['debit'], 'bill Debit me NAHI');
        $this->assertSame('32,770.00', $row['credit'], 'bill Credit me — supplier ka khaata credit hua');
        $this->assertSame('32,770.00 Cr', $row['balance'], 'balance batata hai kis taraf hai');
        $this->assertStringContainsString('<strong>32,770.00 Cr</strong>', $html, 'oopar ka Current Balance bhi');

        // Data ka usool wahi: bill = payable barha = stored 'debit'.
        $this->assertSame('debit', SupplierLedger::where('reference_no', $bill->bill_no)->value('direction'));
    }

    public function test_payment_debit_me_aur_baqi_cr(): void
    {
        $this->postBill(32770);
        $payment = $this->pay(8180);

        $row = $this->cells($this->ledgerPage(), $payment->payment_no);

        $this->assertSame('8,180.00', $row['debit'], 'payment Debit me — supplier ko paisa diya');
        $this->assertSame('—', $row['credit']);
        $this->assertSame('24,590.00 Cr', $row['balance']);

        $this->assertSame('credit', SupplierLedger::where('reference_id', $payment->id)
            ->where('entry_type', 'payment')->value('direction'), 'stored direction nahi badla');
        $this->assertSame(24590.0, (float) Supplier::find($this->supplierId)->current_balance, 'balance wahi');
    }

    public function test_advance_wala_balance_waisa_hi_rehta_hai(): void
    {
        PurchasingSetting::tenantDefault()->update(['supplier_running_account' => true]);
        $this->postBill(1000);
        $payment = $this->pay(6000);

        $html = $this->ledgerPage();
        $this->assertSame('Advance 5,000.00 (Dr)', $this->cells($html, $payment->payment_no)['balance']);
        $this->assertStringContainsString('<strong>Advance 5,000.00 (Dr)</strong>', $html);
        $this->assertStringNotContainsString('5,000.00 Cr', $html);
    }

    /** Wohi label supplier list, supplier ka safha aur payment form par bhi. */
    public function test_baqi_teen_jagah_bhi_cr_dikhta_hai(): void
    {
        $this->postBill(32770);

        $list = $this->actingAsOwner()->get('http://' . $this->host . '/suppliers')->assertOk()->getContent();
        $this->assertStringContainsString('32,770.00 Cr', $list, 'supplier list');

        $show = $this->actingAsOwner()->get('http://' . $this->host . '/suppliers/' . $this->supplierId)->assertOk()->getContent();
        $this->assertStringContainsString('<strong>32,770.00 Cr</strong>', $show, 'supplier ka safha');

        $form = $this->actingAsOwner()->get('http://' . $this->host . '/supplier-payments/create')->assertOk()->getContent();
        $this->assertStringContainsString('(Balance: 32,770.00 Cr)', $form, 'payment form ka dropdown');
    }

    public function test_zero_balance_bina_nishan(): void
    {
        $this->postBill(5000);
        $payment = $this->pay(5000);

        $this->assertSame('0.00', $this->cells($this->ledgerPage(), $payment->payment_no)['balance']);
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // helpers
    // ══════════════════════════════════════════════════════════════════════════════

    private function ledgerPage(): string
    {
        return $this->actingAsOwner()->get('http://' . $this->host . '/suppliers/' . $this->supplierId . '/ledger')
            ->assertOk()->getContent();
    }

    /** Ledger ki us satar ke khaane jis me ye reference hai — header ke kram se. */
    private function cells(string $html, string $reference): array
    {
        $at = strpos($html, '<td>' . $reference . '</td>');
        $this->assertNotFalse($at, "[{$reference}] ledger me mojood");
        $open = strrpos(substr($html, 0, $at), '<tr');
        $close = strpos($html, '</tr>', $at);
        preg_match_all('#<td[^>]*>(.*?)</td>#s', substr($html, $open, $close - $open), $m);
        $td = array_map(fn ($c) => trim(html_entity_decode(strip_tags($c))), $m[1]);

        return array_combine(['date', 'type', 'reference', 'description', 'debit', 'credit', 'balance', 'user'], $td);
    }

    private function postBill(float $total): PurchaseBill
    {
        $bill = PurchaseBill::create([
            'bill_no' => 'BILL-' . Str::upper(Str::random(6)), 'supplier_id' => $this->supplierId,
            'branch_id' => $this->branchId, 'bill_date' => now()->toDateString(),
            'subtotal' => $total, 'grand_total' => $total, 'amount_paid' => 0,
            'balance_due' => $total, 'status' => 'posted',
        ]);
        app(PurchasingService::class)->postBill($bill, $this->ownerId);

        return $bill->fresh();
    }

    private function pay(float $amount)
    {
        return app(SupplierPayableService::class)->recordPayment([
            'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId, 'cash_bank_account_id' => $this->bankId,
            'payment_date' => now()->toDateString(), 'amount' => $amount, 'payment_method' => 'cash',
        ], $this->ownerId);
    }

    private function actingAsOwner()
    {
        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant');
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenants')->where('tenant_code', 'supdrcr')->delete();

        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'supdrcr', 'business_name' => 'Supplier Ledger Sides',
            'owner_name' => 'Owner', 'owner_email' => 'owner@supdrcr.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $m->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Saare modules khule — imtihan screen ka hai, plan-entitlement ka nahi. */
    private function seedSubscription(): void
    {
        $m = DB::connection('master');

        $planId = $m->table('plans')->where('code', 'supdrcr-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'supdrcr-plan', 'name' => 'Supplier Ledger Sides', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1]);
        }

        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert([
            'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function bootTenant(): void
    {
        DB::setDefaultConnection('tenant');

        // permissions/roles tenant MIGRATION se aate hain — unhe kabhi truncate nahi karna.
        $this->cleanTenant([
            'supplier_credit_allocations', 'purchasing_settings',
            'journal_lines', 'journal_entries', 'supplier_ledgers', 'supplier_payments',
            'purchase_return_lines', 'purchase_returns', 'purchase_bill_lines', 'purchase_bills',
            'cash_bank_account_transactions', 'cash_bank_accounts',
            'model_has_roles', 'users', 'suppliers', 'accounts', 'branches',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        (new DefaultChartOfAccountsSeeder)->run();

        $c = DB::connection('tenant');

        $this->branchId = $c->table('branches')->insertGetId([
            'name' => 'Main', 'code' => 'MAIN', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->supplierId = Supplier::create([
            'code' => 'SUP-CD', 'name' => 'Cold Drink Supplier', 'status' => 'active',
            'opening_balance' => 0, 'current_balance' => 0,
        ])->id;

        $this->bankId = CashBankAccount::create([
            'code' => 'CASH-MAIN', 'name' => 'Main Cash Drawer', 'account_type' => 'cash',
            'account_id' => (int) Account::where('code', '1110')->value('id'), 'branch_id' => $this->branchId,
            'opening_balance' => 500000, 'current_balance' => 500000, 'is_active' => true,
        ])->id;

        foreach ([
            'tenant.suppliers.index',
            'tenant.suppliers.show',
            'tenant.suppliers.ledger',
            'tenant.supplier-payments.create',
        ] as $permName) {
            $c->table('permissions')->updateOrInsert(
                ['name' => $permName, 'guard_name' => 'tenant'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'Ledger Owner', 'email' => 'supdrcr-owner@test.local', 'password' => bcrypt('x'),
            'employee_code' => 'SUPDRCR', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
