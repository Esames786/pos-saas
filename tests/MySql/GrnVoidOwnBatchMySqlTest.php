<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\GoodsReceiptController;
use App\Models\Tenant\GoodsReceipt;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * GRN-VOID-OWN-BATCH-1 — voiding a receipt takes back exactly what it brought in, from the stock row it filled.
 *
 * Before: the void took the quantity off EVERY stock row of the item in the branch (every batch, every variant),
 * collected the receipt's entries per line by product (two lines of the same item reversed each entry twice), and
 * reversed an entry that had already been reversed. Live: khatribiryani's item #60 lost 2 units that way (5 Oct).
 */
class GrnVoidOwnBatchMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $supplierId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'purchase_bill_lines', 'purchase_bills', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'goods_receipt_lines', 'goods_receipts', 'product_variants', 'products', 'categories', 'suppliers', 'branches',
        ]);

        $this->branchId = $this->makeBranch(['status' => 'active']);
        $this->productId = $this->makeProduct($this->makeCategory(), [
            'name' => 'Panadol Test Tablet', 'is_purchasable' => 1, 'is_stock_tracked' => 1, 'requires_batch' => 1, 'has_expiry' => 1,
        ]);
        $this->supplierId = DB::connection('tenant')->table('suppliers')->insertGetId([
            'code' => 'SUP-OB-1', 'name' => 'Test Distributor', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        view()->share('errors', new ViewErrorBag);
    }

    /** @param list<array{0:float,1:string,2?:?int}> $lines [qty, batch, variant id] */
    private function receive(array $lines): GoodsReceipt
    {
        app(GoodsReceiptController::class)->store(Request::create('/goods-receipts', 'POST', [
            'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId, 'receipt_date' => now()->toDateString(),
            'lines' => array_map(fn ($l) => [
                'product_id' => $this->productId, 'product_variant_id' => $l[2] ?? null, 'quantity_received' => $l[0],
                'unit_cost' => 4.5, 'batch_no' => $l[1], 'expiry_date' => now()->addYear()->toDateString(),
            ], $lines),
        ]));

        return GoodsReceipt::on('tenant')->with('lines.product', 'bill')->latest('id')->firstOrFail();
    }

    private function batchQty(string $batchNo): float
    {
        return (float) DB::connection('tenant')->table('stock_balances as sb')
            ->join('inventory_batches as b', 'b.id', '=', 'sb.inventory_batch_id')
            ->where('sb.product_id', $this->productId)->where('b.batch_no', $batchNo)->sum('sb.quantity_on_hand');
    }

    private function total(): float
    {
        return (float) DB::connection('tenant')->table('stock_balances')->where('product_id', $this->productId)->sum('quantity_on_hand');
    }

    private function ledgerTotal(): float
    {
        return (float) DB::connection('tenant')->table('stock_ledgers')->where('product_id', $this->productId)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='in' THEN quantity ELSE -quantity END),0) q")->value('q');
    }

    public function test_voiding_takes_back_only_the_batch_the_receipt_brought_in(): void
    {
        $this->receive([[100, 'PNL-A']]);
        $this->receive([[50, 'PNL-B']]);
        $this->receive([[30, 'PNL-C']]);
        $second = GoodsReceipt::on('tenant')->where('id', '<', GoodsReceipt::on('tenant')->max('id'))->latest('id')->firstOrFail();

        app(PurchasingService::class)->voidGrn($second->load('lines.product', 'bill'), null, 'wrong batch');

        $this->assertEqualsWithDelta(100, $this->batchQty('PNL-A'), 0.001, 'the other batches keep every tablet');
        $this->assertEqualsWithDelta(0, $this->batchQty('PNL-B'), 0.001, 'only its own batch goes');
        $this->assertEqualsWithDelta(30, $this->batchQty('PNL-C'), 0.001);
        $this->assertEqualsWithDelta(130, $this->total(), 0.001, 'before the fix: 180 − 3×50 = 30');
        $this->assertEqualsWithDelta($this->total(), $this->ledgerTotal(), 0.001, 'the stock and its ledger agree');
        $out = DB::connection('tenant')->table('stock_ledgers')->where('direction', 'out')->first();
        $this->assertEqualsWithDelta(0, (float) $out->balance_after, 0.001, 'balance_after is that batch row, like every other movement');
    }

    public function test_two_lines_of_the_same_item_are_each_taken_back_once(): void
    {
        $this->receive([[200, 'PNL-OLD']]);
        // Two batches of one medicine on one receipt (and a free line is a line of its own on the new GRN screen).
        $grn = $this->receive([[40, 'PNL-X'], [10, 'PNL-Y'], [5, 'PNL-X']]);
        $this->assertEqualsWithDelta(255, $this->total(), 0.001);

        app(PurchasingService::class)->voidGrn($grn, null, null);

        $this->assertEqualsWithDelta(200, $this->total(), 0.001, 'exactly 55 back out — before the fix each entry went twice or more');
        $this->assertEqualsWithDelta(0, $this->batchQty('PNL-X'), 0.001);
        $this->assertEqualsWithDelta(0, $this->batchQty('PNL-Y'), 0.001);
        $this->assertSame(
            DB::connection('tenant')->table('stock_ledgers')->where('reference_id', $grn->id)->where('direction', 'in')->count(),
            DB::connection('tenant')->table('stock_ledgers')->where('reference_id', $grn->id)->where('direction', 'out')->count(),
            'one reversal per entry'
        );
        $this->assertEqualsWithDelta($this->total(), $this->ledgerTotal(), 0.001);
    }

    public function test_an_entry_already_reversed_is_not_taken_back_again(): void
    {
        $grn = $this->receive([[100, 'PNL-A'], [1, 'PNL-A']]);
        // The live case: a stray 1-unit entry had been reversed by hand before the receipt was voided.
        $stray = DB::connection('tenant')->table('stock_ledgers')->where('reference_id', $grn->id)->where('quantity', 1)->first();
        DB::connection('tenant')->table('stock_ledgers')->insert([
            'branch_id' => $stray->branch_id, 'product_id' => $stray->product_id, 'inventory_batch_id' => $stray->inventory_batch_id,
            'movement_type' => 'adjustment_out', 'direction' => 'out', 'quantity' => 1, 'unit_cost' => $stray->unit_cost,
            'total_cost' => $stray->total_cost, 'balance_after' => 100, 'reference_type' => $stray->reference_type,
            'reference_id' => $grn->id, 'reversal_of_id' => $stray->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('stock_balances')->where('product_id', $this->productId)->decrement('quantity_on_hand', 1);

        app(PurchasingService::class)->voidGrn($grn, null, null);

        $this->assertEqualsWithDelta(0, $this->total(), 0.001, 'before the fix: −1 (the stray unit taken back twice)');
        $this->assertSame(1, DB::connection('tenant')->table('stock_ledgers')->where('reversal_of_id', $stray->id)->count());
    }

    public function test_a_batch_partly_sold_cannot_be_voided_even_when_other_batches_have_stock(): void
    {
        $this->receive([[500, 'PNL-BIG']]);
        $grn = $this->receive([[50, 'PNL-SMALL']]);
        DB::connection('tenant')->table('stock_balances as sb')->join('inventory_batches as b', 'b.id', '=', 'sb.inventory_batch_id')
            ->where('b.batch_no', 'PNL-SMALL')->update(['sb.quantity_on_hand' => 20]);   // 30 of it sold

        try {
            app(PurchasingService::class)->voidGrn($grn, null, null);
            $this->fail('the receipt should not be voidable — its batch was partly sold');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no longer has the quantity', $e->getMessage());
            $this->assertStringContainsString('in batch PNL-SMALL', $e->getMessage(), 'it names the batch');
        }

        $this->assertEqualsWithDelta(500, $this->batchQty('PNL-BIG'), 0.001, 'before the fix the other batch paid for it');
        $this->assertEqualsWithDelta(20, $this->batchQty('PNL-SMALL'), 0.001, 'nothing moved');
        $this->assertSame('posted', GoodsReceipt::on('tenant')->find($grn->id)->status);
    }

    public function test_only_the_variant_received_goes_back(): void
    {
        $now = now();
        $red = DB::connection('tenant')->table('product_variants')->insertGetId(['product_id' => $this->productId, 'sku' => 'PNL-RED', 'name' => 'Red', 'created_at' => $now, 'updated_at' => $now]);
        $blue = DB::connection('tenant')->table('product_variants')->insertGetId(['product_id' => $this->productId, 'sku' => 'PNL-BLUE', 'name' => 'Blue', 'created_at' => $now, 'updated_at' => $now]);
        $this->receive([[60, 'V-1', $red]]);
        $grn = $this->receive([[25, 'V-1', $blue]]);

        app(PurchasingService::class)->voidGrn($grn, null, null);

        $byVariant = DB::connection('tenant')->table('stock_balances')->where('product_id', $this->productId)
            ->selectRaw('product_variant_id, SUM(quantity_on_hand) q')->groupBy('product_variant_id')->pluck('q', 'product_variant_id');
        $this->assertEqualsWithDelta(60, (float) $byVariant[$red], 0.001, 'the red ones stay');
        $this->assertEqualsWithDelta(0, (float) $byVariant[$blue], 0.001);
    }
}
