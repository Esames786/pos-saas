<?php

namespace Tests\MySql;

use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierLedger;
use App\Models\Tenant\SupplierPayment;
use App\Models\Tenant\User;
use App\Services\Finance\SupplierPayableService;
use App\Support\VoidDate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\IsolatedPharmacyStore;

/**
 * SUPPLIER-LEDGER-DAILY-1 (3 Nov) — the supplier ledger read by the document's own date, day by day.
 *
 * The client: "ledger roz check karna hai — aaj ye invoice, us ka total, ye payment, aur balance; invoice ki date se,
 * posting date se nahi." So:
 *  - every writer stamps the row with its document's date (invoice, payment, payment void, opening balance);
 *  - the backfill dates old rows by their documents and moves nothing else;
 *  - the page puts an invoice typed late on ITS day, works the balance out in that order, and adds a day's rows up;
 *  - "All suppliers" adds every supplier; the switch off is the old screen, and the all-suppliers page is gone.
 *
 * Each test works in its own 2025 window with its own supplier, so the store's other rows never enter its numbers.
 */
class SupplierLedgerDailyMySqlTest extends MySqlTenantTestCase
{
    use IsolatedPharmacyStore;

    private const STORE_CODE = 'sldtest';
    private const STORE_SUFFIX = '_sld';
    private const STORE_FEATURES = ['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true, 'purchase_workspace' => true,
        'supplier_ledger_daily' => true];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStore();
    }

    public function test_every_document_puts_its_ledger_row_on_its_own_date(): void
    {
        $supplier = $this->newSupplier('SLD Supplier Dates');
        $bill = $this->invoice($supplier, 'SLD Oil Dates', '2025-01-05', '2025-01-06', 10, 100);   // typed today, dated 6 Jan
        $this->assertSame('2025-01-06', $this->row($supplier, 'purchase_bill')->entry_date?->toDateString(), 'the invoice sits on its own date');

        $paid = $this->pay($supplier, 300, '2025-01-08');
        $this->assertSame('2025-01-08', $this->row($supplier, 'payment')->entry_date?->toDateString(), 'the payment on its date');

        app(SupplierPayableService::class)->voidPayment($paid, 'wrong amount typed', null, VoidDate::ORIGINAL);
        $this->assertSame('2025-01-08', $this->row($supplier, 'payment_void')->entry_date?->toDateString(),
            'a void wiped on the payment\'s own date shows on that date');

        $again = $this->pay($supplier, 200, '2025-01-09');
        app(SupplierPayableService::class)->voidPayment($again, 'paid twice', null, VoidDate::TODAY);
        $this->assertSame(now()->toDateString(), SupplierLedger::where('reference_type', SupplierPayment::class)->where('reference_id', $again->id)
            ->where('entry_type', 'payment_void')->firstOrFail()->entry_date?->toDateString(), 'a void reversed today shows today');

        $this->owner()->post($this->url('/suppliers'), ['code' => 'SLD-OPEN', 'name' => 'SLD Opening Supplier', 'status' => 'active',
            'opening_balance' => 750])->assertRedirect();
        $opening = Supplier::where('code', 'SLD-OPEN')->firstOrFail();
        $this->assertSame($opening->created_at->toDateString(), $this->row($opening, 'opening_balance')->entry_date?->toDateString(),
            'an opening balance shows on the day its GL entry carries');
        $this->assertSame(PurchaseBill::class, $this->row($supplier, 'purchase_bill')->reference_type);
        $this->assertSame($bill->id, (int) $this->row($supplier, 'purchase_bill')->reference_id);
    }

    public function test_the_backfill_dates_old_rows_by_their_documents_and_moves_nothing_else(): void
    {
        $supplier = $this->newSupplier('SLD Supplier Backfill');
        $this->invoice($supplier, 'SLD Oil Backfill', '2025-02-03', '2025-02-04', 5, 200);
        $paid = $this->pay($supplier, 400, '2025-02-07');
        app(SupplierPayableService::class)->voidPayment($paid, 'cheque bounced', null, VoidDate::ORIGINAL);

        $rows = fn () => DB::connection('tenant')->table('supplier_ledgers')->where('supplier_id', $supplier->id)->orderBy('id')
            ->get(['id', 'entry_type', 'entry_date', 'amount', 'balance_after', 'updated_at']);
        $dated = $rows();
        $this->assertCount(3, $dated);
        DB::connection('tenant')->table('supplier_ledgers')->where('supplier_id', $supplier->id)->update(['entry_date' => null]);
        $books = fn () => json_encode(DB::connection('tenant')->table('supplier_ledgers')
            ->selectRaw('COUNT(*) n, ROUND(SUM(amount),4) a, ROUND(SUM(balance_after),4) b')->first());
        $before = $books();

        $this->assertSame(0, Artisan::call('finance:supplier-ledger-dates', ['tenant_code' => self::STORE_CODE]));
        $this->assertSame([null, null, null], $rows()->pluck('entry_date')->all(), 'a dry run writes nothing');

        $this->assertSame(0, Artisan::call('finance:supplier-ledger-dates', ['tenant_code' => self::STORE_CODE, '--yes' => true]));
        $this->assertSame($dated->pluck('entry_date')->all(), $rows()->pluck('entry_date')->all(),
            'every row gets back exactly the date its writer gives it today');
        $this->assertSame(['2025-02-04', '2025-02-07', '2025-02-07'], $rows()->pluck('entry_date')->all());
        $this->assertSame($before, $books(), 'amounts, balances and row count are untouched');
        $this->assertSame($dated->pluck('updated_at')->all(), $rows()->pluck('updated_at')->all(), 'no other column was written');

        $this->assertSame(0, Artisan::call('finance:supplier-ledger-dates', ['tenant_code' => self::STORE_CODE, '--yes' => true]));
        $this->assertSame($before, $books(), 'running it again changes nothing');
    }

    public function test_the_day_by_day_page_puts_an_invoice_typed_late_on_its_own_day(): void
    {
        $supplier = $this->newSupplier('SLD Supplier Days');
        $this->invoice($supplier, 'SLD Rice Days A', '2025-03-10', '2025-03-10', 10, 100);   // 1,000 on 10 Mar
        $this->pay($supplier, 300, '2025-03-12');                                              //   300 paid 12 Mar
        $this->invoice($supplier, 'SLD Rice Days B', '2025-03-11', '2025-03-11', 5, 100);    //   500 on 11 Mar, typed LAST

        $days = $this->dayRows($this->page('/suppliers/' . $supplier->id . '/ledger', '2025-03-01', '2025-03-31', 'days'));
        $this->assertSame([
            ['Mon, 10 Mar 2025', '0.00', '1,000.00', '—', '1,000.00 Cr'],
            ['Tue, 11 Mar 2025', '1,000.00 Cr', '500.00', '—', '1,500.00 Cr'],
            ['Wed, 12 Mar 2025', '1,500.00 Cr', '—', '300.00', '1,200.00 Cr'],
        ], $days, 'the late invoice sits on 11 Mar, and every day after it carries it');

        $from11 = $this->page('/suppliers/' . $supplier->id . '/ledger', '2025-03-11', '2025-03-31', 'days');
        $this->assertStringContainsString('1,000.00 Cr', $this->between($from11, 'data-ledger-opening', '</div>'), 'the balance before From is exact');

        $entries = $this->entryRows($this->page('/suppliers/' . $supplier->id . '/ledger', '2025-03-01', '2025-03-31', 'entries'));
        $this->assertSame(['Invoice', 'Invoice', 'Payment'], array_column($entries, 'type'), 'in business-date order, not typing order');
        $this->assertSame(['1,000.00 Cr', '1,500.00 Cr', '1,200.00 Cr'], array_column($entries, 'balance'));
        $this->assertSame(['—', '—', '300.00'], array_column($entries, 'debit'), 'a payment under Debit');
        $this->assertSame(['1,000.00', '500.00', '—'], array_column($entries, 'credit'), 'an invoice under Credit');
    }

    public function test_all_suppliers_adds_every_supplier_and_the_switch_off_is_the_old_screen(): void
    {
        $one = $this->newSupplier('SLD Supplier All One');
        $two = $this->newSupplier('SLD Supplier All Two');
        $this->invoice($one, 'SLD Salt All One', '2025-04-02', '2025-04-02', 4, 100);   // 400
        $this->invoice($two, 'SLD Salt All Two', '2025-04-02', '2025-04-02', 6, 100);   // 600
        $this->pay($two, 250, '2025-04-03');

        // Every supplier's rows before 1 Apr (the other tests' suppliers too) are the opening of "All suppliers".
        $open = (float) DB::connection('tenant')->table('supplier_ledgers')->whereRaw("COALESCE(entry_date, DATE(created_at)) < '2025-04-01'")
            ->sum(DB::raw("CASE WHEN direction = 'debit' THEN amount ELSE -amount END"));
        $label = fn (float $v) => \App\Services\Purchasing\SupplierRunningAccountService::balanceLabel($v);

        $days = $this->dayRows($this->page('/suppliers/ledger', '2025-04-01', '2025-04-30', 'days'));
        $this->assertSame([
            ['Wed, 02 Apr 2025', $label($open), '1,000.00', '—', $label($open + 1000)],
            ['Thu, 03 Apr 2025', $label($open + 1000), '—', '250.00', $label($open + 750)],
        ], $days, 'both suppliers\' invoices on 2 Apr, the payment on 3 Apr, on top of every supplier\'s balance before');
        $entries = $this->page('/suppliers/ledger', '2025-04-01', '2025-04-30', 'entries');
        $this->assertStringContainsString('SLD Supplier All One', $entries, 'every entry says whose it is');
        $this->assertStringContainsString('SLD Supplier All Two', $entries);

        $clerk = $this->roleUser('SLD Ledger Clerk', ['tenant.suppliers.ledger']);
        $this->actingAs($clerk, 'tenant')->get($this->url('/suppliers/ledger'))->assertForbidden();
        $this->actingAs($clerk, 'tenant')->get($this->url('/suppliers/' . $one->id . '/ledger'))->assertOk()
            ->assertDontSee('All suppliers</option>', false);

        $this->setFeatures(array_diff_key(self::STORE_FEATURES, ['supplier_ledger_daily' => true]));
        $old = $this->owner()->get($this->url('/suppliers/' . $one->id . '/ledger'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-ledger-view', $old, 'switch off: the old ledger, unchanged');
        $this->assertStringContainsString('Current Balance:', $old);
        $this->owner()->get($this->url('/suppliers/ledger'))->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /** A receipt + its invoice through the purchase workspace, dated as given (typed today). */
    private function invoice(Supplier $supplier, string $item, string $receiptDate, string $billDate, int $qty, int $price): PurchaseBill
    {
        $p = $this->product($item, $this->piece()->id, ['default_selling_price' => $price * 2]);
        $this->owner()->postJson($this->url('/purchases'), ['start' => 'new', '_entry_v2' => '1', 'branch_id' => $this->branchId(),
            'supplier_id' => $supplier->id, 'receipt_date' => $receiptDate,
            'lines' => [['product_id' => $p->id, 'buy_pack' => 'base', 'price' => (string) $price, 'packs' => ['base' => (string) $qty]]],
            'record_invoice' => '1', 'invoice' => ['bill_date' => $billDate]])->assertOk();

        return PurchaseBill::where('supplier_id', $supplier->id)->latest('id')->firstOrFail();
    }

    private function pay(Supplier $supplier, float $amount, string $date): SupplierPayment
    {
        return app(SupplierPayableService::class)->recordPayment(['supplier_id' => $supplier->id, 'branch_id' => $this->branchId(),
            'cash_bank_account_id' => (int) DB::connection('tenant')->table('cash_bank_accounts')->where('is_active', 1)->orderBy('id')->value('id'),
            'payment_method' => 'cash', 'payment_date' => $date, 'amount' => $amount]);
    }

    private function row(Supplier $supplier, string $type): SupplierLedger
    {
        return SupplierLedger::where('supplier_id', $supplier->id)->where('entry_type', $type)->latest('id')->firstOrFail();
    }

    private function newSupplier(string $name): Supplier
    {
        return Supplier::create(['code' => 'SLD-' . strtoupper(substr(md5($name), 0, 6)), 'name' => $name, 'status' => 'active',
            'opening_balance' => 0, 'current_balance' => 0]);
    }

    private function page(string $path, string $from, string $to, string $view): string
    {
        return $this->owner()->get($this->url($path . '?' . http_build_query(compact('from', 'to', 'view'))))->assertOk()->getContent();
    }

    /** @return list<list<string>> date, opening, credit, debit, closing */
    private function dayRows(string $html): array
    {
        $table = $this->between($html, 'data-ledger-view="days"', '</table>');
        preg_match_all('#<tr>\s*<td>(.*?)</td>\s*<td class="text-end">(.*?)</td>\s*<td class="text-end">(.*?)</td>\s*'
            . '<td class="text-end">(.*?)</td>\s*<td class="text-end fw-semibold">(.*?)</td>#s', $table, $m, PREG_SET_ORDER);

        return array_map(fn ($r) => array_map(fn ($c) => trim(html_entity_decode(strip_tags($c))), array_slice($r, 1)), $m);
    }

    /** @return list<array{type:string, debit:string, credit:string, balance:string}> */
    private function entryRows(string $html): array
    {
        $table = $this->between($html, 'data-ledger-view="entries"', '</table>');
        $body = $this->between($table, '<tbody>', '</tbody>');
        preg_match_all('#<tr>(.*?)</tr>#s', $body, $rows);
        $out = [];
        foreach ($rows[1] as $tr) {
            preg_match_all('#<td[^>]*>(.*?)</td>#s', $tr, $c);
            $cells = array_map(fn ($v) => trim(html_entity_decode(strip_tags($v))), $c[1]);
            $out[] = ['type' => $cells[1], 'debit' => $cells[4], 'credit' => $cells[5], 'balance' => $cells[6]];
        }

        return $out;
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "page has {$from}");
        $end = strpos($html, $to, $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    private function roleUser(string $roleName, array $permissions): User
    {
        DB::setDefaultConnection('tenant');
        $role = Role::findOrCreate($roleName, 'tenant');
        $role->syncPermissions($permissions);
        $user = User::on('tenant')->firstOrCreate(['email' => strtolower(str_replace(' ', '.', $roleName)) . '@sldtest.test'],
            ['name' => $roleName, 'password' => Hash::make('secret123'), 'status' => 'active']);
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
