<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\PurchasingSetting;
use App\Models\Tenant\Supplier;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchasingService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * UB-05 (unified-business roadmap, W1) — what a supplier return does TODAY, case by case, through the real services
 * (postGrn → postBill → postPayment → createDraft + post). A characterization: it pins today's behaviour so any
 * change to it is a deliberate one. It changes nothing.
 *
 *   How the books work: a GRN puts goods into STOCK only; the BILL books Dr 1400 Inventory / Cr 2100 Payable; a return
 *   books Dr 2100 / Cr 1400, credits the supplier ledger and (running account ON, as on every live tenant) settles its
 *   own bill first.
 *
 *   after the bill, unpaid ........ the bill owes less; stock, 1400 and 2100 all agree
 *   after the bill, fully paid .... the supplier owes us (credit) — no cash moves until he actually pays it back
 *   BEFORE the bill ............... books ahead of the bill; a later bill for the FULL receipt makes everything agree
 *   free (bonus) goods ............ go back at no money — no credit for goods that were never paid for
 *   a bill already NET of the return (owner, 21 Oct: record the actual invoice) → 800 through the bill screen, which
 *   asks how the invoice treats the return (SUPPLIER-NET-BILL-1, SupplierNetBillMySqlTest has every case)
 */
class SupplierReturnMatrixMySqlTest extends MySqlTenantTestCase
{
    private int $branchId;

    private int $supplierId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'journal_lines', 'journal_entries', 'supplier_ledgers', 'supplier_credit_allocations', 'supplier_payments',
            'cash_bank_account_transactions', 'cash_bank_accounts',
            'purchase_return_lines', 'purchase_returns', 'purchase_bill_lines', 'purchase_bills', 'purchasing_settings',
            'goods_receipt_lines', 'goods_receipts', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'products', 'categories', 'units', 'suppliers', 'accounts', 'branches',
        ]);
        (new DefaultChartOfAccountsSeeder)->run();
        PurchasingSetting::tenantDefault()->update(['supplier_running_account' => true]);   // as on every live tenant
        $c = DB::connection('tenant');
        $this->branchId = $c->table('branches')->insertGetId(['name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $unit = $c->table('units')->insertGetId(['code' => 'PCS', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $cat = $c->table('categories')->insertGetId(['name' => 'Goods', 'slug' => 'goods-' . Str::random(5), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->productId = Product::create(['category_id' => $cat, 'unit_id' => $unit, 'sku' => 'MX-' . Str::upper(Str::random(5)), 'name' => 'Matrix Item',
            'slug' => 'matrix-' . Str::random(5), 'item_kind' => 'finished_good', 'inventory_consumption_method' => 'stock_item',
            'is_stock_tracked' => true, 'is_purchasable' => true, 'status' => 'active'])->id;
        $this->supplierId = Supplier::create(['code' => 'MX-' . Str::upper(Str::random(4)), 'name' => 'Matrix Supplier', 'status' => 'active',
            'opening_balance' => 0, 'current_balance' => 0])->id;
    }

    public function test_after_the_bill_unpaid_the_bill_owes_less_and_the_books_agree(): void
    {
        [$grn] = $this->receive([[10, 100]]);
        $bill = $this->bill($grn, 1000);

        $this->returnOf($grn, 2);

        $this->assertEqualsWithDelta(800, $this->supplierOwes(), 0.001, 'the supplier is owed 800');
        $this->assertEqualsWithDelta(800, (float) $bill->fresh()->balance_due, 0.001, 'the return settled its own bill first');
        $this->assertEqualsWithDelta(8, $this->stock(), 0.001);
        $this->assertEqualsWithDelta(800, $this->gl('1400'), 0.001, 'inventory = 8 × 100');
        $this->assertEqualsWithDelta(-800, $this->gl('2100'), 0.001, 'payable 800');
    }

    public function test_after_the_bill_fully_paid_the_supplier_owes_us_and_no_cash_moves(): void
    {
        [$grn] = $this->receive([[10, 100]]);
        $bill = $this->bill($grn, 1000);
        $this->pay($bill, 1000);
        $bankBefore = $this->gl('1200');
        $movesBefore = DB::table('cash_bank_account_transactions')->count();

        $this->returnOf($grn, 2);

        $this->assertEqualsWithDelta(-200, $this->supplierOwes(), 0.001, 'the supplier owes us 200 (credit)');
        $this->assertSame('paid', $bill->fresh()->status, 'the bill stays paid');
        $this->assertEqualsWithDelta($bankBefore, $this->gl('1200'), 0.001, 'no money until he pays it back');
        $this->assertSame($movesBefore, DB::table('cash_bank_account_transactions')->count(), 'no cash/bank movement');
        $this->assertEqualsWithDelta(200, $this->gl('2100'), 0.001, 'payable is a 200 debit — money owed to us');
    }

    public function test_before_the_bill_the_books_run_ahead_and_a_full_bill_makes_them_agree(): void
    {
        [$grn] = $this->receive([[10, 100]]);

        $this->returnOf($grn, 2);
        $this->assertEqualsWithDelta(-200, $this->supplierOwes(), 0.001, 'before any bill the supplier shows 200 in our favour');
        $this->assertEqualsWithDelta(-200, $this->gl('1400'), 0.001, 'inventory GL is 200 below zero until the bill books the goods');

        $this->bill($grn, 1000);   // the supplier bills the FULL receipt
        $this->assertEqualsWithDelta(800, $this->supplierOwes(), 0.001);
        $this->assertEqualsWithDelta(800, $this->gl('1400'), 0.001, 'inventory = 8 × 100');
        $this->assertEqualsWithDelta(-800, $this->gl('2100'), 0.001);
    }

    public function test_a_bill_already_net_of_the_return_is_800_not_600(): void
    {
        // Until SUPPLIER-NET-BILL-1 this ended at 600 — the return taken off twice. The bill screen now asks.
        [$grn] = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);

        $bill = app(\App\Services\Purchasing\PurchaseBillService::class)->createFromReceipt($grn, [
            'bill_date' => now()->toDateString(), 'returns_on_invoice' => 'netted',   // the supplier's bill already leaves out the 2 that went back
        ]);

        $this->assertEqualsWithDelta(800, (float) $bill->grand_total, 0.001, 'the invoice as written');
        $this->assertEqualsWithDelta(800, $this->supplierOwes(), 0.001, '800, not 600');
        $this->assertEqualsWithDelta(800, $this->gl('1400'), 0.001);
        $this->assertEqualsWithDelta(-800, $this->gl('2100'), 0.001);
        $this->assertEqualsWithDelta(8, $this->stock(), 0.001);
    }

    public function test_free_goods_go_back_at_no_money(): void
    {
        [$grn, $lines] = $this->receive([[10, 100], [2, 0]]);   // 10 paid + 2 free (bonus)
        $this->bill($grn, 1000);

        $return = $this->returnOf($grn, 2, $lines[1]);

        $this->assertEqualsWithDelta(0, (float) $return->grand_total, 0.001, 'no credit for goods never paid for');
        $this->assertEqualsWithDelta(1000, $this->supplierOwes(), 0.001);
        $this->assertEqualsWithDelta(10, $this->stock(), 0.001, 'the 2 free ones left stock');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param list<array{0:float,1:float}> $lines qty, unit cost @return array{0:GoodsReceipt, 1:list<int>} */
    private function receive(array $lines): array
    {
        $grn = GoodsReceipt::create(['grn_no' => 'GRN-' . Str::upper(Str::random(8)), 'branch_id' => $this->branchId, 'supplier_id' => $this->supplierId,
            'receipt_date' => now()->toDateString(), 'status' => 'posted']);
        $ids = [];
        foreach ($lines as [$qty, $cost]) {
            $ids[] = $grn->lines()->create(['product_id' => $this->productId, 'quantity_received' => $qty, 'unit_cost' => $cost, 'affects_stock' => true])->id;
        }
        app(PurchasingService::class)->postGrn($grn->fresh(['lines.product', 'lines.variant', 'branch']));

        return [$grn, $ids];
    }

    private function bill(GoodsReceipt $grn, float $amount): PurchaseBill
    {
        $bill = PurchaseBill::create(['bill_no' => 'BILL-' . Str::upper(Str::random(6)), 'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId,
            'goods_receipt_id' => $grn->id, 'bill_date' => now()->toDateString(), 'subtotal' => $amount, 'grand_total' => $amount,
            'amount_paid' => 0, 'balance_due' => $amount, 'status' => 'posted']);
        app(PurchasingService::class)->postBill($bill);

        return $bill->fresh();
    }

    /** The real payment path: supplier ledger + bill + cash/bank movement + GL (Dr 2100 / Cr bank 1200). */
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

    private function returnOf(GoodsReceipt $grn, float $qty, ?int $lineId = null): PurchaseReturn
    {
        $lineId ??= (int) $grn->lines()->orderBy('id')->value('id');
        $svc = app(PurchaseReturnService::class);
        $draft = $svc->createDraft(
            ['branch_id' => $this->branchId, 'supplier_id' => $this->supplierId, 'goods_receipt_id' => $grn->id, 'return_date' => now()->toDateString(), 'reason_code' => 'damaged'],
            [['product_id' => $this->productId, 'source_line_id' => $lineId, 'quantity' => $qty]],
        );

        return $svc->post($draft);
    }

    /** What we owe the supplier (negative = he owes us), by his ledger. */
    private function supplierOwes(): float
    {
        return round((float) Supplier::findOrFail($this->supplierId)->current_balance, 4);
    }

    /** Debit − credit on an account, every posted entry. */
    private function gl(string $code): float
    {
        return round((float) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', $code)->sum(DB::raw('journal_lines.debit - journal_lines.credit')), 4);
    }

    private function stock(): float
    {
        return (float) DB::table('stock_balances')->where('product_id', $this->productId)->sum('quantity_on_hand');
    }
}
