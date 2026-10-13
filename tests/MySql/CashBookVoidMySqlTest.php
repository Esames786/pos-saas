<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchasingSetting;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierCreditAllocation;
use App\Models\Tenant\SupplierLedger;
use App\Models\Tenant\SupplierPayment;
use App\Models\Tenant\User;
use App\Services\Finance\SupplierPayableService;
use App\Services\Purchasing\PurchasingService;
use App\Services\Purchasing\SupplierRunningAccountService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\ParsesInlineScripts;

/**
 * CASH-BOOK-VOID-1 + SUPPLIER-PAYMENT-VOID-1 — Cash Book se ghalat entry ulti karo.
 *
 * Accountant ne Cash Book par ghalat entry dekhi aur poocha "void kahan se karun". Catering receipt,
 * expense aur manual journal ka void tha magar alag alag safhon par; supplier payment ka void tha hi
 * nahi — Owner ke paas bhi nahi. Ab har satar par Void ka button hai jo usi entry ke apne darwaze par
 * jata hai, aur supplier payment ka darwaza naya bana hai.
 *
 * Supplier payment ka void recordPayment() ke chaaron hisse ulte karta hai — cash, supplier ka payable,
 * GL, bill — ek transaction me. Ye imtihan chaaron ko alag alag naapta hai, asli HTTP raaste se.
 */
class CashBookVoidMySqlTest extends MySqlTenantTestCase
{
    use ParsesInlineScripts;

    private const OPENING = 100000.0;

    private int $tenantId;
    private int $ownerId;
    private int $clerkId;
    private int $branchId;
    private int $supplierId;
    private int $drawerId;
    private string $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = 'cbvoid.' . config('tenancy.tenant_base_domain');

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
            $m->table('tenants')->where('tenant_code', 'cbvoid')->delete();
        } catch (\Throwable) {
            // best effort — asli nateeja kabhi na chhupe
        }
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // SUPPLIER PAYMENT VOID — chaaron hisse wapas
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_void_cash_payable_gl_aur_bill_chaaron_wapas_karta_hai(): void
    {
        $bill = $this->postBill(10000);
        $payment = $this->pay(4000, $bill->id);

        $this->assertSame(self::OPENING - 4000, $this->drawer(), 'payment ne drawer se 4,000 nikale');
        $this->assertSame(6000.0, $this->payable());
        $this->assertSame([4000.0, 6000.0, 'partial'], $this->billState($bill));

        $this->voidPayment($payment, 'entered twice')->assertSessionHasNoErrors();

        // cash
        $back = CashBankAccountTransaction::where('reference_type', 'supplier_payment')->where('reference_id', $payment->id)
            ->where('transaction_type', 'supplier_payment_void_reversal')->sole();
        $this->assertSame('in', $back->direction);
        $this->assertSame(4000.0, (float) $back->amount);
        $this->assertSame(self::OPENING, $this->drawer(), 'drawer wapas 100,000');

        // supplier ka payable
        $this->assertSame(10000.0, $this->payable(), 'supplier phir 10,000 ka lendar');
        $row = SupplierLedger::where('entry_type', 'payment_void')->sole();
        $this->assertSame('debit', $row->direction, 'aaina: payable barha = stored debit');
        $this->assertSame(4000.0, (float) $row->amount);

        // GL
        $original = JournalEntry::where('source_type', 'supplier_payment')->where('source_id', $payment->id)->sole();
        $reversal = JournalEntry::where('reversed_entry_id', $original->id)->sole();
        $this->assertTrue((bool) $reversal->is_reversal);
        $this->assertSame(0.0, $this->tbDiff(), 'trial balance barabar');
        $this->assertSame($this->payable(), $this->apFromGl(), 'GL ka AP = supplier ka subledger');

        // bill
        $this->assertSame([0.0, 10000.0, 'posted'], $this->billState($bill), 'bill phir poora khula');

        // nishan
        $payment->refresh();
        $this->assertNotNull($payment->voided_at);
        $this->assertSame('entered twice', $payment->void_reason);
        $this->assertSame($this->ownerId, (int) $payment->voided_by_user_id);
        $this->assertSame($reversal->id, (int) $payment->void_journal_entry_id);
    }

    public function test_running_account_par_allocations_hat_jati_hain_aur_voided_payment_credit_nahi_rehti(): void
    {
        PurchasingSetting::tenantDefault()->update(['supplier_running_account' => true]);
        $a = $this->postBill(3000);
        $b = $this->postBill(5000);
        $payment = $this->pay(6000);   // general payment: A poora, B me se 3,000

        $this->assertSame([3000.0, 0.0, 'paid'], $this->billState($a));
        $this->assertSame([3000.0, 2000.0, 'partial'], $this->billState($b));

        $this->voidPayment($payment, 'wrong supplier')->assertSessionHasNoErrors();

        $this->assertSame(0, SupplierCreditAllocation::where('source_id', $payment->id)->count(), 'allocations hat gayin');
        $this->assertSame([0.0, 3000.0, 'posted'], $this->billState($a));
        $this->assertSame([0.0, 5000.0, 'posted'], $this->billState($b));
        $this->assertSame(8000.0, $this->payable());

        // Ulti payment kisi aane wale bill ko nahi chukati.
        $this->assertSame([], app(SupplierRunningAccountService::class)->unallocatedCredits($this->supplierId));
        $c = $this->postBill(1000);
        $this->assertSame([0.0, 1000.0, 'posted'], $this->billState($c), 'naya bill khula hi raha');
    }

    public function test_dobara_void_nahi_hota_aur_wajah_lazmi_hai(): void
    {
        $payment = $this->pay(2000);

        $this->voidPayment($payment, '')->assertSessionHasErrors('reason');
        $this->voidPayment($payment, '.')->assertSessionHasErrors('reason');
        $this->assertNull($payment->fresh()->voided_at, 'bina wajah kuch nahi hua');

        $this->voidPayment($payment, 'paid by mistake')->assertSessionHasNoErrors();
        $res = $this->voidPayment($payment, 'paid by mistake again');
        $res->assertSessionHasErrors('payment');
        $this->assertStringContainsString('already been voided', session('errors')->first('payment'));

        $this->assertSame(1, CashBankAccountTransaction::where('transaction_type', 'supplier_payment_void_reversal')->count(), 'paisa ek hi bar wapas');
        $this->assertSame(1, JournalEntry::where('source_type', 'supplier_payment_reversal')->count());
        $this->assertSame(self::OPENING, $this->drawer());
    }

    /** Ek do-click: doosri request controller tak pahunchti hi nahi. */
    public function test_void_route_par_duplicate_guard_hai(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'tenant.supplier-payments.void');
        $this->assertNotNull($route);
        $this->assertContains('prevent.duplicate.submit', $route->gatherMiddleware());
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // CASH BOOK
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_cash_book_ki_har_satar_par_uska_apna_void(): void
    {
        $payment = $this->pay(1500);
        $journal = $this->manualJournal(800);
        $expenseId = $this->expenseRow(600);

        $html = $this->cashBook();

        $this->assertStringContainsString('data-action="' . url('/supplier-payments/' . $payment->id . '/void') . '"', $html);
        $this->assertStringContainsString('data-action="' . url('/finance/manual-journals/' . $journal->id . '/reverse') . '"', $html);
        $this->assertStringContainsString('data-action="' . url('/finance/expenses/' . $expenseId . '/void') . '"', $html);
        $this->assertStringContainsString('data-kind="manual_journal"', $html);

        // Ek hi modal: wajah lazmi, aur kaam ke baad isi safhe par wapsi.
        $this->assertMatchesRegularExpression('/<textarea name="reason"[^>]*\brequired\b[^>]*minlength="3"/', $html);
        $this->assertMatchesRegularExpression('/<input type="hidden" name="return_to" value="\/reports\/cash-book\?[^"]*"/', $html);
        $this->assertStringContainsString('_submit_token', $html, 'do-click ki rok');
        $this->assertInlineScriptsParse($html, 'cash book', 'cash-book-void');
    }

    public function test_cash_book_se_void_wapas_cash_book_par_aur_satar_voided(): void
    {
        $payment = $this->pay(1500);
        $returnTo = '/reports/cash-book?' . http_build_query(['date_from' => now()->toDateString(), 'date_to' => now()->toDateString(), 'mode' => 'entries']);

        $res = $this->voidPayment($payment, 'wrong amount', ['return_to' => $returnTo]);
        $res->assertRedirect($returnTo);
        $this->assertStringContainsString('voided', session('status'));

        $html = $this->cashBook();
        $row = $this->rowWith($html, $payment->payment_no, 'Supplier payment</td>');
        $this->assertStringContainsString('>Voided</span>', $row, 'asal satar par Voided');
        $this->assertStringNotContainsString('cash-book-void', $row, 'aur us par Void ka button nahi');
        $this->assertStringContainsString('Supplier payment voided (back)', $html, 'wapsi ki satar Cash Book me');
    }

    public function test_manual_journal_ka_reverse_bhi_cash_book_par_lautata_hai(): void
    {
        $journal = $this->manualJournal(800);
        $returnTo = '/reports/cash-book?mode=entries';

        $this->actingAsOwner()->post('http://' . $this->host . '/finance/manual-journals/' . $journal->id . '/reverse',
            ['reason' => 'wrong drawer', 'return_to' => $returnTo])->assertRedirect($returnTo);

        $this->assertSame(1, JournalEntry::where('reversed_entry_id', $journal->id)->count());
        $this->assertStringContainsString('>Reversed</span>', $this->cashBook());
    }

    public function test_return_to_site_se_bahar_nahi_le_jata(): void
    {
        foreach (['https://evil.example/reports/cash-book', '//evil.example/reports/cash-book', '/reports/cash-bookx', '/supplier-payments'] as $bad) {
            $payment = $this->pay(100);
            $this->voidPayment($payment, 'testing the redirect', ['return_to' => $bad])
                ->assertRedirect(url('/supplier-payments/' . $payment->id));
        }
    }

    public function test_bina_ijazat_void_ka_button_nahi(): void
    {
        $this->pay(1500);
        $this->manualJournal(800);

        $html = $this->actingAs(User::on('tenant')->find($this->clerkId), 'tenant')
            ->get('http://' . $this->host . '/reports/cash-book?mode=entries')->assertOk()->getContent();

        $this->assertStringNotContainsString('cash-book-void"', $html, 'sirf Cash Book dekhne wale ko Void nahi');

        // Aur seedha POST bhi rad.
        $payment = SupplierPayment::first();
        $this->actingAs(User::on('tenant')->find($this->clerkId), 'tenant')
            ->post('http://' . $this->host . '/supplier-payments/' . $payment->id . '/void', ['reason' => 'not allowed'])
            ->assertStatus(403);
        $this->assertNull($payment->fresh()->voided_at);
    }

    public function test_payment_ke_safhe_par_void_aur_voided_nishan(): void
    {
        $payment = $this->pay(1500);

        $html = $this->actingAsOwner()->get('http://' . $this->host . '/supplier-payments/' . $payment->id)->assertOk()->getContent();
        $this->assertStringContainsString('data-bs-target="#voidPaymentModal"', $html);
        $this->assertStringContainsString('action="' . url('/supplier-payments/' . $payment->id . '/void') . '"', $html);

        $this->voidPayment($payment, 'entered twice');
        $html = $this->actingAsOwner()->get('http://' . $this->host . '/supplier-payments/' . $payment->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('#voidPaymentModal', $html, 'voided par button nahi');
        $this->assertStringContainsString('id="payment-voided-note"', $html);
        $this->assertStringContainsString('entered twice', $html);

        $list = $this->actingAsOwner()->get('http://' . $this->host . '/supplier-payments')->assertOk()->getContent();
        $this->assertStringContainsString('<span class="badge bg-secondary">Voided</span>', $list);
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // helpers
    // ══════════════════════════════════════════════════════════════════════════════

    private function voidPayment(SupplierPayment $payment, string $reason, array $extra = [])
    {
        return $this->actingAsOwner()->post('http://' . $this->host . '/supplier-payments/' . $payment->id . '/void',
            ['reason' => $reason] + $extra);
    }

    private function cashBook(): string
    {
        return $this->actingAsOwner()->get('http://' . $this->host . '/reports/cash-book?' . http_build_query([
            'date_from' => now()->subDay()->toDateString(), 'date_to' => now()->toDateString(), 'mode' => 'entries',
        ]))->assertOk()->getContent();
    }

    /** Cash Book ki woh `<tr>` jis me ye dono tukre hon. */
    private function rowWith(string $html, string $needle, string $alsoContains): string
    {
        foreach (preg_split('#</tr>#', $html) as $chunk) {
            $tr = substr($chunk, (int) strrpos($chunk, '<tr'));
            if (str_contains($tr, $needle) && str_contains($tr, $alsoContains)) {
                return $tr;
            }
        }
        $this->fail("no row with [{$needle}] and [{$alsoContains}]");
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

    private function pay(float $amount, ?int $billId = null): SupplierPayment
    {
        // Payment kabhi advance na banaye (switch OFF par ye mana hai) — pehle utna payable khada karo.
        if ((float) Supplier::find($this->supplierId)->current_balance < $amount && ! $billId
            && ! PurchasingSetting::supplierRunningAccount()) {
            $this->postBill($amount);
        }

        return app(SupplierPayableService::class)->recordPayment([
            'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId, 'cash_bank_account_id' => $this->drawerId,
            'purchase_bill_id' => $billId, 'payment_date' => now()->toDateString(), 'amount' => $amount, 'payment_method' => 'cash',
        ], $this->ownerId);
    }

    /** Manual journal ASLI HTTP se: Dr 6800 / Cr 1110 drawer ki satar par. */
    private function manualJournal(float $amount): JournalEntry
    {
        $this->actingAsOwner()->post('http://' . $this->host . '/finance/manual-journals', [
            'entry_date' => now()->toDateString(), 'description' => 'Misc cash expense', 'reference_no' => '',
            'lines' => [
                ['account_id' => $this->accId('6800'), 'debit' => $amount, 'credit' => 0],
                ['account_id' => $this->accId('1110'), 'debit' => 0, 'credit' => $amount, 'cash_bank_account_id' => $this->drawerId],
            ],
        ])->assertSessionHasNoErrors();

        return JournalEntry::where('source_type', 'manual_journal')->orderByDesc('id')->firstOrFail();
    }

    /** Ek posted expense ki cash satar — Cash Book par button dikhane ke liye kaafi. */
    private function expenseRow(float $amount): int
    {
        $c = DB::connection('tenant');
        $id = $c->table('expense_vouchers')->insertGetId([
            'voucher_no' => 'EXP-' . Str::upper(Str::random(6)), 'branch_id' => $this->branchId,
            'cash_bank_account_id' => $this->drawerId, 'expense_date' => now()->toDateString(),
            'payee_name' => 'Stationery shop', 'status' => 'posted', 'subtotal' => $amount, 'tax_amount' => 0,
            'total_amount' => $amount, 'created_at' => now(), 'updated_at' => now(),
        ]);
        CashBankAccountTransaction::create([
            'cash_bank_account_id' => $this->drawerId, 'transaction_date' => now()->toDateString(), 'direction' => 'out',
            'amount' => $amount, 'balance_after' => $this->drawer() - $amount, 'transaction_type' => 'expense_payment',
            'reference_type' => 'expense_voucher', 'reference_id' => $id, 'notes' => 'Expense',
        ]);

        return $id;
    }

    private function billState(PurchaseBill $bill): array
    {
        $bill->refresh();

        return [round((float) $bill->amount_paid, 2), round((float) $bill->balance_due, 2), $bill->status];
    }

    private function drawer(): float
    {
        return round((float) CashBankAccount::find($this->drawerId)->current_balance, 2);
    }

    private function payable(): float
    {
        return round((float) Supplier::find($this->supplierId)->current_balance, 2);
    }

    private function tbDiff(): float
    {
        return round((float) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) v')->value('v'), 2);
    }

    private function apFromGl(): float
    {
        return round((float) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')->where('l.account_id', $this->accId('2100'))
            ->selectRaw('COALESCE(SUM(l.credit) - SUM(l.debit), 0) v')->value('v'), 2);
    }

    private function accId(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
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
        $m->table('tenants')->where('tenant_code', 'cbvoid')->delete();

        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'cbvoid', 'business_name' => 'Cash Book Void',
            'owner_name' => 'Owner', 'owner_email' => 'owner@cbvoid.test',
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

    /** Saare modules khule — imtihan void ka hai, plan-entitlement ka nahi. */
    private function seedSubscription(): void
    {
        $m = DB::connection('master');

        $planId = $m->table('plans')->where('code', 'cbvoid-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'cbvoid-plan', 'name' => 'Cash Book Void', 'price' => 0,
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
            'expense_voucher_lines', 'expense_vouchers',
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
            'code' => 'SUP-CH', 'name' => 'Chicken Supplier', 'status' => 'active',
            'opening_balance' => 0, 'current_balance' => 0,
        ])->id;

        $this->drawerId = CashBankAccount::create([
            'code' => 'CASH-MAIN', 'name' => 'Main Cash Drawer', 'account_type' => 'cash',
            'account_id' => $this->accId('1110'), 'branch_id' => $this->branchId,
            'opening_balance' => self::OPENING, 'current_balance' => self::OPENING, 'is_active' => true,
        ])->id;

        $perms = [
            'tenant.reports.cash-book.index',
            'tenant.supplier-payments.index', 'tenant.supplier-payments.show', 'tenant.supplier-payments.void',
            'tenant.finance.expenses.show', 'tenant.finance.expenses.void',
            'tenant.finance.manual-journals.store', 'tenant.finance.manual-journals.show', 'tenant.finance.manual-journals.reverse',
            'tenant.catering.advances.void', 'tenant.sales-orders.cancel',
        ];
        foreach ($perms as $permName) {
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

        // Sirf Cash Book dekhne wala — apna naam wala role, kisi doosre type ka nahi.
        $clerkRole = $c->table('roles')->where('name', 'Cash Book Viewer')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Cash Book Viewer', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        $c->table('role_has_permissions')->where('role_id', $clerkRole)->delete();
        $c->table('role_has_permissions')->insert([
            'permission_id' => $c->table('permissions')->where('name', 'tenant.reports.cash-book.index')->where('guard_name', 'tenant')->value('id'),
            'role_id' => $clerkRole,
        ]);

        foreach ([['Void Owner', 'cbvoid-owner@test.local', 'CBVOWN', $ownerRole], ['Book Clerk', 'cbvoid-clerk@test.local', 'CBVCLK', $clerkRole]] as [$name, $email, $code, $role]) {
            $id = $c->table('users')->insertGetId([
                'name' => $name, 'email' => $email, 'password' => bcrypt('x'),
                'employee_code' => $code, 'status' => 'active', 'locale' => 'en',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $c->table('model_has_roles')->insert(['role_id' => $role, 'model_type' => User::class, 'model_id' => $id]);
            $code === 'CBVOWN' ? $this->ownerId = $id : $this->clerkId = $id;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
