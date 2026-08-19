<?php

namespace Tests\MySql;

use App\Services\Reports\SalesReportEngine;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * SALES REPORT CENTER — reconciliation proof (spec AE) on a deterministic real-MySQL population:
 * parent/child categories, three items, two waiters + an UNASSIGNED sale, three order types (incl. a
 * delivery order carrying a delivery charge), cash + card, a posted partial return, an opening-cash
 * shift and cash-out movements (expense + department handover payout).
 *
 * Identities proven for ONE population:
 *   sale-dimension nets  : overview.net_sales = Σwaiter.net_sales = Σorder_type.net_sales
 *   line-dimension nets  : Σcategory.net = Σitem.net = Σdetailed.line_total  (delivery charge is a
 *                          HEADER charge — the explicit bridging term: grand = line net + delivery)
 *   returns              : separate columns everywhere; net qty = sold − returned
 *   cash position        : opening float NEVER inflates any sales number; expense / dept payout are
 *                          labeled money-out movements, never negative sales.
 */
class SalesReportEngineMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private SalesReportEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['cash_bank_account_transactions', 'cash_bank_accounts', 'journal_lines', 'journal_entries', 'accounts', 'sales_return_lines', 'sales_returns', 'sale_payments', 'sales_order_lines', 'sales_orders', 'restaurant_waiters', 'shifts', 'payment_methods', 'products', 'product_variants', 'categories', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch();
        $this->engine = app(SalesReportEngine::class);
    }

    private function seedPopulation(): array
    {
        $conn = DB::connection('tenant');
        $catParent = $conn->table('categories')->insertGetId(['name' => 'Biryani', 'slug' => 'biryani', 'sort_order' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $catChild = $conn->table('categories')->insertGetId(['name' => 'Special Biryani', 'slug' => 'special-biryani', 'parent_id' => $catParent, 'sort_order' => 2, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $cat2 = $conn->table('categories')->insertGetId(['name' => 'Beverages', 'slug' => 'bev', 'sort_order' => 3, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $p1 = $this->makeProduct($catParent, ['name' => 'Khatri Biryani', 'default_selling_price' => 200]);
        $p2 = $this->makeProduct($catChild, ['name' => 'Special Biryani', 'default_selling_price' => 100]);
        $p3 = $this->makeProduct($cat2, ['name' => 'Cola', 'default_selling_price' => 100]);
        $w1 = $conn->table('restaurant_waiters')->insertGetId(['branch_id' => $this->branchId, 'name' => 'Waiter One', 'code' => 'W1', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $w2 = $conn->table('restaurant_waiters')->insertGetId(['branch_id' => $this->branchId, 'name' => 'Waiter Two', 'code' => 'W2', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $cash = $this->makePaymentMethod(['method_type' => 'cash']);
        $card = $this->makePaymentMethod(['method_type' => 'card']);
        $today = now()->toDateString();

        $mkSale = function (array $attrs, array $lines, array $payments) use ($conn, $today) {
            $saleId = $this->makeSale($this->branchId, array_merge(['business_date' => $today, 'status' => 'paid'], $attrs));
            $lineIds = [];
            foreach ($lines as [$productId, $qty, $price, $returnedQty]) {
                $pname = $conn->table('products')->where('id', $productId)->value('name');
                $lineIds[] = $conn->table('sales_order_lines')->insertGetId([
                    'sales_order_id' => $saleId, 'product_id' => $productId, 'product_name' => $pname,
                    'quantity' => $qty, 'returned_quantity' => $returnedQty, 'unit_price' => $price,
                    'line_total' => $qty * $price, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ($payments as [$methodId, $amount]) {
                $conn->table('sale_payments')->insert(['sales_order_id' => $saleId, 'payment_method_id' => $methodId, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
            }

            return [$saleId, $lineIds];
        };

        // A: dine_in, W1, cash 500 — 2×P1@200 + 1×P2@100.
        [$saleA] = $mkSale(['order_type' => 'dine_in', 'restaurant_waiter_id' => $w1, 'subtotal' => 500, 'grand_total' => 500, 'paid_amount' => 500],
            [[$p1, 2, 200, 0], [$p2, 1, 100, 0]], [[$cash, 500]]);
        // B: takeaway, UNASSIGNED, card 300 — 3×P3@100; later partially returned (1 unit / 100).
        [$saleB, $bLines] = $mkSale(['order_type' => 'takeaway', 'subtotal' => 300, 'grand_total' => 300, 'paid_amount' => 300, 'status' => 'partially_returned'],
            [[$p3, 3, 100, 1]], [[$card, 300]]);
        // C: delivery, W2, cash 330 — 1×P1 + 1×P2 + DELIVERY CHARGE 30 (header-level).
        [$saleC] = $mkSale(['order_type' => 'delivery', 'restaurant_waiter_id' => $w2, 'subtotal' => 300, 'delivery_charge_amount' => 30, 'grand_total' => 330, 'paid_amount' => 330],
            [[$p1, 1, 200, 0], [$p2, 1, 100, 0]], [[$cash, 330]]);

        // posted return on B (1×P3 = 100, no tax).
        $returnId = $conn->table('sales_returns')->insertGetId([
            'return_no' => 'SR-1', 'sales_order_id' => $saleB, 'branch_id' => $this->branchId,
            'return_date' => now(), 'subtotal' => 100, 'tax_amount' => 0, 'grand_total' => 100,
            'refund_method' => 'cash', 'refund_amount' => 100, 'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('sales_return_lines')->insert([
            'sales_return_id' => $returnId, 'sales_order_line_id' => $bLines[0], 'product_id' => $p3,
            'quantity' => 1, 'unit_price' => 100, 'tax_amount' => 0, 'line_total' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // opening-cash shift (MUST never touch sales numbers).
        $terminalId = $this->makeTerminal($this->branchId);
        $userId = $this->makeUser(['default_branch_id' => $this->branchId]);
        $conn->table('shifts')->insert([
            'branch_id' => $this->branchId, 'terminal_id' => $terminalId, 'opened_by_user_id' => $userId,
            'opening_cash' => 1000, 'expected_cash' => 1830, 'business_date' => $today,
            'status' => 'open', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // cash ledger: sales receipts in, expense + DEPARTMENT HANDOVER PAYOUT out.
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        $acctId = $conn->table('accounts')->where('code', '1010')->value('id') ?: $conn->table('accounts')->orderBy('id')->value('id');
        $cashAcct = $conn->table('cash_bank_accounts')->insertGetId([
            'account_id' => $acctId, 'branch_id' => $this->branchId, 'code' => 'CASH-1', 'name' => 'Main Cash',
            'account_type' => 'cash', 'opening_balance' => 0, 'current_balance' => 0, 'is_default' => 1, 'is_system' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mkMove = fn (string $type, string $dir, float $amount) => $conn->table('cash_bank_account_transactions')->insert([
            'cash_bank_account_id' => $cashAcct, 'transaction_date' => $today, 'direction' => $dir,
            'amount' => $amount, 'balance_after' => 0, 'transaction_type' => $type, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mkMove('sales_payment', 'in', 830);
        $mkMove('expense_payment', 'out', 200);
        $mkMove('dept_handover_payout', 'out', 150);

        return ['w1' => $w1, 'w2' => $w2];
    }

    public function test_all_report_dimensions_reconcile_and_cash_position_stays_separate(): void
    {
        $this->seedPopulation();
        $f = $this->engine->normalizeFilters(['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]);

        // ── overview ──
        $o = $this->engine->overview($f);
        $this->assertSame(3, $o['orders']);
        $this->assertSame(8.0, $o['sold_qty']);
        $this->assertSame(1.0, $o['returned_qty']);
        $this->assertSame(7.0, $o['net_qty']);
        $this->assertSame(1100.0, $o['gross_sales'], 'Σsubtotal');
        $this->assertSame(30.0, $o['delivery_charge']);
        $this->assertSame(1130.0, $o['grand_total']);
        $this->assertSame(100.0, $o['returns_amount'], 'returns stay a SEPARATE column');
        $this->assertSame(1030.0, $o['net_sales'], 'net = grand − period returns');
        $this->assertSame(100.0, $o['refunds_recorded']);
        $this->assertSame(100.0, $o['cash_refunds']);
        $this->assertSame(0.0, $o['returns_not_refunded']);
        $this->assertSame(730.0, $o['net_cash_from_sales']);
        $this->assertSame(830.0, $o['payments']['cash']);
        $this->assertSame(300.0, $o['payments']['card']);

        // ── sale-dimension identity: overview = Σwaiter = Σorder_type ──
        $waiters = collect($this->engine->byWaiter($f));
        $this->assertSame(1030.0, $waiters->sum('net_sales'), 'Σwaiter net = overview net');
        $unassigned = $waiters->firstWhere('label', 'Unassigned');
        $this->assertNotNull($unassigned, 'no silent omission — Unassigned bucket exists');
        $this->assertSame(200.0, $unassigned['net_sales'], '300 − 100 return');
        $orderTypes = collect($this->engine->byOrderType($f));
        $this->assertSame(1030.0, $orderTypes->sum('net_sales'), 'Σorder-type net = overview net');
        $this->assertSame(330.0, $orderTypes->firstWhere('label', 'Delivery')['grand_total']);
        $this->assertSame(30.0, $orderTypes->firstWhere('label', 'Delivery')['delivery_charge']);

        // ── line-dimension identity: Σcategory = Σitem = Σdetailed (grand = line net + delivery) ──
        $categories = collect($this->engine->byCategory($f));
        $this->assertSame(1100.0, $categories->sum('net'), 'Σcategory line-net');
        $this->assertSame(1000.0, $categories->sum('net_value'), 'category sold value less period returns');
        $biryani = $categories->firstWhere('name', 'Biryani');
        $this->assertSame(800.0, $biryani['net'], 'parent rollup: P1 600 + child P2 200');
        $this->assertSame(2, $biryani['orders'], 'parent orders is a DISTINCT count over the subtree (A + C), not the child-sum 4');
        $this->assertSame(200.0, collect($biryani['children'])->firstWhere('name', 'Special Biryani')['net'], 'child stays visible inside the rollup');
        $items = collect($this->engine->byItem($f));
        $this->assertSame(1100.0, $items->sum(fn ($r) => (float) $r->net), 'Σitem net');
        $this->assertSame(1000.0, $items->sum(fn ($r) => (float) $r->net_value), 'item sold value less period returns');
        $detailed = $this->engine->detailedQuery($f)->get();
        $this->assertSame(1100.0, (float) $detailed->sum('line_total'), 'Σdetailed line net');
        $this->assertSame(1130.0, $o['grand_total'], 'grand = line net 1100 + delivery charge 30 (explicit bridge)');
        $cola = $items->first(fn ($r) => $r->item === 'Cola');
        $this->assertSame(1.0, (float) $cola->returned_qty);
        $this->assertSame(2.0, (float) $cola->net_qty, 'returns reduce net qty, visibly');

        // ── cash & bank: a DIFFERENT question — opening float never inflates sales ──
        $cb = $this->engine->cashBank($f);
        $this->assertSame(1000.0, (float) $cb['shifts']['opening_cash']);
        $this->assertSame(1030.0, $o['net_sales'], 'sales numbers unchanged by the shift/movement rows');
        $this->assertSame(830.0, $cb['cash_in']);
        $this->assertSame(350.0, $cb['cash_out'], 'expense 200 + dept payout 150');
        $this->assertSame(480.0, $cb['net_cash_movement']);
        $this->assertSame(1830.0, $cb['expected_cash_formula'], 'opening 1000 + cash sales 830');
        $labels = collect($cb['movements'])->pluck('label');
        $this->assertContains('Paid to department owners (handover payout)', $labels->all(), 'the dept-handover journey is a labeled money-out row');
        $this->assertContains('Expenses paid', $labels->all());
    }

    public function test_filters_slice_every_dimension_consistently(): void
    {
        $ids = $this->seedPopulation();
        $base = ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()];

        // order-type filter: delivery only.
        $f = $this->engine->normalizeFilters($base + ['order_type' => 'delivery']);
        $o = $this->engine->overview($f);
        $this->assertSame(1, $o['orders']);
        $this->assertSame(330.0, $o['grand_total']);
        $this->assertSame(330.0, collect($this->engine->byWaiter($f))->sum('grand_total'));
        $this->assertSame(300.0, collect($this->engine->byItem($f))->sum(fn ($r) => (float) $r->net));

        // waiter filter: W1 only.
        $f = $this->engine->normalizeFilters($base + ['waiter_id' => $ids['w1']]);
        $this->assertSame(500.0, $this->engine->overview($f)['net_sales']);
    }

    /**
     * A CATEGORY filter narrows to specific LINES within a bill, so the order-level
     * sections (overview, waiters, order types) must measure only those lines too — not
     * every bill that merely contains the category. Otherwise the headline dwarfs the
     * category the operator filtered to, and order-level charges (the delivery on bill C)
     * that belong to no single category inflate it. With every filter "All", nothing
     * changes and the report still reconciles order-level.
     */
    public function test_a_category_filter_narrows_the_order_level_sections_to_line_value(): void
    {
        $this->seedPopulation();
        $base = ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()];
        $biryani = DB::connection('tenant')->table('categories')->where('name', 'Biryani')->value('id');

        $f = $this->engine->normalizeFilters($base + ['category_id' => $biryani]);

        // Overview = the CATEGORY's line value, not the whole bills that contain it.
        $o = $this->engine->overview($f);
        $this->assertSame(2, $o['orders'], 'only bills A + C contain Biryani; B is beverages-only');
        $this->assertSame(800.0, $o['gross_sales'], 'Biryani lines only');
        $this->assertSame(800.0, $o['grand_total'], 'billed = category line net, not the bill');
        $this->assertSame(0.0, $o['delivery_charge'], "bill C's 30 delivery is the order's, not the category's");
        $this->assertSame(800.0, $o['net_sales']);
        $this->assertSame([], $o['payments'], 'a payment method is not category-attributable');
        $this->assertSame(800.0, $o['cash_collected']);
        $this->assertSame(800.0, $o['net_cash_from_sales']);

        // It reconciles with the categories tab — the two used to disagree.
        $catNet = collect($this->engine->byCategory($f))->sum('net_value');
        $this->assertSame(800.0, $catNet);
        $this->assertSame($o['net_sales'], $catNet, 'overview == categories tab');

        // Waiters and order types now sum to the SAME narrowed net — no whole-bill inflation.
        $this->assertSame(800.0, collect($this->engine->byWaiter($f))->sum('net_sales'), 'Sum waiter net = category net');
        $this->assertSame(800.0, collect($this->engine->byOrderType($f))->sum('net_sales'), 'Sum order-type net = category net');
        $delivery = collect($this->engine->byOrderType($f))->firstWhere('label', 'Delivery');
        $this->assertSame(300.0, $delivery['grand_total'], 'delivery order type carries its Biryani lines only (300), not +30');
        $this->assertSame(0.0, $delivery['delivery_charge']);

        // REQUIREMENT: every filter "All" → unchanged, still reconciles order-level.
        $all = $this->engine->overview($this->engine->normalizeFilters($base));
        $this->assertSame(1130.0, $all['grand_total'], 'unfiltered overview unchanged: whole bills + delivery');
        $this->assertSame(30.0, $all['delivery_charge']);
        $this->assertSame(830.0, $all['payments']['cash'], 'unfiltered payments unchanged');
    }

    /**
     * sales_return_lines.line_total is the FINAL refunded line value (subtotal − discount + tax)
     * since 2026_08_11_000004 normalised it. The category rollup used to add tax on top of it,
     * which silently double-counted tax on every taxed return. Pin the column's meaning here:
     * a category's returns_amount must equal the money the customer actually got back.
     */
    public function test_category_returns_value_never_double_counts_tax(): void
    {
        $conn = DB::connection('tenant');
        $categoryId = $this->makeCategory(['name' => 'Taxed', 'slug' => 'taxed']);
        $productId = $this->makeProduct($categoryId, ['name' => 'Taxed Item', 'default_selling_price' => 100]);
        $saleId = $this->makeSale($this->branchId, [
            'order_type' => 'takeaway', 'status' => 'partially_returned',
            'subtotal' => 200, 'discount_amount' => 20, 'tax_amount' => 18,
            'grand_total' => 198, 'paid_amount' => 198,
        ]);
        $lineId = $this->makeSaleLine($saleId, $productId, [
            'quantity' => 2, 'returned_quantity' => 1, 'unit_price' => 100,
            'discount_amount' => 20, 'tax_amount' => 18, 'line_total' => 198,
        ]);

        // refund of one unit: 100 gross − 10 discount + 9 tax = 99 actually handed back.
        $returnId = $conn->table('sales_returns')->insertGetId([
            'return_no' => 'SR-TAX', 'sales_order_id' => $saleId, 'branch_id' => $this->branchId,
            'return_date' => now(), 'subtotal' => 100, 'discount_amount' => 10, 'tax_amount' => 9,
            'grand_total' => 99, 'refund_method' => 'cash', 'refund_amount' => 99, 'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('sales_return_lines')->insert([
            'sales_return_id' => $returnId, 'sales_order_line_id' => $lineId, 'product_id' => $productId,
            'quantity' => 1, 'unit_price' => 100, 'discount_amount' => 10, 'tax_amount' => 9,
            'line_total' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $f = $this->engine->normalizeFilters(['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]);
        $category = collect($this->engine->byCategory($f))->firstWhere('name', 'Taxed');

        $this->assertNotNull($category);
        $this->assertSame(99.0, (float) $category['returns_amount'], 'category returns = the refunded money, tax counted once');
    }

    public function test_a_return_today_for_an_older_sale_is_visible_in_every_return_dimension(): void
    {
        $conn = DB::connection('tenant');
        $categoryId = $this->makeCategory(['name' => 'Historical', 'slug' => 'historical']);
        $productId = $this->makeProduct($categoryId, ['name' => 'Historical Item', 'default_selling_price' => 100]);
        $yesterday = now()->subDay();
        $saleId = $this->makeSale($this->branchId, [
            'order_type' => 'takeaway', 'status' => 'returned', 'sale_date' => $yesterday,
            'business_date' => $yesterday->toDateString(), 'subtotal' => 100,
            'grand_total' => 100, 'paid_amount' => 100,
        ]);
        $lineId = $this->makeSaleLine($saleId, $productId, [
            'quantity' => 1, 'returned_quantity' => 1, 'unit_price' => 100, 'line_total' => 100,
        ]);
        $returnId = $conn->table('sales_returns')->insertGetId([
            'return_no' => 'SR-HISTORICAL', 'sales_order_id' => $saleId, 'branch_id' => $this->branchId,
            'return_date' => now(), 'subtotal' => 100, 'grand_total' => 100,
            'refund_method' => null, 'refund_amount' => 0, 'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('sales_return_lines')->insert([
            'sales_return_id' => $returnId, 'sales_order_line_id' => $lineId, 'product_id' => $productId,
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $f = $this->engine->normalizeFilters(['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]);
        $overview = $this->engine->overview($f);
        $this->assertSame(0.0, $overview['sold_qty']);
        $this->assertSame(1.0, $overview['returned_qty']);
        $this->assertSame(-100.0, $overview['net_sales']);
        $this->assertSame(100.0, $overview['returns_not_refunded']);

        $category = collect($this->engine->byCategory($f))->firstWhere('name', 'Historical');
        $this->assertSame(0.0, (float) $category['net']);
        $this->assertSame(100.0, (float) $category['returns_amount']);
        $this->assertSame(-100.0, (float) $category['net_value']);

        $item = collect($this->engine->byItem($f))->first(fn ($row) => (int) $row->product_id === $productId);
        $this->assertNotNull($item);
        $this->assertSame(1.0, (float) $item->returned_qty);
        $this->assertSame(-100.0, (float) $item->net_value);

        $takeaway = collect($this->engine->byOrderType($f))->firstWhere('label', 'Takeaway');
        $this->assertSame(100.0, (float) $takeaway['returns_amount']);
        $this->assertSame(-100.0, (float) $takeaway['net_sales']);

        $combos = $this->engine->orderTypeCombos($f);
        $this->assertSame(-100.0, (float) collect($combos['categories']['Takeaway'])->sum('net_value'));
        $this->assertSame(-100.0, (float) collect($combos['items']['Takeaway'])->sum('net_value'));
        $this->assertSame(-100.0, (float) collect($combos['waiters']['Takeaway'])->sum('net_sales'));
    }
}
