<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\GoodsReceiptController;
use App\Http\Controllers\Tenant\PurchaseBillController;
use App\Models\Tenant\GoodsReceipt;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * GRN-NON-STOCK-1 — a purchase without stock: the GRN posts, inventory is not touched.
 *
 * Khatri buys cold drinks it sells but has not started counting (owner, 6 Oct). The GRN refused them,
 * and a bill can only be built from a GRN, so the purchase had nowhere to go. Now a purchase-only line
 * posts with no stock movement; its bill books it to 5100 cost (1400 would never be relieved — a sale
 * only takes COGS out of stock it holds), and a return takes it back out of cost.
 *
 * Mixed receipt: 1,000 containers @ 18.50 (stocked) + 100 Cola Next @ 50 (purchase only) + 235
 * cartage. Value 18,500 / 5,000 of 23,500, so the cartage splits 185 / 50.
 */
class GrnNonStockMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $supplierId;
    private int $container;
    private int $cola;
    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'journal_lines', 'journal_entries', 'purchase_return_lines', 'purchase_returns', 'purchase_bill_lines',
            'purchase_bills', 'supplier_ledgers', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'goods_receipt_lines', 'goods_receipts', 'product_variants', 'products', 'categories', 'suppliers', 'branches', 'accounts',
        ]);
        foreach ([['1400', 'Inventory Asset', 'asset', 'debit'], ['2100', 'Accounts Payable', 'liability', 'credit'], ['5100', 'Product COGS', 'expense', 'debit']] as [$c, $n, $t, $nb]) {
            $this->acc[$c] = DB::table('accounts')->insertGetId(['code' => $c, 'name' => $n, 'type' => $t, 'normal_balance' => $nb, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->branchId = $this->makeBranch(['status' => 'active']);
        $cat = $this->makeCategory();
        $this->container = $this->makeProduct($cat, ['name' => 'Container 750 ML', 'is_sellable' => 0, 'is_pos_visible' => 0, 'is_purchasable' => 1, 'is_stock_tracked' => 1]);
        $this->cola = $this->makeProduct($cat, ['name' => 'Cola Next 300 ml', 'is_sellable' => 1, 'is_pos_visible' => 1, 'is_purchasable' => 1, 'is_stock_tracked' => 0]);
        $this->supplierId = DB::table('suppliers')->insertGetId(['code' => 'SUP-CD', 'name' => 'Shahbaz cold drink', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        view()->share('errors', new ViewErrorBag);
    }

    /** @param list<array{0:int,1:float,2:float}> $lines product, qty, cost */
    private function receive(array $lines, float $charges = 0): GoodsReceipt
    {
        app(GoodsReceiptController::class)->store(Request::create('/goods-receipts', 'POST', [
            'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId, 'receipt_date' => now()->toDateString(),
            'extra_charges' => $charges, 'extra_charges_note' => $charges > 0 ? 'Cartage' : null,
            'lines' => array_map(fn ($l) => ['product_id' => $l[0], 'quantity_received' => $l[1], 'unit_cost' => $l[2]], $lines),
        ]));

        return GoodsReceipt::on('tenant')->with('lines')->latest('id')->firstOrFail();
    }

    private function mixed(): GoodsReceipt
    {
        return $this->receive([[$this->container, 1000, 18.5], [$this->cola, 100, 50]], 235);
    }

    private function bill(GoodsReceipt $grn): object
    {
        app(PurchaseBillController::class)->store(Request::create('/purchase-bills', 'POST', ['goods_receipt_id' => $grn->id, 'bill_date' => now()->toDateString()]));

        return DB::table('purchase_bills')->latest('id')->first();
    }

    /** account code => [debit, credit] of the journal for a source. */
    private function journal(string $type, int $id): array
    {
        $out = [];
        foreach (DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.source_type', $type)->where('e.source_id', $id)->get(['a.code', 'l.debit', 'l.credit']) as $l) {
            $out[$l->code] = [round((float) $l->debit, 2), round((float) $l->credit, 2)];
        }
        ksort($out);

        return $out;
    }

    private function onHand(int $product): float
    {
        return (float) DB::table('stock_balances')->where('product_id', $product)->sum('quantity_on_hand');
    }

    public function test_a_mixed_receipt_stocks_only_the_tracked_line(): void
    {
        $grn = $this->mixed();

        $this->assertSame('posted', $grn->status, 'the receipt posts — no refusal, no 500');
        $this->assertSame(1000.0, $this->onHand($this->container));
        $this->assertSame(0.0, $this->onHand($this->cola), 'the drink enters no stock');
        $this->assertSame(0, DB::table('stock_ledgers')->where('product_id', $this->cola)->count());
        $this->assertFalse($grn->lines->firstWhere('product_id', $this->cola)->affects_stock);
        $this->assertTrue($grn->lines->firstWhere('product_id', $this->container)->affects_stock);
        // The stocked line carries only ITS share of the cartage (185), not the drink's.
        $this->assertEqualsWithDelta(18685.0, (float) DB::table('stock_ledgers')->where('product_id', $this->container)->sum('total_cost'), 0.06);
    }

    public function test_the_bill_books_the_purchase_only_part_to_cost(): void
    {
        $bill = $this->bill($this->mixed());

        $this->assertSame(23735.0, round((float) $bill->grand_total, 2), 'the supplier is owed for everything');
        $this->assertSame([
            '1400' => [18685.0, 0.0],   // = the stock ledger's value
            '2100' => [0.0, 23735.0],
            '5100' => [5050.0, 0.0],    // drinks 5,000 + their 50 of cartage
        ], $this->journal('purchase_bill', $bill->id));
        $this->assertSame(1, DB::table('supplier_ledgers')->where('reference_id', $bill->id)->where('entry_type', 'purchase_bill')->count());
    }

    public function test_a_purchase_only_receipt_bills_entirely_to_cost(): void
    {
        $bill = $this->bill($this->receive([[$this->cola, 240, 50]]));

        $this->assertSame(['2100' => [0.0, 12000.0], '5100' => [12000.0, 0.0]], $this->journal('purchase_bill', $bill->id),
            'no inventory line at all');
    }

    public function test_switching_track_stock_on_later_does_not_rebook_an_old_receipt(): void
    {
        $grn = $this->receive([[$this->cola, 240, 50]]);
        DB::table('products')->where('id', $this->cola)->update(['is_stock_tracked' => 1]);   // inventory started later

        $bill = $this->bill($grn);

        $this->assertSame(['2100' => [0.0, 12000.0], '5100' => [12000.0, 0.0]], $this->journal('purchase_bill', $bill->id),
            'the receipt moved no stock, so its bill still books cost — the flag was frozen at receipt');
    }

    public function test_a_stocked_receipt_bills_exactly_as_before(): void
    {
        $bill = $this->bill($this->receive([[$this->container, 1000, 18.5]], 400));

        $this->assertSame(['1400' => [18900.0, 0.0], '2100' => [0.0, 18900.0]], $this->journal('purchase_bill', $bill->id));
    }

    public function test_voiding_a_mixed_receipt_reverses_only_the_stock_it_moved(): void
    {
        $grn = $this->mixed();

        app(PurchasingService::class)->voidGrn($grn->fresh('lines'));

        $this->assertSame('voided', $grn->fresh()->status);
        $this->assertSame(0.0, $this->onHand($this->container));
        $this->assertSame(0, DB::table('stock_ledgers')->where('product_id', $this->cola)->count(), 'nothing to reverse for the drink');
    }

    public function test_returning_purchase_only_goods_takes_them_out_of_cost_not_stock(): void
    {
        $grn = $this->mixed();
        $colaLine = $grn->lines->firstWhere('product_id', $this->cola);
        $containerLine = $grn->lines->firstWhere('product_id', $this->container);

        $service = app(PurchaseReturnService::class);
        $return = $service->createDraft(
            ['branch_id' => $this->branchId, 'supplier_id' => $this->supplierId, 'goods_receipt_id' => $grn->id, 'return_date' => now()->toDateString()],
            [
                ['product_id' => $this->cola, 'source_line_id' => $colaLine->id, 'quantity' => 20, 'unit_cost' => 50],
                ['product_id' => $this->container, 'source_line_id' => $containerLine->id, 'quantity' => 100, 'unit_cost' => 18.5],
            ]
        );
        $service->post($return);

        $this->assertSame(900.0, $this->onHand($this->container), 'the stocked goods go back out');
        $this->assertSame(0, DB::table('stock_ledgers')->where('product_id', $this->cola)->count(), 'the drink was never in stock — nothing leaves');
        // 1,000 drinks + 1,850 containers = 2,850; cost takes back 1,000 of it.
        $this->assertSame(['1400' => [0.0, 1850.0], '2100' => [2850.0, 0.0], '5100' => [0.0, 1000.0]], $this->journal('purchase_return', $return->id));
    }

    public function test_the_grn_page_marks_the_purchase_only_line(): void
    {
        $html = app(GoodsReceiptController::class)->show($this->mixed())->render();

        $this->assertSame(1, substr_count($html, 'Purchase only — no stock'), 'on the drink, not the containers');
    }
}
