<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\GoodsReceiptController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * GRN-EXTRA-CHARGES-1 — cartage and the like are a CHARGE, not a product.
 *
 * With nowhere to put them a counter books them as a product line, which is what happened here:
 * four receipts carried "Spoon, qty 1, rate 400" to record cartage, inflating a real item's stock
 * by four and putting 1,600 of freight onto the wrong thing.
 *
 * They are paid to GET the goods here, so they belong in what those goods cost. The split is by
 * VALUE, not quantity, and the inventory cost is the only thing that moves — the line keeps the
 * price the supplier charged, so the receipt still reconciles against the supplier's document.
 */
class GrnExtraChargesMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $supplierId;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'stock_ledgers', 'stock_balances', 'goods_receipt_lines', 'goods_receipts',
            'products', 'categories', 'suppliers', 'branches',
        ]);

        $this->branchId   = $this->makeBranch(['status' => 'active']);
        $this->categoryId = $this->makeCategory();
        $this->supplierId = DB::connection('tenant')->table('suppliers')->insertGetId([
            'code' => 'SUP-EC-1', 'name' => 'Packing Supplier', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        view()->share('errors', new ViewErrorBag);
    }

    private function supply(string $name): int
    {
        return $this->makeProduct($this->categoryId, [
            'name' => $name, 'is_sellable' => 0, 'is_pos_visible' => 0,
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
        ]);
    }

    /** Post a GRN and return the stock value each product ended up carrying. */
    private function receive(array $lines, array $extra = []): void
    {
        app(GoodsReceiptController::class)->store(Request::create('/goods-receipts', 'POST', array_merge([
            'supplier_id'  => $this->supplierId,
            'branch_id'    => $this->branchId,
            'receipt_date' => now()->toDateString(),
            'lines'        => $lines,
        ], $extra)));
    }

    /** What the inventory ledger says this receipt actually cost — the number that matters. */
    private function ledgerValue(): float
    {
        return (float) DB::connection('tenant')->table('stock_ledgers')->sum('total_cost');
    }

    public function test_a_receipt_without_charges_behaves_exactly_as_before(): void
    {
        // Every receipt that already exists is on this path. If it moves by a paisa, the migration
        // has rewritten history.
        $a = $this->supply('Container 750 ML');
        $this->receive([
            ['product_id' => $a, 'quantity_received' => 1000, 'unit_cost' => 18.5],
        ]);

        $this->assertSame(18500.0, round($this->ledgerValue(), 2), 'goods cost only, untouched');
        $this->assertSame(1000.0, (float) DB::connection('tenant')->table('stock_balances')
            ->where('product_id', $a)->sum('quantity_on_hand'));
    }

    public function test_the_charge_lands_in_cost_and_the_parts_add_back_up(): void
    {
        $a = $this->supply('Container 750 ML');   // 1000 x 18.5 = 18,500
        $b = $this->supply('Spoon');              //  500 x 1.70 =    850
        $this->receive([
            ['product_id' => $a, 'quantity_received' => 1000, 'unit_cost' => 18.5],
            ['product_id' => $b, 'quantity_received' => 500,  'unit_cost' => 1.7],
        ], ['extra_charges' => 400, 'extra_charges_note' => 'Cartage']);

        // 19,350 of goods + 400 of cartage = 19,750.
        //
        // NOT asserted as exact, and the reason matters: unit_cost is decimal(14,4) and the ledger
        // stores total = qty x unit_cost, so spreading a charge through a UNIT price cannot land
        // on the paisa — the error is bounded at half a paisa per unit received. This asserts that
        // bound, derived from the quantities, rather than a round number that happens to pass.
        $bound = (1000 + 500) * 0.00005;
        $this->assertEqualsWithDelta(19750.0, $this->ledgerValue(), $bound,
            'goods + charge must reconcile to within half a paisa per unit');
        $this->assertGreaterThan(19749.0, $this->ledgerValue(), 'and the charge is really in there');
    }

    public function test_the_split_follows_value_not_quantity(): void
    {
        // 500 spoons outnumber 1000... but 1000 containers are 95.6% of what was paid for, so
        // they must carry 95.6% of the cartage. Splitting by quantity would be nonsense here.
        $a = $this->supply('Container 750 ML');
        $b = $this->supply('Spoon');
        $this->receive([
            ['product_id' => $a, 'quantity_received' => 1000, 'unit_cost' => 18.5],
            ['product_id' => $b, 'quantity_received' => 500,  'unit_cost' => 1.7],
        ], ['extra_charges' => 400, 'extra_charges_note' => 'Cartage']);

        $value = fn (int $id) => (float) DB::connection('tenant')->table('stock_ledgers')
            ->where('product_id', $id)->sum('total_cost');

        // 18,500 / 19,350 of 400 = 382.43 ; 850 / 19,350 of 400 = 17.57 (same delta as above).
        $this->assertEqualsWithDelta(18882.43, $value($a), 1000 * 0.00005);
        $this->assertEqualsWithDelta(867.57, $value($b), 500 * 0.00005);
        // The point of the test: the containers carry ~96% of the cartage, the spoons ~4%.
        $this->assertGreaterThan(20.0, ($value($a) - 18500) / ($value($b) - 850),
            'split by value, not by quantity — 500 spoons must not carry a third of it');
    }

    public function test_a_single_line_carries_the_whole_charge(): void
    {
        $a = $this->supply('Container 750 ML');
        $this->receive([
            ['product_id' => $a, 'quantity_received' => 100, 'unit_cost' => 10],
        ], ['extra_charges' => 250, 'extra_charges_note' => 'Unloading']);

        $this->assertSame(1250.0, round($this->ledgerValue(), 2));
    }

    public function test_the_charge_never_changes_how_many_things_arrived(): void
    {
        // The whole point. A fake product line moved the COUNT; a charge may only move the COST.
        $a = $this->supply('Spoon');
        $this->receive([
            ['product_id' => $a, 'quantity_received' => 500, 'unit_cost' => 1.7],
        ], ['extra_charges' => 400, 'extra_charges_note' => 'Cartage']);

        $this->assertSame(500.0, (float) DB::connection('tenant')->table('stock_balances')
            ->where('product_id', $a)->sum('quantity_on_hand'),
            '500 spoons arrived, not 501 — the cartage is not a spoon');
        $this->assertSame(1, (int) DB::connection('tenant')->table('goods_receipt_lines')->count(),
            'and it is not a line either');
    }

    public function test_the_description_is_kept_and_shown(): void
    {
        $a = $this->supply('Container 750 ML');
        $this->receive([
            ['product_id' => $a, 'quantity_received' => 10, 'unit_cost' => 18.5],
        ], ['extra_charges' => 400, 'extra_charges_note' => 'Cartage']);

        $grn = DB::connection('tenant')->table('goods_receipts')->latest('id')->first();
        $this->assertSame('Cartage', $grn->extra_charges_note);
        $this->assertSame(400.0, round((float) $grn->extra_charges, 2));

        $html = app(GoodsReceiptController::class)
            ->show(\App\Models\Tenant\GoodsReceipt::on('tenant')->findOrFail($grn->id))->render();
        $this->assertStringContainsString('Extra Charges', $html);
        $this->assertStringContainsString('Cartage', $html, 'a cost that moved invisibly cannot be checked');
    }

    public function test_a_negative_charge_is_refused(): void
    {
        $a = $this->supply('Container 750 ML');

        try {
            $this->receive([
                ['product_id' => $a, 'quantity_received' => 10, 'unit_cost' => 18.5],
            ], ['extra_charges' => -50]);
            $this->fail('a negative charge should have been refused');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('extra_charges', $e->errors());
        }

        $this->assertSame(0, (int) DB::connection('tenant')->table('goods_receipts')->count());
    }
}
