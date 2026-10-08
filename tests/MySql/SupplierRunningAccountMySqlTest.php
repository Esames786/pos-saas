<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\GoodsReceiptController;
use App\Http\Controllers\Tenant\PurchaseBillController;
use App\Http\Controllers\Tenant\Reports\PurchaseReportController;
use App\Http\Controllers\Tenant\SupplierController;
use App\Http\Controllers\Tenant\SupplierPaymentController;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchasingSetting;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierCreditAllocation;
use App\Models\Tenant\SupplierPayment;
use App\Services\Finance\SupplierPayableService;
use App\Services\Purchasing\PurchaseReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Tests\MySql\Support\ParsesInlineScripts;
use Tests\MySql\Support\TenantFixtures;

/**
 * SUPPLIER-RUNNING-ACCOUNT-1 — pay a supplier on account (owner, kashifkitchen, 8 Oct).
 *
 * A payment may exceed what is owed; the extra stays in 2100 as an advance (no new account). Every
 * credit — payment or return — settles bills: its own bill first, then the oldest. A new bill takes
 * an existing advance first. OFF (every other tenant): today's refusal and today's bill update.
 *
 * Real paths throughout: GRN and bill through their controllers, payments through
 * SupplierPayableService::recordPayment, returns through PurchaseReturnService::post.
 */
class SupplierRunningAccountMySqlTest extends MySqlTenantTestCase
{
    use ParsesInlineScripts;
    use TenantFixtures;

    private int $branchId;
    private int $supplierId;
    private int $item;
    private int $bankId;
    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'supplier_credit_allocations', 'purchasing_settings', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'journal_lines', 'journal_entries', 'supplier_payments', 'purchase_return_lines', 'purchase_returns', 'purchase_bill_lines',
            'purchase_bills', 'supplier_ledgers', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'goods_receipt_lines', 'goods_receipts', 'product_variants', 'products', 'categories', 'suppliers', 'branches', 'accounts',
        ]);
        foreach ([['1200', 'Bank', 'asset', 'debit'], ['1400', 'Inventory Asset', 'asset', 'debit'],
            ['2100', 'Accounts Payable', 'liability', 'credit'], ['5100', 'Product COGS', 'expense', 'debit']] as [$c, $n, $t, $nb]) {
            $this->acc[$c] = DB::table('accounts')->insertGetId(['code' => $c, 'name' => $n, 'type' => $t, 'normal_balance' => $nb, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->branchId = $this->makeBranch(['status' => 'active']);
        // Bought for the kitchen, not counted — so a receipt needs no stock rules to post.
        $this->item = $this->makeProduct($this->makeCategory(), ['name' => 'Beef', 'is_sellable' => 0, 'is_pos_visible' => 0, 'is_purchasable' => 1, 'is_stock_tracked' => 0]);
        $this->supplierId = DB::table('suppliers')->insertGetId(['code' => 'SUP-FB', 'name' => 'Faisal Beef', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->bankId = DB::table('cash_bank_accounts')->insertGetId([
            'code' => 'KK-CASH', 'name' => 'Cash in Hand', 'account_type' => 'cash', 'account_id' => $this->acc['1200'],
            'branch_id' => $this->branchId, 'opening_balance' => 0, 'current_balance' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        view()->share('errors', new ViewErrorBag);
    }

    private function on(): void
    {
        PurchasingSetting::tenantDefault()->update(['supplier_running_account' => true]);
    }

    /** A received and billed purchase of $total, dated $date. */
    private function bill(float $total, string $date = '2026-10-01'): PurchaseBill
    {
        app(GoodsReceiptController::class)->store(Request::create('/goods-receipts', 'POST', [
            'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId, 'receipt_date' => $date,
            'lines' => [['product_id' => $this->item, 'quantity_received' => 1, 'unit_cost' => $total]],
        ]));
        $grn = GoodsReceipt::on('tenant')->latest('id')->firstOrFail();
        app(PurchaseBillController::class)->store(Request::create('/purchase-bills', 'POST', ['goods_receipt_id' => $grn->id, 'bill_date' => $date]));

        return PurchaseBill::latest('id')->firstOrFail();
    }

    private function pay(float $amount, ?int $billId = null, string $date = '2026-10-05'): SupplierPayment
    {
        return app(SupplierPayableService::class)->recordPayment([
            'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId, 'cash_bank_account_id' => $this->bankId,
            'purchase_bill_id' => $billId, 'payment_date' => $date, 'amount' => $amount, 'payment_method' => 'cash',
        ]);
    }

    private function balance(): float
    {
        return round((float) Supplier::find($this->supplierId)->current_balance, 2);
    }

    /** bill id => [amount_paid, balance_due, status] */
    private function bills(): array
    {
        return PurchaseBill::orderBy('id')->get()->mapWithKeys(fn ($b) => [$b->id => [round((float) $b->amount_paid, 2), round((float) $b->balance_due, 2), $b->status]])->all();
    }

    public function test_off_still_refuses_paying_beyond_the_balance_word_for_word(): void
    {
        $bill = $this->bill(1000);
        try {
            $this->pay(1500, $bill->id);
            $this->fail('paid beyond the balance with the switch OFF');
        } catch (RuntimeException $e) {
            $this->assertSame('This would leave the supplier with a negative payable (an advance of 500.00), and supplier advances are not supported '
                . 'by the chart of accounts. Nothing was saved. Reduce the amount to the outstanding balance, or ask the owner to set up a supplier-advance account first.', $e->getMessage());
        }
        $this->assertSame(0, SupplierPayment::count());
        $this->assertSame([$bill->id => [0.0, 1000.0, 'posted']], $this->bills());
    }

    public function test_off_keeps_the_old_bill_update(): void
    {
        $bill = $this->bill(1000);
        $this->pay(400, $bill->id);

        $this->assertSame([$bill->id => [400.0, 600.0, 'partial']], $this->bills());
        $this->assertSame(0, SupplierCreditAllocation::count(), 'OFF writes no allocations');
    }

    public function test_on_pays_ahead_with_no_bill_and_the_next_bill_takes_it(): void
    {
        $this->on();
        $payment = $this->pay(50000);

        $this->assertSame(-50000.0, $this->balance());
        $journal = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.source_type', 'supplier_payment')->where('e.source_id', $payment->id)
            ->get(['l.account_id', 'l.debit', 'l.credit'])->mapWithKeys(fn ($l) => [$l->account_id => [(float) $l->debit, (float) $l->credit]])->all();
        $this->assertEquals([$this->acc['2100'] => [50000.0, 0.0], $this->acc['1200'] => [0.0, 50000.0]], $journal, 'Dr 2100 / Cr cash, no new account');

        $ledger = app(SupplierController::class)->ledger(Supplier::find($this->supplierId))->render();
        $this->assertStringContainsString('Advance 50,000.00 (Dr)', $ledger);

        $bill = $this->bill(30000, '2026-10-06');
        $this->assertSame([$bill->id => [30000.0, 0.0, 'paid']], $this->bills(), 'the advance pays the new bill');
        $this->assertSame(30000.0, (float) SupplierCreditAllocation::where('purchase_bill_id', $bill->id)->where('source_id', $payment->id)->sum('amount'));
        $this->assertSame(-20000.0, $this->balance());
    }

    public function test_paying_a_bill_more_than_it_owes_pays_it_once_and_keeps_the_rest(): void
    {
        $this->on();
        $first = $this->bill(1000);
        $this->pay(1500, $first->id);

        $this->assertSame([$first->id => [1000.0, 0.0, 'paid']], $this->bills(), 'amount_paid never above the bill');
        $this->assertSame(-500.0, $this->balance());

        $second = $this->bill(2000, '2026-10-07');
        $this->assertSame([1000.0, 0.0, 'paid'], $this->bills()[$first->id]);
        $this->assertSame([500.0, 1500.0, 'partial'], $this->bills()[$second->id]);
        $this->assertSame(1500.0, $this->balance());
    }

    public function test_a_general_payment_settles_the_oldest_bills_first(): void
    {
        $this->on();
        $b3 = $this->bill(1000, '2026-10-03');   // created first, dated last — date decides, not id
        $b1 = $this->bill(1000, '2026-10-01');
        $b2 = $this->bill(1000, '2026-10-02');
        $this->pay(2500);

        $bills = $this->bills();
        $this->assertSame([1000.0, 0.0, 'paid'], $bills[$b1->id]);
        $this->assertSame([1000.0, 0.0, 'paid'], $bills[$b2->id]);
        $this->assertSame([500.0, 500.0, 'partial'], $bills[$b3->id]);
        $this->assertSame($this->balance(), round(array_sum(array_column($bills, 1)), 2), 'bills due = the ledger');
    }

    public function test_a_return_settles_its_own_bill_first(): void
    {
        $this->on();
        $older = $this->bill(1000, '2026-10-01');
        $newer = $this->bill(1000, '2026-10-02');
        $grn = GoodsReceipt::on('tenant')->with('lines')->findOrFail($newer->goods_receipt_id);

        $service = app(PurchaseReturnService::class);
        $return = $service->createDraft(
            ['branch_id' => $this->branchId, 'supplier_id' => $this->supplierId, 'goods_receipt_id' => $grn->id, 'return_date' => '2026-10-04'],
            [['product_id' => $this->item, 'source_line_id' => $grn->lines->first()->id, 'quantity' => 0.4, 'unit_cost' => 1000]]
        );
        $service->post($return);

        $this->assertSame([0.0, 1000.0, 'posted'], $this->bills()[$older->id], 'the older bill is not the one returned against');
        $this->assertSame([400.0, 600.0, 'partial'], $this->bills()[$newer->id]);
        $this->assertSame(1600.0, $this->balance());
    }

    public function test_switching_on_records_old_payments_once_and_settles_general_ones(): void
    {
        // Before the switch, the way kashifkitchen is today: a bill-wise payment and a general one.
        $b1 = $this->bill(1000, '2026-10-01');
        $b2 = $this->bill(2000, '2026-10-02');
        $this->pay(300, $b1->id);
        $this->pay(1200);   // FAISAL's case: lowers the ledger, settles no bill
        $this->assertSame(1500.0, $this->balance());
        $this->assertSame(2700.0, round(array_sum(array_column($this->bills(), 1)), 2), 'bills say 1,200 more than the ledger');

        $tenantCode = $this->registerTenant();
        $books = fn () => [DB::table('journal_lines')->count(), DB::table('journal_lines')->sum('debit'),
            DB::table('supplier_ledgers')->count(), DB::table('supplier_ledgers')->sum('amount'), $this->balance()];
        $before = $books();
        $billsBefore = $this->bills();

        $this->assertSame(0, Artisan::call('finance:supplier-running-account', ['tenant_code' => $tenantCode, '--enable' => true]));
        $this->assertStringContainsString('DRY RUN', Artisan::output());
        $this->assertSame($billsBefore, $this->bills(), 'a dry run writes nothing');
        $this->assertSame(0, SupplierCreditAllocation::count());
        $this->assertFalse(PurchasingSetting::supplierRunningAccount());

        $this->assertSame(0, Artisan::call('finance:supplier-running-account', ['tenant_code' => $tenantCode, '--enable' => true, '--yes' => true]));
        DB::setDefaultConnection('tenant');
        $this->assertTrue(PurchasingSetting::supplierRunningAccount());
        $this->assertSame([1000.0, 0.0, 'paid'], $this->bills()[$b1->id], '300 bill-wise + 700 of the general payment');
        $this->assertSame([500.0, 1500.0, 'partial'], $this->bills()[$b2->id]);
        $this->assertSame($this->balance(), round(array_sum(array_column($this->bills(), 1)), 2), 'bills due = the ledger');
        $this->assertSame($before, $books(), 'journals and supplier ledgers untouched');

        // The 300 paid against bill 1 before the switch must not be spent again.
        $b3 = $this->bill(100, '2026-10-08');
        $this->assertSame([0.0, 100.0, 'posted'], $this->bills()[$b3->id]);
    }

    public function test_the_payment_form_says_advance_before_save(): void
    {
        $this->on();
        $this->pay(5000);
        $html = app(SupplierPaymentController::class)->create(Request::create('/supplier-payments/create', 'GET'))->render();

        $this->assertMatchesRegularExpression('/id="advance-warn" data-running-account="1"/', $html);
        $this->assertStringContainsString('running-account-note', $html);
        $this->assertStringContainsString('Faisal Beef', $html);
        $this->assertStringContainsString('(Advance 5,000.00 (Dr))', $html, 'never a bare minus in the supplier list');
        $this->assertInlineScriptsParse($html, 'supplier payment form', 'advance-warn');
    }

    public function test_aging_lists_suppliers_in_advance_apart_from_what_is_due(): void
    {
        $this->on();
        $this->pay(5000);
        $html = app(PurchaseReportController::class)->payables(Request::create('/reports/purchases/payables', 'GET'))->render();

        $this->assertStringContainsString('id="suppliers-in-advance"', $html);
        $this->assertMatchesRegularExpression('/Faisal Beef<\/a><\/td>\s*<td class="text-end">5,000\.00<\/td>/', $html);
    }

    /** The command finds a tenant by code in master and activates its database — this test DB. */
    private function registerTenant(): string
    {
        $m = DB::connection('master');
        $code = 'runacct' . strtolower(\Illuminate\Support\Str::random(5));
        $id = $m->table('tenants')->insertGetId(['tenant_code' => $code, 'business_name' => 'Running Account', 'owner_name' => 'O',
            'owner_email' => $code . '@test.local', 'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('tenant_databases')->insert(['tenant_id' => $id, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'), 'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb, 'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        $this->beforeApplicationDestroyed(function () use ($m, $id) {
            $m->table('tenant_databases')->where('tenant_id', $id)->delete();
            $m->table('tenants')->where('id', $id)->delete();
        });

        return $code;
    }
}
