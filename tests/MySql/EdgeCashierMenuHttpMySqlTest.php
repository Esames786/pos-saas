<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W2 (Team 2) — SELL THE REAL MENU on the Branch Server, over REAL HTTP on a branch_server-booted app, through the endpoints the
 * cashier page calls, on a PRODUCTION-SHAPED menu (the dev-instance shapes: parent + child categories, a required single-choice
 * modifier group + an optional multi-choice group with a stock-consuming linked option, a two-variant product with variant
 * barcodes, a product barcode, a weighted (kg) item, a categorised deal):
 *
 *  A3/A4/A5/A8  the page ships the Online tile payload (sku, unit/measurable, tax, sale-resolved price, product + variant barcodes,
 *               variants with price + stock, modifier groups, operational stock) and the Online pill rules;
 *  A7           options are priced + named from the synced modifier book (never the request), min/max enforced server-side,
 *               linked-product stock consumed, the envelope carries the options;
 *  A8           a variant sells at its own price + stock and prints its name; a foreign variant is refused;
 *  A6           a weighted item sells a decimal quantity;
 *  A10          per-line kitchen note persists (KOT / receipt source); line discounts ride the shared totals + approval gate and
 *               stop at the sync-contract boundary with a business message; carried options on Add Round are kept / guarded;
 *  held notes   the order note of a held check is persisted (was accepted and dropped);
 *  order types  a direct dine-in sale answers with the Online rule; own delivery needs a rider (Online), an aggregator drops it.
 */
class EdgeCashierMenuHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $cashId;
    private int $grills;
    private int $chicken;
    private int $dealsCat;
    private int $tikka;
    private int $karahi;
    private int $mutton;
    private int $drink;
    private int $naan;
    private int $half;
    private int $full;
    private int $spiceGroup;
    private int $extrasGroup;
    private array $opt = [];
    private int $comboId;
    private int $baselineId;
    private int $ownChannel;
    private int $aggChannel;
    private int $riderId;
    private int $customerId;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();

        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
                'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
                'password' => config('database.connections.tenant.password')]
        )]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');

        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations',
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'manager_approvals', 'model_has_permissions', 'permissions',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'promotion_targets', 'promotions', 'customer_addresses', 'customers', 'delivery_riders', 'delivery_channels',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'stock_ledgers', 'stock_balances',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods',
            'product_barcodes', 'product_branch_prices', 'product_modifier_group', 'modifiers', 'modifier_groups', 'combo_components', 'combos', 'product_variants',
            'products', 'units', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $t = fn (string $table) => DB::connection('tenant')->table($table);
        $now = now();

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'manual_discount_approval_mode' => 'auto_approve', 'delivery_charge_locked' => 0, 'default_delivery_charge' => 150]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter 1']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'default_terminal_id' => $this->terminalId, 'employee_code' => 'MENU' . Str::random(4)]);
        $this->cashId = $this->makePaymentMethod(['method_type' => 'cash', 'name' => 'Cash']);

        // ── the dev-instance menu shapes ──
        $pc = $t('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $kg = $t('units')->insertGetId(['code' => 'kg', 'name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->grills = $this->makeCategory(['name' => 'Grills', 'is_active' => 1, 'sort_order' => 1]);
        $this->chicken = $this->makeCategory(['name' => 'Chicken', 'parent_id' => $this->grills, 'is_active' => 1, 'sort_order' => 1]);
        $karahiCat = $this->makeCategory(['name' => 'Karahi', 'is_active' => 1, 'sort_order' => 2]);
        $breads = $this->makeCategory(['name' => 'Breads', 'is_active' => 1, 'sort_order' => 3]);
        $empty = $this->makeCategory(['name' => 'Empty Shelf', 'is_active' => 1, 'sort_order' => 4]);
        $this->dealsCat = $this->makeCategory(['name' => 'Deals', 'is_active' => 1, 'sort_order' => 5]);
        $prod = fn (int $cat, string $name, float $price, array $extra = []) => $this->makeProduct($cat, array_merge([
            'name' => $name, 'unit_id' => $pc, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => $price,
            'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1,
        ], $extra));
        $this->tikka = $prod($this->chicken, 'Chicken Tikka', 250, ['sku' => 'SKU-TIKKA', 'is_taxable' => 1, 'tax_rate_percent' => 10]);
        $this->karahi = $prod($karahiCat, 'Chicken Karahi', 1200, ['sku' => 'SKU-KARAHI', 'has_variants' => 1]);
        $this->mutton = $prod($karahiCat, 'Mutton Karahi (per kg)', 2400, ['sku' => 'SKU-MUTTON', 'unit_id' => $kg]);
        $this->drink = $prod($breads, 'Cold Drink', 100, ['sku' => 'SKU-DRINK']);
        $this->naan = $prod($breads, 'Naan', 40, ['sku' => 'SKU-NAAN']);
        $this->half = $t('product_variants')->insertGetId(['product_id' => $this->karahi, 'sku' => 'V-HALF', 'name' => 'Half', 'barcode' => '8901001', 'selling_price' => 650, 'is_default' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->full = $t('product_variants')->insertGetId(['product_id' => $this->karahi, 'sku' => 'V-FULL', 'name' => 'Full', 'barcode' => '8901002', 'selling_price' => 1200, 'is_default' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        foreach ([[$this->drink, null, '8903001'], [$this->karahi, $this->half, '8901001'], [$this->karahi, $this->full, '8901002']] as [$pid, $vid, $code]) {
            $t('product_barcodes')->insert(['product_id' => $pid, 'product_variant_id' => $vid, 'barcode' => $code, 'barcode_type' => 'manual', 'is_primary' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->spiceGroup = $t('modifier_groups')->insertGetId(['branch_id' => null, 'name' => 'Spice Level', 'min_select' => 1, 'max_select' => 1, 'is_required' => 1, 'sort_order' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->extrasGroup = $t('modifier_groups')->insertGetId(['branch_id' => null, 'name' => 'Extras', 'min_select' => 0, 'max_select' => 3, 'is_required' => 0, 'sort_order' => 2, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $foreignGroup = $t('modifier_groups')->insertGetId(['branch_id' => null, 'name' => 'Sauces', 'min_select' => 0, 'max_select' => null, 'is_required' => 0, 'sort_order' => 3, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        foreach ([['Mild', 0, 1, null, $this->spiceGroup], ['Medium', 0, 0, null, $this->spiceGroup], ['Hot', 0, 0, null, $this->spiceGroup],
                  ['Extra Cheese', 50, 0, null, $this->extrasGroup], ['Extra Sauce', 30, 0, null, $this->extrasGroup], ['Extra Naan', 40, 0, $this->naan, $this->extrasGroup],
                  ['Garlic Mayo', 20, 0, null, $foreignGroup]] as $i => [$n, $d, $def, $lp, $g]) {
            $this->opt[$n] = $t('modifiers')->insertGetId(['modifier_group_id' => $g, 'name' => $n, 'price_delta' => $d, 'linked_product_id' => $lp,
                'consume_stock' => $lp ? 1 : 0, 'linked_quantity' => $lp ? 1 : null, 'is_default' => $def, 'sort_order' => $i, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([[$this->tikka, $this->spiceGroup, 1], [$this->tikka, $this->extrasGroup, 2], [$this->naan, $foreignGroup, 1]] as [$pid, $gid, $sort]) {
            $t('product_modifier_group')->insert(['product_id' => $pid, 'modifier_group_id' => $gid, 'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->comboId = $t('combos')->insertGetId(['branch_id' => null, 'category_id' => $this->dealsCat, 'code' => 'FAMILY', 'name' => 'Family Deal', 'price' => 1999, 'sort_order' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        foreach ([[$this->tikka, 2], [$this->naan, 4], [$this->drink, 2]] as $i => [$pid, $qty]) {
            $t('combo_components')->insert(['combo_id' => $this->comboId, 'product_id' => $pid, 'quantity' => $qty, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->ownChannel = $t('delivery_channels')->insertGetId(['name' => 'Own Delivery', 'type' => 'own', 'is_active' => 1, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->aggChannel = $t('delivery_channels')->insertGetId(['name' => 'FoodPanda', 'type' => 'aggregator', 'is_active' => 1, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now]);
        $this->riderId = $t('delivery_riders')->insertGetId(['branch_id' => $this->branchId, 'name' => 'Rider Kamran', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->customerId = $t('customers')->insertGetId(['customer_uuid' => (string) Str::ulid(), 'code' => 'CUST-001', 'name' => 'Ahmed Raza', 'phone' => '03001234567', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->baselineId = (int) $this->acceptTestBaseline([
            ['product_id' => $this->tikka, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->naan, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->drink, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->mutton, 'product_variant_id' => null, 'quantity' => 25],
            ['product_id' => $this->karahi, 'product_variant_id' => $this->half, 'quantity' => 20],
            ['product_id' => $this->karahi, 'product_variant_id' => $this->full, 'quantity' => 20],
        ])->id;
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    private function vm(): array
    {
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<script id="edge-pos-data" type="application/json">(.*?)</script>#s', $html, $m));

        return json_decode(html_entity_decode($m[1]), true, 512, JSON_THROW_ON_ERROR);
    }

    private function sale(array $lines, array $extra = [], ?float $pay = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/edge/local/pos/sales', array_merge([
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(), 'lines' => $lines,
            'payments' => [['payment_method_id' => $this->cashId, 'amount' => $pay ?? 100000, 'tendered_amount' => $pay ?? 100000]],
        ], $extra));
    }

    private function mods(string ...$names): array
    {
        return array_map(fn ($n) => ['modifier_group_id' => 0, 'modifier_id' => $this->opt[$n], 'name' => 'CLIENT-NAME', 'price_delta' => 999], $names);
    }

    /** Pay exactly the quoted grand total for these lines (the server refuses short payment). */
    private function paidSale(array $lines, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $q = $this->postJson('/edge/local/pos/preview-bill', array_merge(['order_type' => $extra['order_type'] ?? 'takeaway', 'lines' => $lines], array_diff_key($extra, ['client_uuid' => 1])))->assertOk()->json('totals');

        return $this->sale($lines, $extra, (float) $q['grand_total']);
    }

    public function test_the_page_ships_the_online_tile_payload_and_the_online_pill_rules(): void
    {
        $vm = $this->vm();
        $p = collect($vm['products'])->keyBy('id');

        // A4 tile data: sku, tax, operational stock, the price the sale charges.
        $this->assertSame('SKU-TIKKA', $p[$this->tikka]['sku']);
        $this->assertTrue($p[$this->tikka]['is_taxable']);
        $this->assertSame('tracked', $p[$this->tikka]['stock_kind']);
        $this->assertEquals(50.0, $p[$this->tikka]['stock']);
        $this->assertNull($p[$this->tikka]['image_url'], 'no image file on the appliance → initials avatar, never a broken URL');
        // A6 measurable unit
        $this->assertTrue($p[$this->mutton]['allow_decimal_qty']);
        $this->assertSame('weight', $p[$this->mutton]['unit_type']);
        $this->assertSame('kg', $p[$this->mutton]['unit_code']);
        $this->assertFalse($p[$this->tikka]['allow_decimal_qty']);
        // A5 product barcode; A8 variants with their own price / stock / barcodes; default variant prices the tile
        $this->assertSame(['8903001'], $p[$this->drink]['barcodes']);
        $variants = collect($p[$this->karahi]['variants'])->keyBy('name');
        $this->assertEquals(650.0, $variants['Half']['price']);
        $this->assertEquals(1200.0, $variants['Full']['price']);
        $this->assertContains('8901002', $variants['Full']['barcodes']);
        $this->assertEquals(20.0, $variants['Full']['stock']);
        $this->assertEquals(650.0, $p[$this->karahi]['price']);
        $this->assertSame($this->half, $p[$this->karahi]['default_variant_id']);
        // A7 modifier groups with active options (a group attached to ANOTHER product is not offered here)
        $groups = collect($p[$this->tikka]['modifier_groups'])->keyBy('name');
        $this->assertSame(['Spice Level', 'Extras'], collect($p[$this->tikka]['modifier_groups'])->pluck('name')->all());
        $this->assertSame(1, $groups['Spice Level']['min_select']);
        $this->assertSame(1, $groups['Spice Level']['max_select']);
        $this->assertCount(3, $groups['Extras']['modifiers']);
        $this->assertSame([], $p[$this->karahi]['modifier_groups']);
        // A9 deal components travel for the availability badge
        $deal = collect($vm['combos'])->firstWhere('id', $this->comboId);
        $this->assertSame('FAMILY', $deal['code']);
        $this->assertCount(3, $deal['components']);

        // A3 Online pill rules: Grills gets a pill through its CHILD's products; the empty category gets none; the child strip
        // is offered; a categorised deal file means no flat "Deals" pill (the category named Deals is the deals entry).
        $this->assertContains($this->grills, $vm['pillCategoryIds']);
        $this->assertContains($this->dealsCat, $vm['pillCategoryIds']);
        $this->assertNotContains(collect($vm['categories'])->firstWhere('name', 'Empty Shelf')['id'], $vm['pillCategoryIds']);
        $this->assertContains($this->chicken, $vm['contentCategoryIds']);
        $this->assertSame('Chicken', collect(collect($vm['categories'])->firstWhere('id', $this->grills)['children'])->first()['name']);
        $this->assertFalse($vm['hasUncategorizedCombos']);
        $this->assertFalse($vm['allowNegativeStock']);

        // The Online controls are on the page (ids the census + browser proof target).
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        foreach (['pos_search', 'parent-category-strip', 'child-category-strip', 'child-category-wrap', 'product-grid', 'delivery-panel', 'delivery_channel_id',
                  'delivery-rider-wrap', 'delivery_charge_amount', 'vehicle-wrap', 'vehicle_number', 'qs-waiter-wrap', 'qs-waiter-select'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html, "#{$id} on the page");
        }
        foreach (['qtyEntryModal', 'qty-modal-input', 'qty-modal-amount-input', 'qty-modal-confirm', 'modifierEntryModal', 'modifier-modal-groups', 'modifier-modal-confirm',
                  'customerModal', 'cust-search-input', 'cust-search-results', 'cust-selected-panel', 'cust-attach-btn', 'cust-address-list'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html, "#{$id} rendered by the page script");
        }
    }

    public function test_options_are_priced_and_named_from_the_synced_book_with_min_max_enforced_and_linked_stock_consumed(): void
    {
        // Medium (0) + Extra Cheese (50) + Extra Naan (40, consumes 1 naan) on a 250 tikka, qty 2. Client names/deltas are ignored.
        $lines = [['product_id' => $this->tikka, 'quantity' => 2, 'modifiers' => $this->mods('Medium', 'Extra Cheese', 'Extra Naan')]];
        $preview = $this->postJson('/edge/local/pos/preview-bill', ['order_type' => 'takeaway', 'lines' => $lines])->assertOk()->json();
        $this->assertEquals(340.0, $preview['lines'][0]['unit_price']);
        $this->assertEquals(680.0, (float) $preview['totals']['subtotal']);
        $this->assertEquals(68.0, (float) $preview['totals']['tax_amount'], 'tax on the option-inclusive price (shared resolveTaxAmount)');

        $sale = $this->paidSale($lines)->assertStatus(201)->json();
        $line = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $sale['sale_id'])->first();
        $this->assertEquals(340.0, (float) $line->unit_price);
        $mods = json_decode($line->modifiers, true);
        $this->assertSame(['Medium', 'Extra Cheese', 'Extra Naan'], array_column($mods, 'name'));
        $this->assertSame(['Spice Level', 'Extras', 'Extras'], array_column($mods, 'modifier_group_name'));
        $this->assertEquals([0.0, 50.0, 40.0], array_map('floatval', array_column($mods, 'price_delta')));
        // stock: 2 tikka out, and the linked option consumed 2 naan (1 per line unit)
        $this->assertEquals(48.0, $this->edgeOnHand($this->baselineId, $this->tikka));
        $this->assertEquals(48.0, $this->edgeOnHand($this->baselineId, $this->naan));
        // the sync envelope carries the options (Cloud stores them on the synced line)
        $envelope = json_decode((string) DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $sale['sale_uuid'])->value('envelope'), true);
        $this->assertSame(['Medium', 'Extra Cheese', 'Extra Naan'], array_column(data_get($envelope, 'lines.0.modifiers'), 'name'));

        // min: the required single-choice group missing → refused, nothing written
        $before = DB::connection('tenant')->table('sales_orders')->count();
        $this->sale([['product_id' => $this->tikka, 'quantity' => 1, 'modifiers' => $this->mods('Extra Cheese')]])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Select at least 1 option for Spice Level on Chicken Tikka.']);
        $this->sale([['product_id' => $this->tikka, 'quantity' => 1]])->assertStatus(422);
        // max: two spice levels on a max-1 group
        $this->sale([['product_id' => $this->tikka, 'quantity' => 1, 'modifiers' => $this->mods('Mild', 'Hot')]])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Select no more than 1 option for Spice Level on Chicken Tikka.']);
        // an option of a group NOT attached to this product
        $this->sale([['product_id' => $this->tikka, 'quantity' => 1, 'modifiers' => $this->mods('Mild', 'Garlic Mayo')]])
            ->assertStatus(422)->assertJsonFragment(['message' => 'An option chosen for Chicken Tikka is not available on this menu.']);
        // an inactive option is refused too
        DB::connection('tenant')->table('modifiers')->where('id', $this->opt['Extra Sauce'])->update(['status' => 'inactive']);
        $this->sale([['product_id' => $this->tikka, 'quantity' => 1, 'modifiers' => $this->mods('Mild', 'Extra Sauce')]])->assertStatus(422);
        $this->assertSame($before, DB::connection('tenant')->table('sales_orders')->count());
    }

    public function test_a_variant_sells_at_its_own_price_and_stock_and_a_foreign_variant_is_refused(): void
    {
        $sale = $this->paidSale([['product_id' => $this->karahi, 'product_variant_id' => $this->full, 'quantity' => 1]])->assertStatus(201)->json();
        $line = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $sale['sale_id'])->first();
        $this->assertEquals(1200.0, (float) $line->unit_price);
        $this->assertSame($this->full, (int) $line->product_variant_id);
        $this->assertSame('Full', $line->variant_name, 'variant snapshot for KOT / receipt');
        $this->assertSame('pc', $line->unit_code);
        $this->assertEquals(19.0, $this->edgeOnHand($this->baselineId, $this->karahi, $this->full));
        $this->assertEquals(20.0, $this->edgeOnHand($this->baselineId, $this->karahi, $this->half));

        // no variant named → the default variant (the shared resolveVariant rule)
        $sale2 = $this->paidSale([['product_id' => $this->karahi, 'quantity' => 1]])->assertStatus(201)->json();
        $this->assertSame($this->half, (int) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $sale2['sale_id'])->value('product_variant_id'));

        // a variant of another product → a business refusal, never a 500 / model-not-found text
        $this->sale([['product_id' => $this->tikka, 'product_variant_id' => $this->full, 'quantity' => 1, 'modifiers' => $this->mods('Mild')]])
            ->assertStatus(422)->assertJsonFragment(['message' => 'The selected option of Chicken Tikka does not belong to it.']);
        // an inactive variant is not sold on a new line
        DB::connection('tenant')->table('product_variants')->where('id', $this->full)->update(['is_active' => 0]);
        $this->sale([['product_id' => $this->karahi, 'product_variant_id' => $this->full, 'quantity' => 1]])->assertStatus(422);
    }

    public function test_a_weighted_item_sells_a_decimal_quantity_and_a_kitchen_note_persists(): void
    {
        $lines = [['product_id' => $this->mutton, 'quantity' => 1.25, 'kitchen_note' => 'Less oil, extra ginger']];
        $preview = $this->postJson('/edge/local/pos/preview-bill', ['order_type' => 'takeaway', 'lines' => $lines])->assertOk()->json();
        $this->assertEquals(3000.0, (float) $preview['totals']['grand_total']);
        $this->assertSame('Less oil, extra ginger', $preview['lines'][0]['kitchen_note']);

        $sale = $this->paidSale($lines)->assertStatus(201)->json();
        $line = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $sale['sale_id'])->first();
        $this->assertEquals(1.25, (float) $line->quantity);
        $this->assertEquals(3000.0, (float) $line->line_total);
        $this->assertSame('kg', $line->unit_code);
        $this->assertSame('Less oil, extra ginger', $line->kitchen_note, 'kitchen_note is what the shared KOT / receipt / KDS documents print');
        $this->assertEquals(23.75, $this->edgeOnHand($this->baselineId, $this->mutton));
    }

    public function test_held_check_keeps_its_note_and_its_carried_options(): void
    {
        $r = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'takeaway', 'notes' => 'Customer will collect at 8', 'lines' => [
                ['product_id' => $this->tikka, 'quantity' => 1, 'modifiers' => $this->mods('Hot', 'Extra Cheese')],
            ],
        ])->assertStatus(201)->json();
        $sale = DB::connection('tenant')->table('sales_orders')->find($r['sale_id']);
        $this->assertSame('Customer will collect at 8', $sale->notes, 'held-sale notes were accepted and dropped before W2');
        $line = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $sale->id)->first();
        $this->assertEquals(300.0, (float) $line->unit_price);

        // Add Round: the carried line named WITHOUT options keeps its options + captured price; a new drink joins.
        $r2 = $this->postJson('/edge/local/pos/held-sales', ['held_sale_id' => $sale->id, 'order_type' => 'takeaway', 'lines' => [
            ['sales_order_line_id' => $line->id, 'product_id' => $this->tikka, 'quantity' => 2],
            ['product_id' => $this->drink, 'quantity' => 1],
        ]])->assertOk()->json();
        $carried = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $sale->id)->where('product_id', $this->tikka)->first();
        $this->assertSame(['Hot', 'Extra Cheese'], array_column(json_decode($carried->modifiers, true), 'name'));
        $this->assertEquals(300.0, (float) $carried->unit_price);
        $this->assertSame('Customer will collect at 8', DB::connection('tenant')->table('sales_orders')->where('id', $sale->id)->value('notes'), 'a revision without notes keeps the note');

        // naming DIFFERENT options on the carried line is refused (it must be a new line — Online edits options only on an unsent line)
        $this->postJson('/edge/local/pos/held-sales', ['held_sale_id' => $sale->id, 'order_type' => 'takeaway', 'lines' => [
            ['sales_order_line_id' => $carried->id, 'product_id' => $this->tikka, 'quantity' => 2, 'modifiers' => $this->mods('Mild')],
        ]])->assertStatus(422)->assertJsonFragment(['message' => 'A carried line\'s options changed — submit the change as a new line.']);
    }

    public function test_team3_requests_change_order_details_moves_a_held_check_and_guest_count_is_required(): void
    {
        $pos = app(\App\Services\Edge\EdgeLocalPosService::class);
        $user = User::on('tenant')->find($this->userId);
        $tableId = $this->makeTable($this->branchId, ['table_no' => 'G1', 'status' => 'available']);
        // Online RestaurantTableSessionController::open — guest_count required
        try {
            $pos->openTableSession($tableId, [], $user, $this->terminalId);
            $this->fail('guest_count must be required');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame('Enter the number of guests.', $e->errors()['guest_count'][0]);
        }
        $session = $pos->openTableSession($tableId, ['guest_count' => 2], $user, $this->terminalId);

        $held = $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'takeaway', 'lines' => [['product_id' => $this->drink, 'quantity' => 2]]])->assertStatus(201)->json();
        $line = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $held['sale_id'])->first();
        // without the flag a different session is still refused (lock-order guard unchanged)
        try {
            $pos->holdOrReviseSale(['held_sale_id' => $held['sale_id'], 'order_type' => 'dine_in', 'restaurant_table_session_id' => $session->id,
                'lines' => [['sales_order_line_id' => $line->id, 'product_id' => $this->drink, 'quantity' => 2]]], $user, $this->terminalId);
            $this->fail('a session change without change_order_details must be refused');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('restaurant_table_session_id', $e->errors());
        }
        // R21 Change Order Details: the takeaway check moves onto the table as dine-in (Online revision semantics)
        $moved = $pos->holdOrReviseSale(['held_sale_id' => $held['sale_id'], 'change_order_details' => true, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $session->id,
            'lines' => [['sales_order_line_id' => $line->id, 'product_id' => $this->drink, 'quantity' => 2]]], $user, $this->terminalId);
        $this->assertSame('dine_in', $moved->order_type);
        $this->assertSame($session->id, (int) $moved->restaurant_table_session_id);
        $this->assertSame($tableId, (int) $moved->restaurant_table_id);
        $this->assertSame($held['sale_id'], $moved->id, 'the same check (durable sale_uuid), never a new one');
        // and back to takeaway: detached from the session
        $back = $pos->holdOrReviseSale(['held_sale_id' => $held['sale_id'], 'change_order_details' => true, 'order_type' => 'takeaway',
            'lines' => [['sales_order_line_id' => $moved->lines()->first()->id, 'product_id' => $this->drink, 'quantity' => 2]]], $user, $this->terminalId);
        $this->assertSame('takeaway', $back->order_type);
        $this->assertNull($back->restaurant_table_session_id);
    }

    public function test_line_discounts_ride_the_shared_totals_and_the_sync_envelope(): void
    {
        $line = ['product_id' => $this->drink, 'quantity' => 2, 'discount_amount' => 30];
        $q = $this->postJson('/edge/local/pos/preview-bill', ['order_type' => 'takeaway', 'lines' => [$line]])->assertOk()->json('totals');
        $this->assertEquals(30.0, (float) $q['manual_discount_amount'], 'SalesTotalsService folds the line discount into the manual discount (same approval gate)');
        $this->assertEquals(170.0, (float) $q['grand_total']);

        // W6 (0.7.0-edge): a line-ONLY discount is now carried by the sync envelope — lines[].discount_amount explains
        // totals.discount_amount (discount_type stays 'none'); the Cloud posts the same header discount it always did.
        $lineOnly = $this->sale([$line], [], 170)->assertStatus(201)->json();
        $lineOnlyRow = DB::connection('tenant')->table('sales_orders')->find($lineOnly['sale_id']);
        $this->assertEquals(30.0, (float) $lineOnlyRow->discount_amount);
        $this->assertSame('none', (string) $lineOnlyRow->discount_type);
        $lineOnlyEnv = json_decode((string) DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $lineOnlyRow->sale_uuid)->value('envelope'), true);
        $this->assertSame('edge-sale-envelope-v1', $lineOnlyEnv['envelope_schema_version']);
        $this->assertSame('none', data_get($lineOnlyEnv, 'totals.discount_type'));
        $this->assertEquals(30.0, (float) data_get($lineOnlyEnv, 'totals.discount_amount'));
        $this->assertEquals(30.0, (float) data_get($lineOnlyEnv, 'lines.0.discount_amount'));
        $this->assertSame(1, DB::connection('tenant')->table('sales_orders')->count());
        // negative line discount → Online min:0
        $this->sale([['product_id' => $this->drink, 'quantity' => 1, 'discount_amount' => -5]])->assertStatus(422);

        // with an order discount type the envelope carries it: line discount 30 + fixed 20 (branch auto-approves)
        $extra = ['discount_type' => 'fixed', 'discount_value' => 20];
        $sale = $this->paidSale([$line], $extra)->assertStatus(201)->json();
        $row = DB::connection('tenant')->table('sales_orders')->find($sale['sale_id']);
        $this->assertEquals(50.0, (float) $row->discount_amount);
        $this->assertEquals(150.0, (float) $row->grand_total);
        $this->assertEquals(30.0, (float) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $row->id)->value('discount_amount'));
        $envelope = json_decode((string) DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $row->sale_uuid)->value('envelope'), true);
        $this->assertEquals(30.0, (float) data_get($envelope, 'lines.0.discount_amount'));
        $this->assertEquals(50.0, (float) data_get($envelope, 'totals.discount_amount'));
    }

    public function test_order_type_rules_follow_online_dine_in_direct_and_delivery_rider(): void
    {
        // The browser-found defect: Dine In as the default + direct pay answered "Order type [dine_in] is not yet available".
        $this->sale([['product_id' => $this->drink, 'quantity' => 1]], ['order_type' => 'dine_in'], 100)->assertStatus(422)
            ->assertJsonFragment(['message' => 'Select an open table before completing a dine-in order.']);
        $this->sale([['product_id' => $this->drink, 'quantity' => 1]], ['order_type' => 'pizza_party'], 100)->assertStatus(422);

        // Own delivery needs a rider (Online validateDeliveryAttribution); an aggregator's rider is dropped.
        $d = ['order_type' => 'delivery', 'customer_id' => $this->customerId, 'delivery_charge_amount' => 150];
        $this->sale([['product_id' => $this->drink, 'quantity' => 1]], $d + ['delivery_channel_id' => $this->ownChannel], 250)->assertStatus(422)
            ->assertJsonFragment(['message' => 'Select a rider for own-delivery orders.']);
        $own = $this->sale([['product_id' => $this->drink, 'quantity' => 1]], $d + ['delivery_channel_id' => $this->ownChannel, 'delivery_rider_id' => $this->riderId, 'delivery_address' => 'House 12, Gulberg'], 250)->assertStatus(201)->json();
        $this->assertSame($this->riderId, (int) DB::connection('tenant')->table('sales_orders')->where('id', $own['sale_id'])->value('delivery_rider_id'));
        $agg = $this->sale([['product_id' => $this->drink, 'quantity' => 1]], ['order_type' => 'delivery', 'delivery_channel_id' => $this->aggChannel, 'delivery_rider_id' => $this->riderId, 'delivery_charge_amount' => 150], 250)->assertStatus(201)->json();
        $this->assertNull(DB::connection('tenant')->table('sales_orders')->where('id', $agg['sale_id'])->value('delivery_rider_id'));
    }
}
