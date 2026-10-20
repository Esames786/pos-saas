<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesReturnLine;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SalesReturnService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * RETURN-RESTOCK-1 — each returned line: "Back on the shelf?"
 *
 * Yes (the default, and every caller before this): the stock comes back and the GL moves the cost back from
 * COGS to Inventory, exactly as before. No (opened, spoilt, a dish): nothing comes back to stock, and the cost
 * STAYS in COGS — the goods are a loss; reversing it would leave Inventory valued for goods in the bin.
 * A line sold in a pack is returned in that pack: the return line keeps the pack and its tablets.
 */
class ReturnRestockMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'sales_ledgers',
            'sales_return_lines', 'sales_returns', 'sale_payments', 'sales_order_lines',
            'sales_orders', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'products', 'categories', 'accounts', 'branches', 'users',
        ]);
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        $this->branchId = $this->makeBranch();
        $this->userId = $this->makeUser();
    }

    public function test_back_on_the_shelf_or_thrown_away_moves_stock_and_cost_accordingly(): void
    {
        $cat = $this->makeCategory();
        $sealed = $this->stocked($cat, 'Sealed Syrup');
        $spoilt = $this->stocked($cat, 'Fresh Bread');
        $sale = $this->makeSale($this->branchId, ['created_by_user_id' => $this->userId, 'subtotal' => 500, 'grand_total' => 500, 'paid_amount' => 500]);
        $l1 = $this->makeSaleLine($sale, $sealed, ['quantity' => 2, 'unit_price' => 150, 'line_total' => 300, 'unit_cost' => 90, 'cost_total' => 180]);
        $l2 = $this->makeSaleLine($sale, $spoilt, ['quantity' => 2, 'unit_price' => 100, 'line_total' => 200, 'unit_cost' => 60, 'cost_total' => 120]);
        [$before1, $before2] = [$this->stock($sealed), $this->stock($spoilt)];

        $return = app(SalesReturnService::class)->processReturn(
            SalesOrder::findOrFail($sale),
            [['sales_order_line_id' => $l1, 'quantity' => 1, 'restock' => '1'], ['sales_order_line_id' => $l2, 'quantity' => 1, 'restock' => '0']],
            'test', 'cash', 250, $this->userId,
        );

        $this->assertEqualsWithDelta($before1 + 1, $this->stock($sealed), 0.001, 'Yes: back in stock');
        $this->assertEqualsWithDelta($before2, $this->stock($spoilt), 0.001, 'No: nothing comes back');
        $lines = SalesReturnLine::where('sales_return_id', $return->id)->get()->keyBy('product_id');
        $this->assertTrue($lines[$sealed]->restocked);
        $this->assertFalse($lines[$spoilt]->restocked);

        $gl = $this->journal($return->id);
        $this->assertEquals(90, $gl['1400']['debit'], 'only the restocked line moves its cost back into Inventory');
        $this->assertEquals(90, $gl['5100']['credit'], 'the thrown-away bread stays a cost (60 not reversed)');
        $this->assertEquals(250, $gl['4110']['debit'] ?? $gl['4120']['debit'], 'the whole refund still reverses the sale');
        $this->assertEqualsWithDelta(0, array_sum(array_column($gl, 'debit')) - array_sum(array_column($gl, 'credit')), 0.001, 'balanced');
    }

    public function test_without_the_question_a_return_restocks_exactly_as_before(): void
    {
        $p = $this->stocked($this->makeCategory(), 'Plain Item');
        $sale = $this->makeSale($this->branchId, ['created_by_user_id' => $this->userId, 'subtotal' => 100, 'grand_total' => 100, 'paid_amount' => 100]);
        $line = $this->makeSaleLine($sale, $p, ['quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'unit_cost' => 50, 'cost_total' => 50]);
        $before = $this->stock($p);

        $return = app(SalesReturnService::class)->processReturn(SalesOrder::findOrFail($sale), [['sales_order_line_id' => $line, 'quantity' => 1]], 'test', 'cash', null, $this->userId);

        $this->assertEqualsWithDelta($before + 1, $this->stock($p), 0.001);
        $this->assertTrue(SalesReturnLine::where('sales_return_id', $return->id)->first()->restocked);
        $this->assertEquals(50, $this->journal($return->id)['1400']['debit']);
    }

    public function test_a_pack_line_is_returned_in_its_pack(): void
    {
        $p = $this->stocked($this->makeCategory(), 'Strip Medicine');
        $sale = $this->makeSale($this->branchId, ['created_by_user_id' => $this->userId, 'subtotal' => 105, 'grand_total' => 105, 'paid_amount' => 105]);
        $line = $this->makeSaleLine($sale, $p, ['quantity' => 2, 'unit_price' => 52.5, 'line_total' => 105, 'unit_cost' => 40, 'cost_total' => 80,
            'pack_unit_id' => 999, 'pack_factor' => 10, 'base_quantity' => 20, 'unit_code' => 'Strip']);
        $before = $this->stock($p);

        $return = app(SalesReturnService::class)->processReturn(SalesOrder::findOrFail($sale), [['sales_order_line_id' => $line, 'quantity' => 1]], 'test', 'cash', null, $this->userId);

        $rl = SalesReturnLine::where('sales_return_id', $return->id)->first();
        $this->assertSame(10, (int) $rl->pack_factor);
        $this->assertEquals(10, (float) $rl->base_quantity, '1 Strip = 10 tablets');
        $this->assertEqualsWithDelta($before + 10, $this->stock($p), 0.001, 'its tablets go back');
    }

    public function test_the_return_page_asks_only_for_shelf_goods_and_defaults_a_perishable_to_no(): void
    {
        $src = file_get_contents(resource_path('views/tenant/sales-returns/create.blade.php'));
        $this->assertStringContainsString('name="lines[{{ $i }}][restock]"', $src);
        $this->assertStringContainsString('$askShelf = (bool) ($line->product?->is_stock_tracked);', $src, 'a dish (no stock) is not asked — refund only, as before');
        $this->assertStringContainsString('$shelfDefault = ! ($line->product?->is_perishable);', $src);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function stocked(int $cat, string $name): int
    {
        $p = $this->makeProduct($cat, ['name' => $name, 'is_stock_tracked' => 1, 'inventory_consumption_method' => 'stock_item']);
        app(InventoryService::class)->postIn(
            branch: \App\Models\Tenant\Branch::findOrFail($this->branchId), product: \App\Models\Tenant\Product::findOrFail($p), variant: null,
            quantity: 50, unitCost: 40, movementType: 'opening_stock', referenceType: 'test',
        );

        return $p;
    }

    private function stock(int $productId): float
    {
        return (float) DB::connection('tenant')->table('stock_balances')->where('product_id', $productId)->sum('quantity_on_hand');
    }

    /** @return array<string, array{debit: float, credit: float}> by account code */
    private function journal(int $returnId): array
    {
        $out = [];
        foreach (DB::connection('tenant')->table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.source_type', 'sales_return')->where('journal_entries.source_id', $returnId)
            ->get(['accounts.code', 'journal_lines.debit', 'journal_lines.credit']) as $l) {
            $out[$l->code]['debit'] = ($out[$l->code]['debit'] ?? 0) + (float) $l->debit;
            $out[$l->code]['credit'] = ($out[$l->code]['credit'] ?? 0) + (float) $l->credit;
        }
        $this->assertNotEmpty($out, 'the return was journaled');

        return $out;
    }
}
