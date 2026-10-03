<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\GoodsReceiptController;
use App\Http\Controllers\Tenant\PurchaseBillController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * GRN-BILL-CHARGES-1 — the receipt's extra charges have to reach the bill.
 *
 * GRN-EXTRA-CHARGES-1 gave cartage a home on the receipt and put it into what the goods cost. The
 * bill, built from that same receipt, ignored it: 28,700 of goods plus 400 of cartage produced a
 * 28,700 payable, so the supplier was owed 400 less than they had charged. The plan for that work
 * said this linkage was required and the code did not carry it — these guards are why it cannot
 * be forgotten twice.
 *
 * The GL stays balanced by itself: postPurchaseBill() debits 1400 Inventory and credits 2100
 * Payable with the same grand_total. Debiting inventory is also right — the GRN already put this
 * charge into stock cost, so the two agree.
 */
class GrnBillChargesMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $supplierId;
    private int $categoryId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'journal_lines', 'journal_entries', 'purchase_bill_lines', 'purchase_bills',
            'stock_ledgers', 'stock_balances', 'goods_receipt_lines', 'goods_receipts',
            'products', 'categories', 'suppliers', 'branches', 'accounts',
        ]);

        // postPurchaseBill() posts Dr 1400 / Cr 2100 and is deliberately forgiving: if those
        // accounts are missing it reports and moves on. Without them seeded, the GL guard below
        // would pass on an empty journal — asserting nothing at all.
        foreach ([
            ['1400', 'Inventory Asset', 'asset', 'debit'],
            ['2100', 'Accounts Payable', 'liability', 'credit'],
        ] as [$code, $name, $type, $normal]) {
            DB::connection('tenant')->table('accounts')->insert([
                'code' => $code, 'name' => $name, 'type' => $type,
                'normal_balance' => $normal, 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->branchId   = $this->makeBranch(['status' => 'active']);
        $this->categoryId = $this->makeCategory();
        $this->productId  = $this->makeProduct($this->categoryId, [
            'name' => 'Container 750 ML', 'is_sellable' => 0, 'is_pos_visible' => 0,
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
        ]);
        $this->supplierId = DB::connection('tenant')->table('suppliers')->insertGetId([
            'code' => 'SUP-BC-1', 'name' => 'Packing Supplier', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        view()->share('errors', new ViewErrorBag);
    }

    /** Receive 1,000 @ 18.50 = 18,500 of goods, with whatever charge is passed. */
    private function receive(float $charges = 0): int
    {
        app(GoodsReceiptController::class)->store(Request::create('/goods-receipts', 'POST', [
            'supplier_id'        => $this->supplierId,
            'branch_id'          => $this->branchId,
            'receipt_date'       => now()->toDateString(),
            'extra_charges'      => $charges,
            'extra_charges_note' => $charges > 0 ? 'Cartage' : null,
            'lines'              => [[
                'product_id' => $this->productId, 'quantity_received' => 1000, 'unit_cost' => 18.5,
            ]],
        ]));

        return (int) DB::connection('tenant')->table('goods_receipts')->latest('id')->value('id');
    }

    private function bill(int $grnId): object
    {
        app(PurchaseBillController::class)->store(Request::create('/purchase-bills', 'POST', [
            'goods_receipt_id' => $grnId,
            'bill_date'        => now()->toDateString(),
        ]));

        return DB::connection('tenant')->table('purchase_bills')->latest('id')->first();
    }

    public function test_the_payable_includes_the_receipts_charges(): void
    {
        $bill = $this->bill($this->receive(400));

        $this->assertSame(18500.0, round((float) $bill->subtotal, 2), 'goods alone');
        $this->assertSame(400.0, round((float) $bill->extra_charges, 2), 'carried from the receipt');
        $this->assertSame(18900.0, round((float) $bill->grand_total, 2),
            'the supplier is owed the goods AND the cartage');
        $this->assertSame(18900.0, round((float) $bill->balance_due, 2));
    }

    public function test_a_receipt_without_charges_bills_exactly_as_before(): void
    {
        // Every bill posted before this change is on this path.
        $bill = $this->bill($this->receive(0));

        $this->assertSame(0.0, round((float) $bill->extra_charges, 2));
        $this->assertSame(18500.0, round((float) $bill->grand_total, 2));
    }

    public function test_the_gl_entry_balances_and_carries_the_charge(): void
    {
        $bill = $this->bill($this->receive(400));

        $lines = DB::connection('tenant')->table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.source_type', 'purchase_bill')
            ->select('journal_lines.debit', 'journal_lines.credit')
            ->get();

        $this->assertNotEmpty($lines, 'posting a bill must write a journal');
        $debit  = round((float) $lines->sum('debit'), 2);
        $credit = round((float) $lines->sum('credit'), 2);

        // Dr 1400 Inventory / Cr 2100 Payable, both at grand_total — so raising the total by the
        // charge cannot unbalance it. This guard is here because that is easy to assume and
        // expensive to be wrong about.
        $this->assertSame($debit, $credit, 'the journal must balance');
        $this->assertSame(18900.0, $debit, 'and it carries the charge, not just the goods');
    }

    public function test_both_bill_screens_name_the_charge(): void
    {
        $grnId = $this->receive(400);

        // Before posting: nobody should be surprised by a payable larger than the lines above it.
        $preview = app(PurchaseBillController::class)
            ->create(Request::create('/purchase-bills/create', 'GET', ['goods_receipt_id' => $grnId]))
            ->render();
        $this->assertStringContainsString('Extra Charges', $preview);
        $this->assertStringContainsString('Cartage', $preview);

        // And after.
        $bill = $this->bill($grnId);
        $html = app(PurchaseBillController::class)
            ->show(\App\Models\Tenant\PurchaseBill::on('tenant')->findOrFail($bill->id))->render();
        $this->assertStringContainsString('Extra Charges', $html);
    }

    public function test_the_bill_and_the_stock_ledger_tell_the_same_story(): void
    {
        $bill = $this->bill($this->receive(400));

        $ledger = round((float) DB::connection('tenant')->table('stock_ledgers')->sum('total_cost'), 2);

        // The receipt put the charge into stock cost; the bill puts it into the payable and into
        // Inventory Asset. If these two ever disagree by more than the landed-cost rounding floor
        // (half a paisa per unit, so 0.05 on 1,000 units), one of them is lying.
        $this->assertEqualsWithDelta((float) $bill->grand_total, $ledger, 1000 * 0.00005,
            'what we owe and what the stock is carrying must be the same number');
    }
}
