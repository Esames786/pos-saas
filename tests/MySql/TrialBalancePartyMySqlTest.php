<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Finance\TrialBalanceController;
use App\Models\Tenant\ExpenseVoucher;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\SupplierPayment;
use App\Services\Finance\JournalPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * TRIAL-BALANCE-PERIOD-1 part 2 — the parties under each control account, Print, and the supplier
 * named on Accounts Payable lines at the root.
 *
 * Kashif Kitchen's shape: two suppliers (one billed and paid the same day), two cash accounts on
 * one GL account, catering receipts (one saved without a cash account), an expense voucher split over
 * two categories, manual journals, and one bill line posted before the supplier was written on it.
 * Bills, payments and the voucher go through the real JournalPostingService.
 */
class TrialBalancePartyMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private array $acc = [];
    private int $mainCash;
    private int $cashInHand;
    private int $abChicken;
    private int $faisal;
    private int $legacyLineId;
    private int $orphanLineId;

    private const OCT = ['date_from' => '2026-10-01', 'date_to' => '2026-10-06'];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'supplier_ledgers', 'supplier_payments',
            'purchase_bills', 'expense_voucher_lines', 'expense_vouchers', 'expense_categories', 'catering_advances',
            'catering_refunds', 'catering_final_invoices', 'catering_events', 'cash_bank_accounts', 'suppliers', 'accounts', 'branches',
        ]);
        $this->branchId = $this->makeBranch(['name' => 'Kashif Kitchen — Main']);
        foreach ([
            ['1110', 'Main Cash', 'asset', 'debit'], ['1300', 'Accounts Receivable', 'asset', 'debit'],
            ['1400', 'Inventory Asset', 'asset', 'debit'], ['2100', 'Accounts Payable', 'liability', 'credit'],
            ['2300', 'Customer Advances', 'liability', 'credit'], ['3300', 'Opening Balance Equity', 'equity', 'credit'],
            ['6800', 'Miscellaneous Expense', 'expense', 'debit'], ['6810', 'Site / Operating Expense', 'expense', 'debit'],
        ] as [$c, $n, $t, $nb]) {
            $this->acc[$c] = DB::table('accounts')->insertGetId(['code' => $c, 'name' => $n, 'type' => $t, 'normal_balance' => $nb, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->mainCash = DB::table('cash_bank_accounts')->insertGetId(['account_id' => $this->acc['1110'], 'branch_id' => $this->branchId, 'code' => 'CB-MAIN', 'name' => 'Main Cash Drawer', 'created_at' => now(), 'updated_at' => now()]);
        $this->cashInHand = DB::table('cash_bank_accounts')->insertGetId(['account_id' => $this->acc['1110'], 'branch_id' => $this->branchId, 'code' => 'CB-HAND', 'name' => 'Cash in Hand', 'created_at' => now(), 'updated_at' => now()]);
        $this->abChicken = DB::table('suppliers')->insertGetId(['code' => 'SUP-AB', 'name' => 'AB CHICKEN NEW', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->faisal = DB::table('suppliers')->insertGetId(['code' => 'SUP-FB', 'name' => 'FAISAL BEEF COUNTER (KHATRI)', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        // Posted before the fix: the 2100 line carries no supplier. Opening for October.
        $legacy = $this->bill('BILL-OLD-1', $this->faisal, 50000, '2026-09-20');
        $this->legacyLineId = (int) DB::table('journal_lines')->where('journal_entry_id', $legacy)->where('account_id', $this->acc['2100'])->value('id');
        DB::table('journal_lines')->where('id', $this->legacyLineId)->update(['supplier_id' => null, 'counterparty_type' => null]);

        $this->payment('PAY-FB-1', $this->faisal, 117600, '2026-10-01', $this->cashInHand);
        $this->bill('BILL-FB-1', $this->faisal, 823200, '2026-10-02');
        $this->bill('BILL-AB-1', $this->abChicken, 19650, '2026-10-03');
        $this->payment('PAY-AB-1', $this->abChicken, 19650, '2026-10-03', $this->mainCash);
        $this->bill('BILL-AB-2', $this->abChicken, 22500, '2026-10-06');

        // Catering receipts → 2300 by booking, 1110 by cash account.
        $alfakhar = $this->event('EV-20261005-0168', 'ALFAKHAR HERBAL SHOP');
        $umair = $this->event('EV-20261004-0157', 'mr.s umair');
        $this->receipt($alfakhar, 20000, '2026-10-05', $this->mainCash);
        $this->receipt($umair, 5000, '2026-10-04', null);   // saved without a cash account

        // An expense voucher over two categories on 6810.
        $this->voucher('2026-10-02', [['6810-1', 'Site / Operating', 5290], ['6810-2', 'Supervisor Fee', 4800]]);

        // Manual journals: one cash line on Cash in Hand; one AP line with no supplier and no cash link.
        $m1 = $this->manual('2026-10-04', [['6810', 1000, 0], ['1110', 0, 1000]]);
        DB::table('cash_bank_account_transactions')->insert(['cash_bank_account_id' => $this->cashInHand, 'transaction_date' => '2026-10-04', 'direction' => 'out', 'amount' => 1000, 'balance_after' => 0, 'transaction_type' => 'manual_journal', 'reference_type' => 'manual_journal', 'reference_id' => $m1, 'created_at' => now(), 'updated_at' => now()]);
        $m2 = $this->manual('2026-10-05', [['2100', 300, 0], ['1110', 0, 300]]);
        $this->orphanLineId = (int) DB::table('journal_lines')->where('journal_entry_id', $m2)->where('account_id', $this->acc['2100'])->value('id');

        view()->share('errors', new ViewErrorBag);
    }

    protected function tearDown(): void
    {
        // The command test registers this DB as a tenant in the SHARED master DB. Left behind, it
        // breaks every later test that registers the same DB (unique tenant_databases.db_database).
        try {
            $master = DB::connection('master');
            $master->table('tenant_databases')->where('db_database', $this->tenantDb)
                ->whereIn('tenant_id', $master->table('tenants')->where('tenant_code', 'like', 'aptest%')->pluck('id'))->delete();
            $master->table('tenants')->where('tenant_code', 'like', 'aptest%')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    private function bill(string $no, int $supplier, float $amount, string $date): int
    {
        $bill = PurchaseBill::create(['bill_no' => $no, 'supplier_id' => $supplier, 'branch_id' => $this->branchId, 'bill_date' => $date, 'subtotal' => $amount, 'grand_total' => $amount, 'balance_due' => $amount, 'status' => 'posted']);

        return (int) app(JournalPostingService::class)->postPurchaseBill($bill)->id;
    }

    private function payment(string $no, int $supplier, float $amount, string $date, int $cash): void
    {
        $p = SupplierPayment::create(['payment_no' => $no, 'supplier_id' => $supplier, 'branch_id' => $this->branchId, 'payment_date' => $date, 'amount' => $amount, 'cash_bank_account_id' => $cash]);
        app(JournalPostingService::class)->postSupplierPayment($p);
    }

    private function event(string $no, string $customer): int
    {
        return DB::table('catering_events')->insertGetId(['event_no' => $no, 'customer_name' => $customer, 'booking_date' => '2026-10-01', 'event_date' => '2026-10-10', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function receipt(int $eventId, float $amount, string $date, ?int $cash): void
    {
        $id = DB::table('catering_advances')->insertGetId(['catering_event_id' => $eventId, 'amount' => $amount, 'received_date' => $date, 'cash_bank_account_id' => $cash, 'created_at' => now(), 'updated_at' => now()]);
        $this->journal($date, 'catering_advance', $id, [['1110', $amount, 0], ['2300', 0, $amount]]);
    }

    private function voucher(string $date, array $lines): void
    {
        $total = array_sum(array_column($lines, 2));
        $vid = DB::table('expense_vouchers')->insertGetId(['voucher_no' => 'EXP-' . uniqid(), 'branch_id' => $this->branchId, 'cash_bank_account_id' => $this->mainCash, 'expense_date' => $date, 'status' => 'posted', 'subtotal' => $total, 'total_amount' => $total, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($lines as [$code, $name, $amount]) {
            $cat = DB::table('expense_categories')->insertGetId(['account_id' => $this->acc['6810'], 'code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('expense_voucher_lines')->insert(['expense_voucher_id' => $vid, 'expense_category_id' => $cat, 'account_id' => $this->acc['6810'], 'amount' => $amount, 'line_total' => $amount, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(JournalPostingService::class)->postExpenseVoucher(ExpenseVoucher::on('tenant')->findOrFail($vid));
    }

    private function manual(string $date, array $lines): int
    {
        return $this->journal($date, 'manual_journal', random_int(1000, 999999), $lines);
    }

    private function journal(string $date, string $type, int $sourceId, array $lines): int
    {
        $id = DB::table('journal_entries')->insertGetId(['entry_no' => 'JE-' . uniqid(), 'entry_date' => $date, 'status' => 'posted', 'source_type' => $type, 'source_id' => $sourceId, 'description' => $type, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($lines as [$code, $dr, $cr]) {
            DB::table('journal_lines')->insert(['journal_entry_id' => $id, 'account_id' => $this->acc[$code], 'branch_id' => $this->branchId, 'debit' => $dr, 'credit' => $cr, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    private function page(array $params)
    {
        return app(TrialBalanceController::class)->index(Request::create('/finance/trial-balance', 'GET', $params));
    }

    /** code => [account row, parties keyed by label] as the screen gets them. */
    private function screen(array $params = self::OCT): array
    {
        $d = $this->page($params)->getData();
        $out = [];
        foreach ($d['rows'] as $r) {
            $out[(string) $r['code']] = [$r, collect($d['parties'][$r['account_id']] ?? [])->keyBy('label')->all()];
        }

        return $out;
    }

    private function six(array $r): array
    {
        return array_map(fn ($k) => round($r[$k], 2), ['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'closing_debit', 'closing_credit']);
    }

    // ── guards ───────────────────────────────────────────────────────────────────

    public function test_every_party_block_adds_up_to_its_account(): void
    {
        foreach ([self::OCT, ['date_from' => '2026-10-03', 'date_to' => '2026-10-03'], ['date_from' => '2026-09-01', 'date_to' => '2026-10-06']] as $period) {
            foreach ($this->screen($period) as $code => [$row, $parties]) {
                if (! $parties) {
                    continue;
                }
                // Openings and closings are netted per party, so compare the NET of each pair plus the movement.
                $net = fn (array $r, string $a, string $b) => round($r[$a] - $r[$b], 2);
                $sum = fn (string $k) => round(array_sum(array_column($parties, $k)), 2);
                $this->assertSame($net($row, 'opening_debit', 'opening_credit'), round($sum('opening_debit') - $sum('opening_credit'), 2), "$code opening");
                $this->assertSame(round($row['period_debit'], 2), $sum('period_debit'), "$code debit");
                $this->assertSame(round($row['period_credit'], 2), $sum('period_credit'), "$code credit");
                $this->assertSame($net($row, 'closing_debit', 'closing_credit'), round($sum('closing_debit') - $sum('closing_credit'), 2), "$code closing");
            }
        }
    }

    public function test_suppliers_under_accounts_payable(): void
    {
        [, $p] = $this->screen()['2100'];

        $this->assertSame([0.0, 0.0, 19650.0, 42150.0, 0.0, 22500.0], $this->six($p['AB CHICKEN NEW']));
        // The legacy line has no supplier on it; its bill names FAISAL, so its 50,000 opening lands there.
        $this->assertSame([0.0, 50000.0, 117600.0, 823200.0, 0.0, 755600.0], $this->six($p['FAISAL BEEF COUNTER (KHATRI)']));
        $this->assertSame([0.0, 0.0, 300.0, 0.0, 300.0, 0.0], $this->six($p['Unassigned']), 'a manual AP line with no supplier is shown, not dropped');
        $this->assertSame('Unassigned', array_key_last($p), 'the catch-all comes last');
    }

    public function test_a_supplier_paid_in_full_still_shows(): void
    {
        // The owner's original question — From = To = 3 Oct.
        [, $p] = $this->screen(['date_from' => '2026-10-03', 'date_to' => '2026-10-03'])['2100'];

        $this->assertSame([0.0, 0.0, 19650.0, 19650.0, 0.0, 0.0], $this->six($p['AB CHICKEN NEW']));
    }

    public function test_bookings_under_customer_advances(): void
    {
        [, $p] = $this->screen()['2300'];

        $this->assertSame([0.0, 0.0, 0.0, 20000.0, 0.0, 20000.0], $this->six($p['EV-20261005-0168 · ALFAKHAR HERBAL SHOP']));
        $this->assertArrayHasKey('EV-20261004-0157 · mr.s umair', $p);
    }

    public function test_cash_split_by_cash_account(): void
    {
        [, $p] = $this->screen()['1110'];

        // Main: +20,000 receipt − 19,650 AB payment − 10,090 voucher.
        $this->assertSame([0.0, 0.0, 20000.0, 29740.0, 0.0, 9740.0], $this->six($p['Main Cash Drawer']));
        // In hand: −117,600 FAISAL payment − 1,000 manual journal (via its cash transaction).
        $this->assertSame([0.0, 0.0, 0.0, 118600.0, 0.0, 118600.0], $this->six($p['Cash in Hand']));
        $this->assertSame([0.0, 0.0, 5000.0, 0.0, 5000.0, 0.0], $this->six($p['No cash/bank account on the receipt']));
        $this->assertSame([0.0, 0.0, 0.0, 300.0, 0.0, 300.0], $this->six($p['Unassigned']), 'a manual cash line with no cash account named');
    }

    public function test_expenses_split_by_category(): void
    {
        [, $p] = $this->screen()['6810'];

        $this->assertSame(5290.0, round($p['6810-1 · Site / Operating']['period_debit'], 2));
        $this->assertSame(4800.0, round($p['6810-2 · Supervisor Fee']['period_debit'], 2));
        $this->assertSame(1000.0, round($p['Unassigned']['period_debit'], 2), 'the manual journal on 6810 has no category');
    }

    public function test_new_postings_name_their_supplier_without_doubling_the_supplier_ledger(): void
    {
        $before = DB::table('supplier_ledgers')->count();
        $entry = $this->bill('BILL-AB-3', $this->abChicken, 100, '2026-10-06');

        $line = DB::table('journal_lines')->where('journal_entry_id', $entry)->where('account_id', $this->acc['2100'])->first();
        $this->assertSame($this->abChicken, (int) $line->supplier_id);
        $this->assertSame('supplier', $line->counterparty_type);
        $this->assertSame($before, DB::table('supplier_ledgers')->count(), 'posting the journal writes nothing to the supplier ledger');

        $pay = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.source_type', 'supplier_payment')->where('l.account_id', $this->acc['2100'])->pluck('l.supplier_id')->map(fn ($v) => (int) $v)->all();
        $this->assertEqualsCanonicalizing([$this->faisal, $this->abChicken], $pay);
    }

    public function test_the_backfill_names_old_lines_only_with_yes(): void
    {
        $code = $this->tenantCode();

        $this->artisan('finance:backfill-ap-suppliers', ['tenant_code' => $code])->assertSuccessful();
        $this->assertNull(DB::table('journal_lines')->where('id', $this->legacyLineId)->value('supplier_id'), 'a dry run writes nothing');

        $this->artisan('finance:backfill-ap-suppliers', ['tenant_code' => $code, '--yes' => true])->assertSuccessful();
        DB::setDefaultConnection('tenant');
        $legacy = DB::table('journal_lines')->where('id', $this->legacyLineId)->first();
        $this->assertSame($this->faisal, (int) $legacy->supplier_id);
        $this->assertSame('supplier', $legacy->counterparty_type);
        $this->assertSame(50000.0, (float) $legacy->credit, 'metadata only — the amount is untouched');
        $this->assertNull(DB::table('journal_lines')->where('id', $this->orphanLineId)->value('supplier_id'), 'no source supplier → left alone');
    }

    public function test_screen_toggle_csv_and_print(): void
    {
        $html = $this->page(self::OCT)->render();
        $this->assertStringContainsString('AB CHICKEN NEW', $html);
        $this->assertMatchesRegularExpression('/id="tb-parties"\s+checked/', $html, 'party detail is on by default');
        $this->assertStringContainsString('id="tb-print"', $html);

        $this->assertStringNotContainsString('AB CHICKEN NEW', $this->page(self::OCT + ['parties' => '0'])->render(), 'the toggle hides them');

        $csvResponse = $this->page(self::OCT + ['export_csv' => 1]);
        ob_start();
        $csvResponse->sendContent();
        $csv = (string) ob_get_clean();
        $this->assertStringContainsString('"    AB CHICKEN NEW",Party,0.00,19650.00,42150.00,"22500.00 Cr"', $csv);

        $pdf = $this->page(self::OCT + ['format' => 'pdf']);
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertGreaterThan(3000, strlen($pdf->getContent()));
    }

    /** Register this test's DB as a tenant so the command can activate it (as the catering command tests do). */
    private function tenantCode(): string
    {
        $master = DB::connection('master');
        $code = $master->table('tenants')->join('tenant_databases as d', 'd.tenant_id', '=', 'tenants.id')
            ->where('d.db_database', $this->tenantDb)->value('tenants.tenant_code');
        if (! $code) {
            $code = 'aptest' . substr(md5($this->tenantDb), 0, 6);
            $master->table('tenants')->updateOrInsert(['tenant_code' => $code], ['business_name' => 'AP Backfill Test', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenantId = $master->table('tenants')->where('tenant_code', $code)->value('id');
        $cfg = config('database.connections.tenant');
        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenant_databases')->insert([
            'tenant_id' => $tenantId, 'db_connection' => 'tenant', 'db_host' => $cfg['host'] ?? '127.0.0.1', 'db_port' => $cfg['port'] ?? 3306,
            'db_database' => $this->tenantDb, 'db_username' => $cfg['username'] ?? 'root',
            'db_password' => Crypt::encryptString((string) ($cfg['password'] ?? '')),
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $code;
    }
}
