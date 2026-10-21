<?php

namespace Tests\MySql;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\PurchasingSetting;
use App\Models\Tenant\Supplier;
use App\Services\Purchasing\PurchaseBillService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchasingService;
use App\Services\Purchasing\SupplierRunningAccountService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\RacesWorkers;

/**
 * SUPPLIER-NET-BILL-1 (owner, 21 Oct): record the ACTUAL supplier invoice. A return made before the bill must be
 * credited exactly once, whether the supplier bills everything received (1,000, the return stays a credit) or bills
 * already less the return (800). Both end at payable 800 — never 600. The old return is never edited or deleted.
 *
 * Through the real services: postGrn → createDraft + post (return) → PurchaseBillService → SupplierPayableService.
 */
class SupplierNetBillMySqlTest extends MySqlTenantTestCase
{
    use RacesWorkers;

    private int $branchId;

    private int $supplierId;

    private int $productId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'journal_lines', 'journal_entries', 'supplier_ledgers', 'supplier_credit_allocations', 'supplier_payments',
            'purchase_bill_return_nettings', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'purchase_return_lines', 'purchase_returns', 'purchase_bill_lines', 'purchase_bills', 'purchasing_settings',
            'goods_receipt_lines', 'goods_receipts', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'products', 'categories', 'units', 'suppliers', 'accounts', 'branches', 'users',
        ]);
        (new DefaultChartOfAccountsSeeder)->run();
        PurchasingSetting::tenantDefault()->update(['supplier_running_account' => true]);   // as on every live tenant
        $c = DB::connection('tenant');
        $this->branchId = $c->table('branches')->insertGetId(['name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $unit = $c->table('units')->insertGetId(['code' => 'PCS', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $cat = $c->table('categories')->insertGetId(['name' => 'Goods', 'slug' => 'goods-' . Str::random(5), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->productId = Product::create(['category_id' => $cat, 'unit_id' => $unit, 'sku' => 'NB-' . Str::upper(Str::random(5)), 'name' => 'Net Bill Item',
            'slug' => 'net-bill-' . Str::random(5), 'item_kind' => 'finished_good', 'inventory_consumption_method' => 'stock_item',
            'is_stock_tracked' => true, 'is_purchasable' => true, 'status' => 'active'])->id;
        $this->supplierId = Supplier::create(['code' => 'NB-' . Str::upper(Str::random(4)), 'name' => 'Net Bill Supplier', 'status' => 'active',
            'opening_balance' => 0, 'current_balance' => 0])->id;
        $this->userId = $c->table('users')->insertGetId(['name' => 'Accounts', 'email' => 'nb' . uniqid() . '@test.local', 'password' => bcrypt('x'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_an_invoice_for_everything_received_keeps_the_return_as_a_credit_payable_800(): void
    {
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);

        $bill = $this->bill($grn, 'separate');

        $this->assertEqualsWithDelta(1000, (float) $bill->grand_total, 0.001, 'the invoice as the supplier wrote it');
        $this->assertEqualsWithDelta(800, $this->supplierOwes(), 0.001);
        $this->assertEqualsWithDelta(800, (float) $bill->fresh()->balance_due, 0.001, 'the return credit settles it once');
        $this->assertBooksAgree(800);
    }

    public function test_an_invoice_already_less_the_return_ends_at_800_and_the_credit_is_used_once(): void
    {
        $grn = $this->receive([[10, 100]]);
        $return = $this->returnOf($grn, 2);

        $bill = $this->bill($grn, 'netted');

        $this->assertEqualsWithDelta(800, (float) $bill->grand_total, 0.001, 'the invoice as the supplier wrote it: 1,000 less the 200 returned');
        $this->assertEqualsWithDelta(800, $this->supplierOwes(), 0.001, 'not 600');
        $this->assertEqualsWithDelta(800, (float) $bill->fresh()->balance_due, 0.001);
        $this->assertBooksAgree(800);
        $this->assertSame(1, DB::table('purchase_bill_return_nettings')->where('purchase_return_id', $return->id)->count(), 'linked once');
        $this->assertEmpty(collect(app(SupplierRunningAccountService::class)->unallocatedCredits($this->supplierId))
            ->where('type', 'return')->where('id', $return->id)->all(), 'the 200 is never offered again');
        $this->assertSame('posted', $return->fresh()->status, 'the return itself is untouched');
        $this->assertEqualsWithDelta(200, (float) $return->fresh()->grand_total, 0.001);
    }

    public function test_a_net_invoice_paid_in_full_settles_everything(): void
    {
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);
        $bill = $this->bill($grn, 'netted');

        $this->pay($bill, 800);

        $this->assertEqualsWithDelta(0, $this->supplierOwes(), 0.001);
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertEqualsWithDelta(0, $this->gl('2100'), 0.001, 'payable cleared');
        $this->assertEqualsWithDelta(800, $this->gl('1400'), 0.001, 'inventory = 8 × 100');
    }

    public function test_a_return_that_had_settled_an_older_bill_moves_to_the_net_invoice(): void
    {
        // An older open bill of 500 for the same supplier: the return made before the new bill settled it (oldest first).
        $older = $this->receive([[5, 100]]);
        $olderBill = $this->bill($older, null);
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);
        $this->assertEqualsWithDelta(300, (float) $olderBill->fresh()->balance_due, 0.001, 'today: the 200 went to the older bill');

        $bill = $this->bill($grn, 'netted');

        $this->assertEqualsWithDelta(500, (float) $olderBill->fresh()->balance_due, 0.001, 'the supplier never credited the older bill — it owes 500 again');
        $this->assertEqualsWithDelta(800, (float) $bill->fresh()->balance_due, 0.001);
        $this->assertEqualsWithDelta(1300, $this->supplierOwes(), 0.001, '500 + 800');
        $this->assertEqualsWithDelta(1300, (float) PurchaseBill::where('supplier_id', $this->supplierId)->sum('balance_due'), 0.001, 'bills agree with the ledger');
        $this->assertBooksAgree(1300, 1300);
    }

    public function test_free_goods_returned_leave_nothing_to_net(): void
    {
        $grn = $this->receive([[10, 100], [2, 0]]);
        $this->returnOf($grn, 2, 1);   // the free line

        $this->assertSame([], app(PurchaseBillService::class)->returnsToAsk($grn), 'no money was credited — nothing to ask');
        $bill = $this->bill($grn, null);
        $this->assertEqualsWithDelta(1000, (float) $bill->grand_total, 0.001);
        $this->assertEqualsWithDelta(1000, $this->supplierOwes(), 0.001);
    }

    public function test_the_question_is_asked_only_when_the_receipt_has_returns_and_never_answered_for_the_user(): void
    {
        $grn = $this->receive([[10, 100]]);
        try {
            $this->bill($grn, 'netted');
            $this->fail('no return before this bill — nothing can be "already less"');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('returns_on_invoice', $e->errors());
        }

        $this->returnOf($grn, 2);
        $this->assertCount(1, app(PurchaseBillService::class)->returnsToAsk($grn));
        try {
            $this->bill($grn, null);
            $this->fail('a receipt with a return before its bill must say how the invoice treats it');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('returns_on_invoice', $e->errors());
        }
        $this->assertSame(0, PurchaseBill::count(), 'nothing posted');

        // Without returns and without an answer: exactly as before.
        $plain = $this->receive([[3, 100]]);
        $this->assertEqualsWithDelta(300, (float) $this->bill($plain, null)->grand_total, 0.001);
    }

    public function test_two_bills_for_one_receipt_at_the_same_moment_make_one(): void
    {
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);
        $job = ['mode' => 'bill_post', 'grn_id' => $grn->id, 'user_id' => $this->userId, 'choice' => 'netted'];

        [$out, $queued] = $this->race('SELECT id FROM goods_receipts WHERE id = ? FOR UPDATE', [$grn->id], [$job, $job]);

        $this->assertSame(2, $queued, 'both were in flight together, queued behind the receipt: ' . implode(' | ', $out));
        $this->assertSame(1, $this->countOut($out, 'POSTED:'), 'one bill: ' . implode(' | ', $out));
        $this->assertSame(1, $this->countOut($out, 'REFUSED:'), 'the other is told a bill exists: ' . implode(' | ', $out));
        $this->assertSame(1, PurchaseBill::where('goods_receipt_id', $grn->id)->count());
        $this->assertSame(1, DB::table('purchase_bill_return_nettings')->count(), 'the return netted once');
        $this->assertEqualsWithDelta(800, $this->supplierOwes(), 0.001);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param list<array{0:float,1:float}> $lines qty, unit cost */
    private function receive(array $lines): GoodsReceipt
    {
        $grn = GoodsReceipt::create(['grn_no' => 'GRN-' . Str::upper(Str::random(8)), 'branch_id' => $this->branchId, 'supplier_id' => $this->supplierId,
            'receipt_date' => now()->toDateString(), 'status' => 'posted']);
        foreach ($lines as [$qty, $cost]) {
            $grn->lines()->create(['product_id' => $this->productId, 'quantity_received' => $qty, 'unit_cost' => $cost, 'affects_stock' => true]);
        }
        app(PurchasingService::class)->postGrn($grn->fresh(['lines.product', 'lines.variant', 'branch']));

        return $grn->fresh();
    }

    private function bill(GoodsReceipt $grn, ?string $returnsOnInvoice): PurchaseBill
    {
        return app(PurchaseBillService::class)->createFromReceipt($grn, array_filter([
            'bill_date' => now()->toDateString(), 'returns_on_invoice' => $returnsOnInvoice,
        ], fn ($v) => $v !== null), $this->userId);
    }

    private function returnOf(GoodsReceipt $grn, float $qty, int $lineIndex = 0): PurchaseReturn
    {
        $lineId = (int) $grn->lines()->orderBy('id')->skip($lineIndex)->value('id');
        $svc = app(PurchaseReturnService::class);
        $draft = $svc->createDraft(
            ['branch_id' => $this->branchId, 'supplier_id' => $this->supplierId, 'goods_receipt_id' => $grn->id, 'return_date' => now()->toDateString(), 'reason_code' => 'damaged'],
            [['product_id' => $this->productId, 'source_line_id' => $lineId, 'quantity' => $qty]],
        );

        return $svc->post($draft);
    }

    private function pay(PurchaseBill $bill, float $amount): void
    {
        $bank = \App\Models\Tenant\CashBankAccount::create(['code' => 'BANK-' . Str::random(4), 'name' => 'Bank', 'account_type' => 'bank',
            'account_id' => (int) DB::table('accounts')->where('code', '1200')->value('id'), 'branch_id' => $this->branchId,
            'opening_balance' => 100000, 'current_balance' => 100000, 'is_active' => true]);
        app(\App\Services\Finance\SupplierPayableService::class)->recordPayment([
            'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId, 'cash_bank_account_id' => $bank->id,
            'purchase_bill_id' => $bill->id, 'payment_date' => now()->toDateString(), 'amount' => $amount, 'payment_method' => 'bank_transfer',
        ]);
    }

    /** Stock value, 1400 and 2100 all say the same thing as the supplier ledger. */
    private function assertBooksAgree(float $payable, ?float $inventory = null): void
    {
        $stockValue = (float) DB::table('stock_balances')->where('product_id', $this->productId)->selectRaw('SUM(quantity_on_hand * average_cost) v')->value('v');
        $this->assertEqualsWithDelta($inventory ?? $stockValue, $this->gl('1400'), 0.001, 'inventory GL = stock value');
        $this->assertEqualsWithDelta(-$payable, $this->gl('2100'), 0.001, 'payable GL = what is owed');
        $this->assertEqualsWithDelta($payable, $this->supplierOwes(), 0.001, 'supplier ledger = what is owed');
    }

    private function supplierOwes(): float
    {
        return round((float) Supplier::findOrFail($this->supplierId)->current_balance, 4);
    }

    private function gl(string $code): float
    {
        return round((float) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', $code)->sum(DB::raw('journal_lines.debit - journal_lines.credit')), 4);
    }
}
