<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\JournalLine;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierLedger;
use App\Models\Tenant\SupplierPayment;
use App\Models\Tenant\User;
use App\Services\Finance\JournalPostingService;
use App\Services\Finance\SupplierPayableService;
use App\Services\Purchasing\PurchasingService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * SUPPLIER-FINANCE-DIRECT-1 — supplier FINANCE mein seedha shamil hai.
 *
 * Do baatein sabit karni hain:
 *
 *   1. Supplier ko paisa dene ke liye purchase bill ki zaroorat NAHI, aur us payment ka
 *      asar teen jagah bilkul barabar utarta hai — supplier subledger, AP control (GL),
 *      aur cash/bank — ek hi transaction mein, ya kahin bhi nahi.
 *
 *   2. Manual journal AP ko be-shanakht nahi hila sakta. Jo satar `2100` par lagti hai
 *      usay supplier ka naam lena parta hai, aur wohi harkat subledger mein utar-ti hai —
 *      warna AP control aur subledger mein farq paida hota, aur wo bhi usi screen se jo
 *      isay theek karne ke liye banayi gayi thi.
 *
 * Guard asli raaste par chalte hain: service authority, aur do jagah asli HTTP render.
 */
class SupplierFinanceDirectMySqlTest extends MySqlTenantTestCase
{
    private int $branchId;
    private int $supplierId;
    private int $bankId;        // COA se juda hua — GL post hota hai
    private int $orphanBankId;  // account_id NULL — GL post NAHI hota
    private int $ownerId;
    private int $cashierId;
    private int $tenantId;
    private string $host;

    protected function setUp(): void
    {
        parent::setUp();

        // POST guards asli controller par chalte hain; CSRF token browser ka kaam hai, is imtihan
        // ka nahi. (Tenant-host par CSRF ka apna alag guard mojood hai — TenantLoginCsrfContext.)
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        // Host asli tenant domain hona chahiye, warna IdentifyTenant 404 deta hai aur imtihan
        // screen ko chhoo bhi nahi pata.
        $this->host = 'supfin.' . config('tenancy.tenant_base_domain');

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
            $m->table('tenants')->where('tenant_code', 'supfin')->delete();
        } catch (\Throwable) {
            // best effort — asli nateeja kabhi na chhupe
        }
        parent::tearDown();
    }

    /** Tenant ka record + domain, taake asli HTTP request is tenant par utre. */
    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenants')->where('tenant_code', 'supfin')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'supfin', 'business_name' => 'Supplier Finance',
            'owner_name' => 'Owner', 'owner_email' => 'owner@supfin.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $master->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Plan par SAARE modules khol do.
     *
     * Ye imtihan permission ka hai, plan-entitlement ka nahi. Agar koi module band reh jaye to
     * EnsureTenantSubscriptionAccess 403 deta aur cashier wala guard JHOOTA pass ho jata — wo 403
     * permission ki wajah se nahi, plan ki wajah se hota. Is liye entitlement ko raaste se hata
     * kar sirf permission ka imtihan liya jata hai.
     */
    private function seedSubscription(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $planId = $m->table('plans')->where('code', 'supfin-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'supfin-plan', 'name' => 'Supplier Finance', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert([
                'plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1,
            ]);
        }

        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert([
            'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // A. DIRECT SUPPLIER PAYMENT — bill ke baghair
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_supplier_ko_bill_ke_baghair_paisa_diya_ja_sakta_hai(): void
    {
        $opening = (float) Supplier::find($this->supplierId)->current_balance;
        $this->assertSame(50000.0, $opening, 'shuru mein 50,000 payable');

        $payment = $this->pay(12000);

        // Koi purchase bill na maanga gaya, na bana.
        $this->assertNull($payment->purchase_bill_id, 'payment kisi bill se nahi bandha');
        $this->assertSame(0, PurchaseBill::count(), 'koi purchase bill NAHI bani');

        // Supplier subledger — payable theek 12,000 ghata.
        $supplier = Supplier::find($this->supplierId);
        $this->assertSame(38000.0, (float) $supplier->current_balance, 'payable 50,000 -> 38,000');

        $row = SupplierLedger::where('reference_id', $payment->id)
            ->where('reference_type', SupplierPayment::class)->sole();
        $this->assertSame('payment', $row->entry_type);
        $this->assertSame('credit', $row->direction, 'payment supplier ko CREDIT karta hai');
        $this->assertSame(12000.0, (float) $row->amount);
        $this->assertSame(38000.0, (float) $row->balance_after, 'running balance satar par mojood');
    }

    public function test_payment_ka_gl_dr_ap_cr_chuna_hua_bank_hai(): void
    {
        $payment = $this->pay(12000);

        $entry = JournalEntry::where('source_type', 'supplier_payment')
            ->where('source_id', $payment->id)->sole();
        $entry->loadMissing('lines.account');

        $byCode = $entry->lines->mapWithKeys(fn ($l) => [
            $l->account->code => ['d' => (float) $l->debit, 'c' => (float) $l->credit],
        ]);

        $bankCode = Account::find(CashBankAccount::find($this->bankId)->account_id)->code;

        $this->assertSame(12000.0, $byCode['2100']['d'] ?? null, 'Dr Accounts Payable');
        $this->assertSame(12000.0, $byCode[$bankCode]['c'] ?? null, 'Cr wohi bank jo chuna gaya');
        $this->assertCount(2, $entry->lines, 'do satrein — na koi expense, na kuch aur');

        // Liability chukane se koi NAYA kharcha paida nahi hota.
        $types = $entry->lines->pluck('account.type')->unique()->sort()->values()->all();
        $this->assertSame(['asset', 'liability'], $types, 'sirf asset + liability — koi expense/income nahi');
    }

    public function test_cash_bank_se_paisa_theek_ek_bar_nikalta_hai(): void
    {
        $before = (float) CashBankAccount::find($this->bankId)->current_balance;

        $payment = $this->pay(12000);

        $txns = CashBankAccountTransaction::where('reference_type', 'supplier_payment')
            ->where('reference_id', $payment->id)->get();

        $this->assertCount(1, $txns, 'theek EK cash/bank harkat');
        $this->assertSame('out', $txns[0]->direction);
        $this->assertSame(12000.0, (float) $txns[0]->amount);
        $this->assertSame($before - 12000, (float) CashBankAccount::find($this->bankId)->current_balance);
    }

    public function test_payment_se_stock_bilkul_nahi_hilta(): void
    {
        $ledgerBefore  = StockLedger::count();
        $balanceBefore = StockBalance::count();

        $this->pay(12000);

        // Dono table dekhe ja rahe hain: harkat ka register aur mojooda mowjoodgi.
        // Liability chukane se maal ka koi hisab nahi badalta.
        $this->assertSame($ledgerBefore, StockLedger::count(), 'stock_ledgers par ek satar bhi nahi');
        $this->assertSame($balanceBefore, StockBalance::count(), 'stock_balances chhua bhi nahi gaya');
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // F. ATOMICITY — sab kuch ya kuch bhi nahi
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_gl_fail_ho_to_payment_subledger_aur_cash_sab_palat_jate_hain(): void
    {
        $balanceBefore = (float) Supplier::find($this->supplierId)->current_balance;
        $bankBefore    = (float) CashBankAccount::find($this->orphanBankId)->current_balance;
        $ledgerBefore  = SupplierLedger::count();

        // orphanBank ka `account_id` NULL hai, is liye cashBankCoaId() null deta hai aur
        // postSupplierPayment() koi journal nahi banata. Pehle wo chup-chaap null laut-ta tha
        // aur baqi teen commit ho chuke hote the — ab poora transaction palat-ta hai.
        try {
            $this->pay(12000, $this->orphanBankId);
            $this->fail('bina GL ke payment qabool ho gaya — atomicity toot gayi');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('general ledger', $e->getMessage());
        }

        $this->assertSame(0, SupplierPayment::count(), 'payment ki row NAHI bani');
        $this->assertSame($ledgerBefore, SupplierLedger::count(), 'subledger par ek satar bhi nahi');
        $this->assertSame($balanceBefore, (float) Supplier::find($this->supplierId)->current_balance,
            'supplier ka balance jaisa tha waisa');
        $this->assertSame($bankBefore, (float) CashBankAccount::find($this->orphanBankId)->current_balance,
            'bank ka balance jaisa tha waisa');
        $this->assertSame(0, CashBankAccountTransaction::count(), 'koi cash/bank harkat nahi');
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_payment')->count());
    }

    public function test_cash_bank_ka_hissa_fail_ho_to_bhi_sab_palat_jata_hai(): void
    {
        $balanceBefore = (float) Supplier::find($this->supplierId)->current_balance;

        // Aisa cash/bank id jo mojood hi nahi — postCashBankTransaction() ka firstOrFail()
        // (ya FK) chalta hai. HTTP par validation isay pehle rok deti hai; ye service ki
        // hifazat ka imtihan hai.
        try {
            $this->pay(12000, 999999);
            $this->fail('gum cash/bank account par payment qabool ho gaya');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, SupplierPayment::count(), 'payment ki row NAHI bani');
        $this->assertSame($balanceBefore, (float) Supplier::find($this->supplierId)->current_balance);
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_payment')->count());
    }

    public function test_subledger_ka_hissa_fail_ho_to_bhi_sab_palat_jata_hai(): void
    {
        // Gum supplier — postSupplierLedger() ka firstOrFail() chalta hai.
        try {
            app(SupplierPayableService::class)->recordPayment([
                'supplier_id'          => 999999,
                'branch_id'            => $this->branchId,
                'cash_bank_account_id' => $this->bankId,
                'payment_date'         => now()->toDateString(),
                'amount'               => 12000,
                'payment_method'       => 'cash',
            ], $this->ownerId);
            $this->fail('gum supplier par payment qabool ho gaya');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, SupplierPayment::count());
        $this->assertSame(0, CashBankAccountTransaction::count());
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_payment')->count());
    }

    public function test_wohi_payment_dobara_post_karne_par_do_bar_nahi_chadhta(): void
    {
        $payment = $this->pay(12000);

        // GL replay — JournalService (source_type, source_id) par idempotent hai.
        app(JournalPostingService::class)->postSupplierPayment($payment, $this->ownerId);
        app(JournalPostingService::class)->postSupplierPayment($payment, $this->ownerId);

        // Cash/bank replay — postCashBankTransaction bhi idempotent hai.
        app(SupplierPayableService::class)->postCashBankTransaction($payment, $this->ownerId);

        $this->assertSame(1, JournalEntry::where('source_type', 'supplier_payment')
            ->where('source_id', $payment->id)->count(), 'ek hi journal entry');
        $this->assertSame(1, CashBankAccountTransaction::where('reference_type', 'supplier_payment')
            ->where('reference_id', $payment->id)->count(), 'ek hi cash/bank harkat');
        $this->assertSame(38000.0, (float) Supplier::find($this->supplierId)->current_balance,
            'supplier ka balance ek hi bar hila');
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // I. SUPPLIER ADVANCE — support nahi, is liye FAIL CLOSED
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_payable_se_zyada_dena_mana_hai_kyunke_advance_ka_koi_account_nahi(): void
    {
        try {
            $this->pay(60000);   // payable sirf 50,000 hai
            $this->fail('overpayment qabool ho gaya — manfi payable ban gaya');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('advance', strtolower($e->getMessage()));
        }

        $this->assertSame(50000.0, (float) Supplier::find($this->supplierId)->current_balance,
            'balance chhua bhi nahi gaya');
        $this->assertSame(0, SupplierPayment::count());
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_payment')->count());
    }

    public function test_poora_payable_chukana_bilkul_jaiz_hai(): void
    {
        $this->pay(50000);

        $this->assertSame(0.0, (float) Supplier::find($this->supplierId)->current_balance,
            'theek sifar par rukna mana nahi');
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // D. GENERAL JOURNAL — AP par supplier ka dimension
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_manual_ap_credit_supplier_ka_payable_barhata_hai(): void
    {
        $entry = $this->manualJournal([
            ['code' => '5100', 'debit' => 7000, 'credit' => 0],
            ['code' => '2100', 'debit' => 0, 'credit' => 7000, 'supplier_id' => $this->supplierId],
        ]);

        $this->assertSame(57000.0, (float) Supplier::find($this->supplierId)->current_balance,
            'Cr AP -> payable barha (50,000 -> 57,000)');

        $row = SupplierLedger::where('reference_type', JournalEntry::class)
            ->where('reference_id', $entry->id)->sole();
        $this->assertSame('journal_adjustment', $row->entry_type);
        $this->assertSame('debit', $row->direction, 'AP credit ka aaina supplier DEBIT hai');
        $this->assertSame(7000.0, (float) $row->amount);
        $this->assertSame($entry->entry_no, $row->reference_no, 'satar journal ka number rakhti hai');
    }

    public function test_manual_ap_debit_supplier_ka_payable_ghatata_hai(): void
    {
        $entry = $this->manualJournal([
            ['code' => '2100', 'debit' => 9000, 'credit' => 0, 'supplier_id' => $this->supplierId],
            ['code' => '5100', 'debit' => 0, 'credit' => 9000],
        ]);

        $this->assertSame(41000.0, (float) Supplier::find($this->supplierId)->current_balance,
            'Dr AP -> payable ghata (50,000 -> 41,000)');

        $row = SupplierLedger::where('reference_type', JournalEntry::class)
            ->where('reference_id', $entry->id)->sole();
        $this->assertSame('credit', $row->direction, 'AP debit ka aaina supplier CREDIT hai');
    }

    public function test_ap_ki_satar_bina_supplier_ke_rad_hoti_hai(): void
    {
        $res = $this->actingAsOwner()->post('http://' . $this->host . '/finance/manual-journals', [
            'entry_date'  => now()->toDateString(),
            'description' => 'be-shanakht AP',
            'lines'       => [
                ['account_id' => $this->accId('5100'), 'debit' => 7000, 'credit' => 0],
                ['account_id' => $this->accId('2100'), 'debit' => 0, 'credit' => 7000],
            ],
        ]);

        $res->assertSessionHasErrors('lines.1.supplier_id');
        $this->assertSame(0, JournalEntry::where('source_type', 'manual_journal')->count(),
            'koi journal post NAHI hua');
        $this->assertSame(50000.0, (float) Supplier::find($this->supplierId)->current_balance);
    }

    public function test_ap_ki_nasl_bhi_supplier_maangti_hai(): void
    {
        // 2100 ke neeche ek sub-account. Sirf code 2100 par pehchan-ne wala guard yahan
        // dhoka kha jata — is liye poori nasl li jaati hai.
        // `normal_balance` ka koi DB default nahi hai; model khud batata hai type ka usool.
        $child = Account::create([
            'code' => '2101', 'name' => 'Local Suppliers', 'type' => 'liability',
            'normal_balance' => Account::normalBalanceForType('liability'),
            'parent_id' => $this->accId('2100'), 'is_active' => true, 'sort_order' => 111,
        ]);

        $this->assertContains((int) $child->id, app(SupplierPayableService::class)->apAccountIds(),
            'sub-account bhi AP mana jata hai');

        $res = $this->actingAsOwner()->post('http://' . $this->host . '/finance/manual-journals', [
            'entry_date'  => now()->toDateString(),
            'description' => 'sub-account se bachne ki koshish',
            'lines'       => [
                ['account_id' => $this->accId('5100'), 'debit' => 500, 'credit' => 0],
                ['account_id' => $child->id, 'debit' => 0, 'credit' => 500],
            ],
        ]);

        $res->assertSessionHasErrors('lines.1.supplier_id');
    }

    public function test_ghair_ap_journal_par_supplier_ki_koi_shart_nahi(): void
    {
        $entry = $this->manualJournal([
            ['code' => '1200', 'debit' => 3000, 'credit' => 0],
            ['code' => '5100', 'debit' => 0, 'credit' => 3000],
        ]);

        $this->assertNotNull($entry, 'AP se bahar ka journal pehle jaise post hota hai');
        $this->assertSame(50000.0, (float) Supplier::find($this->supplierId)->current_balance,
            'supplier ka balance bilkul nahi hila');
        $this->assertSame(0, SupplierLedger::where('reference_type', JournalEntry::class)->count(),
            'subledger par koi satar nahi');

        // Ghair-AP satrein counterparty le kar nahi jaatin.
        $this->assertSame(0, JournalLine::where('journal_entry_id', $entry->id)
            ->whereNotNull('counterparty_type')->count());
    }

    public function test_journal_adjustment_bhi_advance_nahi_bana_sakta(): void
    {
        try {
            $this->manualJournal([
                ['code' => '2100', 'debit' => 60000, 'credit' => 0, 'supplier_id' => $this->supplierId],
                ['code' => '5100', 'debit' => 0, 'credit' => 60000],
            ]);
            $this->fail('journal se manfi payable ban gaya');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('advance', strtolower($e->getMessage()));
        }

        $this->assertSame(50000.0, (float) Supplier::find($this->supplierId)->current_balance);
        $this->assertSame(0, JournalEntry::where('source_type', 'manual_journal')->count(),
            'journal bhi palat gaya — GL aur subledger ek saath');
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // J. REVERSAL
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_reversal_gl_aur_subledger_dono_theek_ek_bar_palatta_hai(): void
    {
        $entry = $this->manualJournal([
            ['code' => '5100', 'debit' => 7000, 'credit' => 0],
            ['code' => '2100', 'debit' => 0, 'credit' => 7000, 'supplier_id' => $this->supplierId],
        ]);
        $this->assertSame(57000.0, (float) Supplier::find($this->supplierId)->current_balance);

        $res = $this->actingAsOwner()
            ->post('http://' . $this->host . '/finance/manual-journals/' . $entry->id . '/reverse',
                ['reason' => 'ghalti']);
        $res->assertRedirect();

        $this->assertSame(50000.0, (float) Supplier::find($this->supplierId)->current_balance,
            'reversal ke baad balance wapas 50,000 — na kam, na zyada');

        $reversal = JournalEntry::where('reversed_entry_id', $entry->id)->sole();
        $rows = SupplierLedger::where('reference_type', JournalEntry::class)
            ->whereIn('reference_id', [$entry->id, $reversal->id])->get();

        $this->assertCount(2, $rows, 'asal + reversal = theek do satrein');
        $this->assertSame('journal_reversal',
            $rows->firstWhere('reference_id', $reversal->id)->entry_type);

        // Reversal ki AP satar bhi supplier ke naam hai, warna AP control supplier ke hisab se
        // jama karne par farq nazar aata.
        $apLine = JournalLine::where('journal_entry_id', $reversal->id)
            ->where('account_id', $this->accId('2100'))->sole();
        $this->assertSame('supplier', $apLine->counterparty_type);
        $this->assertSame($this->supplierId, (int) $apLine->supplier_id);
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // K. AP CONTROL = SUPPLIER SUBLEDGER
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_har_qism_ki_harkat_ke_baad_ap_control_subledger_se_milta_hai(): void
    {
        // Ek numainda safar: opening -> bill -> return -> payment -> journal credit -> journal debit.
        app(JournalPostingService::class)->postSupplierOpeningBalance(
            Supplier::find($this->supplierId), $this->ownerId
        );

        $bill = $this->postBill(30000);
        $this->postReturn($bill, 4000);
        $this->pay(12000);
        $this->manualJournal([
            ['code' => '5100', 'debit' => 7000, 'credit' => 0],
            ['code' => '2100', 'debit' => 0, 'credit' => 7000, 'supplier_id' => $this->supplierId],
        ]);
        $this->manualJournal([
            ['code' => '2100', 'debit' => 5000, 'credit' => 0, 'supplier_id' => $this->supplierId],
            ['code' => '5100', 'debit' => 0, 'credit' => 5000],
        ]);

        // Subledger ka jama — credit payable ghatata hai, debit barhata hai.
        $subledger = round(
            (float) SupplierLedger::where('supplier_id', $this->supplierId)->sum(DB::raw(
                "CASE WHEN direction = 'debit' THEN amount ELSE -amount END"
            )), 2);

        $this->assertSame(round((float) Supplier::find($this->supplierId)->current_balance, 2), $subledger,
            'supplier ka current_balance us ki satron ka jama hai');

        // AP control — 2100 credit-normal hai, is liye credit minus debit.
        $apControl = round((float) JournalLine::where('account_id', $this->accId('2100'))
            ->sum(DB::raw('credit - debit')), 2);

        $this->assertSame($subledger, $apControl,
            'SUPPLIER SUBLEDGER == ACCOUNTS PAYABLE CONTROL — koi drift nahi');
        $this->assertGreaterThan(0.0, $apControl, 'imtihan khali aankron par nahi chala');
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // Purane raaste — kuch nahi badla
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_purchase_bill_ka_bartaao_waisa_hi_hai(): void
    {
        $bill = $this->postBill(30000);

        $this->assertSame(80000.0, (float) Supplier::find($this->supplierId)->current_balance,
            'bill payable barhata hai (50,000 -> 80,000)');

        $row = SupplierLedger::where('reference_type', PurchaseBill::class)
            ->where('reference_id', $bill->id)->sole();
        $this->assertSame('purchase_bill', $row->entry_type);
        $this->assertSame('debit', $row->direction);

        $entry = JournalEntry::where('source_type', 'purchase_bill')->where('source_id', $bill->id)->first();
        $this->assertNotNull($entry, 'bill ka GL pehle jaise post hota hai');

        // Bill ki AP satar par counterparty nahi — system ke banaye journals par shart nahi.
        $apLine = JournalLine::where('journal_entry_id', $entry->id)
            ->where('account_id', $this->accId('2100'))->first();
        $this->assertNotNull($apLine);
        $this->assertNull($apLine->counterparty_type, 'system journal be-shanakht reh sakta hai — kuch nahi toota');
    }

    public function test_purchase_return_ka_bartaao_waisa_hi_hai(): void
    {
        $bill = $this->postBill(30000);
        $return = $this->postReturn($bill, 4000);

        $this->assertSame(76000.0, (float) Supplier::find($this->supplierId)->current_balance,
            'return payable ghatata hai (80,000 -> 76,000)');

        $row = SupplierLedger::where('reference_id', $return->id)
            ->where('reference_type', \App\Models\Tenant\PurchaseReturn::class)->first();
        $this->assertNotNull($row, 'return ki satar subledger par hai');
        $this->assertSame('credit', $row->direction);
    }

    public function test_supplier_opening_balance_ka_gl_waisa_hi_hai(): void
    {
        $entry = app(JournalPostingService::class)->postSupplierOpeningBalance(
            Supplier::find($this->supplierId), $this->ownerId
        );

        $this->assertNotNull($entry);
        $entry->loadMissing('lines.account');
        $byCode = $entry->lines->mapWithKeys(fn ($l) => [
            $l->account->code => ['d' => (float) $l->debit, 'c' => (float) $l->credit],
        ]);

        // Dr 3300 EQUITY / Cr 2100 — kabhi P&L nahi.
        $this->assertSame(50000.0, $byCode['3300']['d'] ?? null, 'Opening Balance Equity debit');
        $this->assertSame(50000.0, $byCode['2100']['c'] ?? null, 'Accounts Payable credit');
        $this->assertSame(['2100', '3300'], $entry->lines->pluck('account.code')->sort()->values()->all(),
            'sirf balance-sheet accounts');
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // Asli HTTP — screen sach mein render hoti hai
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_supplier_ledger_ka_safha_record_payment_aur_description_dikhata_hai(): void
    {
        $this->pay(12000);

        $res = $this->actingAsOwner()->get('http://' . $this->host . '/suppliers/' . $this->supplierId . '/ledger');
        $res->assertOk();
        $html = $res->getContent();

        $this->assertStringContainsString('Record Payment', $html, 'payment ka seedha button mojood');
        $this->assertStringContainsString('/supplier-payments/create?supplier_id=' . $this->supplierId . '&from=ledger',
            $html, 'button supplier ko pehle se chun kar le jata hai');

        foreach (['Date', 'Reference', 'Description', 'Debit', 'Credit', 'Balance'] as $col) {
            $this->assertStringContainsString('<th scope="col">' . $col . '</th>', $html, "column [$col] mojood");
        }
    }

    public function test_payment_ka_form_supplier_pehle_se_chuna_hua_dikhata_hai(): void
    {
        $res = $this->actingAsOwner()->get('http://' . $this->host
            . '/supplier-payments/create?supplier_id=' . $this->supplierId . '&from=ledger');
        $res->assertOk();
        $html = $res->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="' . $this->supplierId . '"[^>]*selected/i', $html,
            'supplier pehle se chuna hua hai'
        );
        $this->assertStringContainsString('/suppliers/' . $this->supplierId . '/ledger', $html,
            'wapsi ka pata form me mojood');
        $this->assertStringContainsString('not</strong> required', $html,
            'form saaf batata hai ke purchase bill lazmi nahi');
    }

    public function test_general_journal_ke_form_par_supplier_ka_picker_DONO_jagah_hai(): void
    {
        $res = $this->actingAsOwner()->get('http://' . $this->host . '/finance/manual-journals/create');
        $res->assertOk();
        $html = $res->getContent();

        // ⚠️ Line ka markup DO jagah hai — render hui rows, aur JS ka template row. Ek chhoot
        // jaye to "Add line" wali row par picker gayab hota aur AP posting rad hoti rehti.
        $this->assertStringContainsString('lines[0][supplier_id]', $html, 'render hui row par picker');
        $this->assertStringContainsString('lines[__I__][supplier_id]', $html, 'template row par bhi picker');
        $this->assertStringContainsString('lines[__I__][counterparty_type]', $html, 'template row par counterparty');
        $this->assertStringContainsString('AP_ACCOUNT_IDS', $html, 'AP ke ids server se aate hain');
        $this->assertStringContainsString('syncSupplierCell', $html, 'account badalne par picker khulta hai');
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // L. PERMISSIONS
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_cashier_ko_supplier_finance_ka_haq_khud_se_nahi_milta(): void
    {
        $cashier = User::on('tenant')->find($this->cashierId);

        foreach ([
            '/supplier-payments/create',
            '/suppliers/' . $this->supplierId . '/ledger',
            '/finance/manual-journals/create',
        ] as $path) {
            $res = $this->actingAs($cashier, 'tenant')->get('http://' . $this->host . $path);
            $this->assertSame(403, $res->getStatusCode(), "cashier ko [$path] par 403 milna chahiye");
        }

        // Aur post bhi nahi kar sakta.
        $res = $this->actingAs($cashier, 'tenant')->post('http://' . $this->host . '/supplier-payments', [
            'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId,
            'cash_bank_account_id' => $this->bankId, 'payment_date' => now()->toDateString(),
            'amount' => 100, 'payment_method' => 'cash',
        ]);
        $this->assertSame(403, $res->getStatusCode(), 'cashier payment post nahi kar sakta');
        $this->assertSame(0, SupplierPayment::count());
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // helpers
    // ══════════════════════════════════════════════════════════════════════════════

    private function pay(float $amount, ?int $bankId = null): SupplierPayment
    {
        return app(SupplierPayableService::class)->recordPayment([
            'supplier_id'          => $this->supplierId,
            'branch_id'            => $this->branchId,
            'cash_bank_account_id' => $bankId ?? $this->bankId,
            'payment_date'         => now()->toDateString(),
            'amount'               => $amount,
            'payment_method'       => 'cash',
            'notes'                => 'direct payment, no bill',
        ], $this->ownerId);
    }

    /** Manual journal ASLI HTTP raaste se — controller ki validation aur aaina dono chalte hain. */
    private function manualJournal(array $lines): JournalEntry
    {
        // Asli form ye teeno field hamesha bhejta hai (khali ho to khali string) — payload
        // usi shakl ka rakha hai, warna guard us raaste se guzarta hi nahi jo browser leta hai.
        $payload = [
            'entry_date'   => now()->toDateString(),
            'description'  => 'test journal',
            'reference_no' => '',
            'lines'        => [],
        ];

        foreach ($lines as $l) {
            $row = ['account_id' => $this->accId($l['code']), 'debit' => $l['debit'], 'credit' => $l['credit']];
            if (! empty($l['supplier_id'])) {
                $row['counterparty_type'] = 'supplier';
                $row['supplier_id'] = $l['supplier_id'];
            }
            $payload['lines'][] = $row;
        }

        $res = $this->actingAsOwner()->post('http://' . $this->host . '/finance/manual-journals', $payload);

        // Controller Throwable ko pakad kar form par error deta hai (500 nahi) — us soorat me
        // hum khud uthate hain, warna guard chup-chaap pass ho jata aur "rad hua" ka imtihan
        // kabhi fail hi na hota.
        //
        // ⚠️ `$res->getSession()` mojood NAHI hai (na TestResponse par, na underlying Response
        // par) — session global helper se padha jata hai.
        $errors = session('errors');
        if ($errors && $errors->any()) {
            throw new RuntimeException(implode(' | ', $errors->all()));
        }

        $res->assertRedirect();

        return JournalEntry::where('source_type', 'manual_journal')->orderByDesc('id')->firstOrFail();
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

    private function postReturn(PurchaseBill $bill, float $amount): \App\Models\Tenant\PurchaseReturn
    {
        $return = \App\Models\Tenant\PurchaseReturn::create([
            'return_no' => 'PR-' . Str::upper(Str::random(6)), 'supplier_id' => $this->supplierId,
            'branch_id' => $this->branchId, 'purchase_bill_id' => $bill->id,
            'return_date' => now()->toDateString(), 'subtotal' => $amount,
            'grand_total' => $amount, 'status' => 'posted',
        ]);

        app(PurchasingService::class)->postSupplierLedger(
            Supplier::find($this->supplierId), 'purchase_return', 'credit', $amount,
            \App\Models\Tenant\PurchaseReturn::class, $return->id, $return->return_no, null, $this->ownerId
        );
        app(JournalPostingService::class)->postPurchaseReturn($return, $this->ownerId);

        return $return->fresh();
    }

    private function actingAsOwner()
    {
        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant');
    }

    private function accId(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }

    private function bootTenant(): void
    {
        DB::setDefaultConnection('tenant');

        // permissions/roles tenant MIGRATION se aate hain — unhe kabhi truncate nahi karna.
        $this->cleanTenant([
            'journal_lines', 'journal_entries', 'supplier_ledgers', 'supplier_payments',
            'purchase_return_lines', 'purchase_returns', 'purchase_bill_lines', 'purchase_bills',
            'cash_bank_account_transactions', 'cash_bank_accounts', 'stock_ledgers', 'stock_balances',
            'model_has_roles', 'users', 'suppliers', 'accounts', 'branches',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        (new DefaultChartOfAccountsSeeder)->run();

        $c = DB::connection('tenant');

        $this->branchId = $c->table('branches')->insertGetId([
            'name' => 'Main', 'code' => 'MAIN', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $supplier = Supplier::create([
            'code' => 'SUP-' . Str::upper(Str::random(4)), 'name' => 'Direct Pay Supplier',
            'status' => 'active', 'opening_balance' => 50000, 'current_balance' => 50000,
        ]);
        $this->supplierId = $supplier->id;

        // ⚠️ Opening balance ki apni SUBLEDGER satar bhi lazmi hai — `SupplierController::store()`
        // yehi karta hai (entry_type `opening_balance`, direction debit). Pehle fixture sirf
        // `current_balance` par 50,000 rakh deti thi, jis se AP-reconciliation ka imtihan 50,000
        // ke farq se fail hua: current_balance 66,000 magar satron ka jama 16,000. Kharabi system
        // me nahi, meri fixture me thi — asli raasta dono likhta hai.
        SupplierLedger::create([
            'supplier_id'   => $supplier->id,
            'entry_type'    => 'opening_balance',
            'direction'     => 'debit',
            'amount'        => 50000,
            'balance_after' => 50000,
            'reference_no'  => 'OPENING',
        ]);

        // COA se juda hua bank — GL post hota hai.
        $this->bankId = CashBankAccount::create([
            'code' => 'BANK1', 'name' => 'Main Bank', 'account_type' => 'bank',
            'account_id' => $this->accId('1200'), 'branch_id' => $this->branchId,
            'opening_balance' => 500000, 'current_balance' => 500000, 'is_active' => true,
        ])->id;

        // Jaan-boojh kar TOOTA hua: account_id NULL, is liye GL post nahi hoga.
        $this->orphanBankId = CashBankAccount::create([
            'code' => 'BANK0', 'name' => 'Unmapped Drawer', 'account_type' => 'cash',
            'account_id' => null, 'branch_id' => $this->branchId,
            'opening_balance' => 100000, 'current_balance' => 100000, 'is_active' => true,
        ])->id;

        // EnsureRoutePermission route ke NAAM par permission maangta hai. Wo naam permissions table
        // me tenant MIGRATION se aate hain — magar ek naye test DB me wo satar mojood ho ya na ho,
        // uska bharosa nahi. Na hone par Owner ko bhi 403 milta aur guard JHOOTA fail hota.
        // updateOrInsert hai, delete nahi — migration ki tables kabhi truncate nahi karni.
        foreach ([
            'tenant.suppliers.ledger',
            'tenant.supplier-payments.index',
            'tenant.supplier-payments.create',
            'tenant.supplier-payments.store',
            'tenant.finance.manual-journals.index',
            'tenant.finance.manual-journals.create',
            'tenant.finance.manual-journals.store',
            'tenant.finance.manual-journals.show',
            'tenant.finance.manual-journals.reverse',
            'tenant.pos.index',
        ] as $permName) {
            $c->table('permissions')->updateOrInsert(
                ['name' => $permName, 'guard_name' => 'tenant'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // Owner — saari tenant permissions.
        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'SupFin Owner', 'email' => 'supfin-owner@test.local', 'password' => bcrypt('x'),
            'employee_code' => 'SUPFINOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        // Cashier — apna alag role, sirf POS ki ijazat. Supplier-finance ka koi haq NAHI:
        // naya operator type apna naam wala role leta hai, kisi doosre type ka nahi.
        $cashierRole = $c->table('roles')->where('name', 'SupFin Cashier')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'SupFin Cashier', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        $c->table('role_has_permissions')->where('role_id', $cashierRole)->delete();
        $posPerm = $c->table('permissions')->where('name', 'tenant.pos.index')->where('guard_name', 'tenant')->value('id');
        if ($posPerm) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $posPerm, 'role_id' => $cashierRole], []);
        }

        $this->cashierId = $c->table('users')->insertGetId([
            'name' => 'SupFin Cashier', 'email' => 'supfin-cashier@test.local', 'password' => bcrypt('x'),
            'employee_code' => 'SUPFINCASH', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $cashierRole, 'model_type' => User::class, 'model_id' => $this->cashierId,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
