<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * DEV EDGE INSTANCE SEED (W7 target, owner directive 20 Sep 2026) — NOT a test of behaviour.
 *
 * Runs ONLY when EDGE_DEV_SEED=1 and the tenant DB is the dedicated dev database (tools/edge-dev-instance/seed.sh sets
 * both); otherwise it is skipped. It rebuilds `bingoo_edge_devtest_local` through the REAL tenant + edge migrations (the
 * MySQL test case does that) and fills it with a PRODUCTION-SHAPED menu — the shapes the audit found missing from every
 * earlier proof (parent/child categories, variants with barcodes, modifier groups, a weighted item, deals, tables, waiters,
 * void reasons, delivery channels/riders, customers + addresses, network printers, two terminals, cashier + manager).
 *
 * The data is disposable and local. No Cloud pairing, no outbox sender, no live tenant. Credentials are DEV-ONLY constants.
 */
class EdgeDevInstanceSeedMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    public const DEV_DB = 'bingoo_edge_devtest_local';

    protected function setUp(): void
    {
        if (getenv('EDGE_DEV_SEED') !== '1') {
            $this->markTestSkipped('dev-instance seed — run through tools/edge-dev-instance/seed.sh (EDGE_DEV_SEED=1).');
        }
        if ((string) env('EDGE_TEST_TENANT_DB') !== self::DEV_DB) {
            $this->markTestSkipped('dev-instance seed refuses any database other than ' . self::DEV_DB . '.');
        }
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
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    public function test_seed_the_dev_edge_instance(): void
    {
        $this->assertSame(self::DEV_DB, $this->tenantDb);
        $this->cleanTenant([
            'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta',
            'kot_batch_lines', 'kot_batches', 'print_jobs', 'manager_approvals', 'model_has_permissions', 'permissions',
            'terminal_user', 'branch_user', 'category_printer_mappings', 'terminal_printer_settings', 'printers',
            'product_barcodes', 'product_modifier_group', 'modifiers', 'modifier_groups', 'combo_components', 'combos', 'product_variants',
            'customer_addresses', 'customers', 'delivery_riders', 'delivery_channels', 'void_reasons',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'sales_return_lines', 'sales_returns', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods',
            'products', 'units', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $t = fn (string $table) => DB::connection('tenant')->table($table);
        $now = now();

        // ── branch, terminals, operators ────────────────────────────────────────────────────────────────────────
        $branchId = $this->makeBranch([
            'name' => 'Home Lab Restaurant', 'timezone' => 'Asia/Karachi', 'allow_negative_stock' => 0,
            'manual_discount_approval_mode' => Branch::MANUAL_DISCOUNT_MANAGER_REQUIRED,
            'default_delivery_charge' => 150, 'delivery_charge_locked' => 0, 'hide_amounts_from_operators' => 1,
        ]);
        $counter1 = $this->makeTerminal($branchId, ['code' => 'C1', 'name' => 'Counter 1']);
        $counter2 = $this->makeTerminal($branchId, ['code' => 'C2', 'name' => 'Counter 2']);
        $cashierId = $this->makeUser(['name' => 'Ayesha Cashier', 'employee_code' => 'DEVCASH1', 'default_branch_id' => $branchId, 'default_terminal_id' => $counter1]);
        $managerId = $this->makeUser(['name' => 'Mohsin Manager', 'employee_code' => 'DEVMGR1', 'default_branch_id' => $branchId]);
        $this->bindEdgeLocalMeta($branchId, 1, 42, 'dev-edge-instance');
        $this->seedEdgeCredential($cashierId, $branchId, 1, 'CashierPass1');
        $this->seedEdgeCredential($managerId, $branchId, 1, 'MgrPass1');
        foreach (['tenant.sales-returns.store', 'tenant.pos.quick-report-send'] as $p) {
            $this->grantEdgePermission($cashierId, $p);
            $this->grantEdgePermission($managerId, $p);
        }
        foreach (['tenant.pos.void-kot-item', 'tenant.pos.change-terminal', 'tenant.shifts.view-amounts',
            \App\Services\Edge\EdgeLocalSupplierFinanceService::PERM_LEDGER, \App\Services\Edge\EdgeLocalSupplierFinanceService::PERM_PAYMENT,
            \App\Services\Edge\EdgeLocalSupplierFinanceService::PERM_JOURNAL, \App\Services\Edge\EdgeLocalPurchaseReturnService::PERM_STORE,
            \App\Services\Edge\EdgeLocalPurchaseReturnService::PERM_POST] as $p) {
            $this->grantEdgePermission($managerId, $p);
        }

        // ── units, categories (parent + child) ───────────────────────────────────────────────────────────────────
        $pc = $t('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $kg = $t('units')->insertGetId(['code' => 'kg', 'name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $cat = fn (string $name, ?int $parent = null, int $sort = 0) => $this->makeCategory(['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)) . '-' . uniqid(), 'parent_id' => $parent, 'sort_order' => $sort, 'is_active' => 1]);
        $grills = $cat('Grills', null, 1);
        $chicken = $cat('Chicken', $grills, 1);
        $beef = $cat('Beef', $grills, 2);
        $karahi = $cat('Karahi', null, 2);
        $beverages = $cat('Beverages', null, 3);
        $hot = $cat('Hot Drinks', $beverages, 1);
        $cold = $cat('Cold Drinks', $beverages, 2);
        $breads = $cat('Breads', null, 4);
        $desserts = $cat('Desserts', null, 5);
        $deals = $cat('Deals', null, 6);

        // ── products: plain, weighted, variants, modifiers ──────────────────────────────────────────────────────
        $prod = fn (int $catId, string $name, float $price, array $extra = []) => $this->makeProduct($catId, array_merge([
            'name' => $name, 'sku' => 'SKU-' . strtoupper(substr(preg_replace('/[^a-z]/i', '', $name), 0, 8)) . '-' . random_int(100, 999),
            'unit_id' => $pc, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => $price,
            'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1,
        ], $extra));
        $tikka = $prod($chicken, 'Chicken Tikka', 250);
        $boti = $prod($chicken, 'Malai Boti', 300);
        $kabab = $prod($beef, 'Beef Seekh Kabab', 280);
        $chKarahi = $prod($karahi, 'Chicken Karahi', 1200, ['has_variants' => 1]);
        $mtKarahi = $prod($karahi, 'Mutton Karahi (per kg)', 2400, ['unit_id' => $kg]);
        $juice = $prod($cold, 'Fresh Juice', 200, ['has_variants' => 1]);
        $tea = $prod($hot, 'Tea', 80);
        $drink = $prod($cold, 'Cold Drink', 100);
        $naan = $prod($breads, 'Naan', 40);
        $roti = $prod($breads, 'Roti', 25);
        $kheer = $prod($desserts, 'Kheer', 150);
        $gulab = $prod($desserts, 'Gulab Jamun', 120);
        $raita = $prod($grills, 'Raita', 60);
        $salad = $prod($grills, 'Salad', 80);

        $variant = fn (int $productId, string $name, float $price, bool $default, string $barcode) => $t('product_variants')->insertGetId([
            'product_id' => $productId, 'sku' => 'V-' . strtoupper(substr(preg_replace('/[^a-z]/i', '', $name), 0, 6)) . '-' . random_int(100, 999),
            'name' => $name, 'barcode' => $barcode, 'selling_price' => $price, 'is_default' => $default ? 1 : 0, 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $karahiHalf = $variant($chKarahi, 'Half', 650, true, '8901001');
        $karahiFull = $variant($chKarahi, 'Full', 1200, false, '8901002');
        $juiceSmall = $variant($juice, 'Small', 200, true, '8902001');
        $juiceLarge = $variant($juice, 'Large', 300, false, '8902002');
        foreach ([[$drink, null, '8903001'], [$chKarahi, $karahiHalf, '8901001'], [$chKarahi, $karahiFull, '8901002'], [$juice, $juiceSmall, '8902001'], [$juice, $juiceLarge, '8902002']] as [$pid, $vid, $code]) {
            $t('product_barcodes')->insert(['product_id' => $pid, 'product_variant_id' => $vid, 'barcode' => $code, 'barcode_type' => 'manual', 'is_primary' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }

        $spice = $t('modifier_groups')->insertGetId(['branch_id' => null, 'name' => 'Spice Level', 'min_select' => 1, 'max_select' => 1, 'is_required' => 1, 'sort_order' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $extras = $t('modifier_groups')->insertGetId(['branch_id' => null, 'name' => 'Extras', 'min_select' => 0, 'max_select' => 3, 'is_required' => 0, 'sort_order' => 2, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        foreach ([['Mild', 0, 1, null], ['Medium', 0, 0, null], ['Hot', 0, 0, null]] as $i => [$n, $d, $def, $lp]) {
            $t('modifiers')->insert(['modifier_group_id' => $spice, 'name' => $n, 'price_delta' => $d, 'linked_product_id' => $lp, 'is_default' => $def, 'sort_order' => $i, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([['Extra Cheese', 50, null], ['Extra Sauce', 30, null], ['Extra Naan', 40, $naan]] as $i => [$n, $d, $lp]) {
            $t('modifiers')->insert(['modifier_group_id' => $extras, 'name' => $n, 'price_delta' => $d, 'linked_product_id' => $lp, 'is_default' => 0, 'sort_order' => $i, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([[$tikka, $spice], [$boti, $spice], [$kabab, $spice], [$tikka, $extras], [$chKarahi, $extras]] as $i => [$pid, $gid]) {
            $t('product_modifier_group')->insert(['product_id' => $pid, 'modifier_group_id' => $gid, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }

        // ── deals ────────────────────────────────────────────────────────────────────────────────────────────────
        $combo = fn (string $code, string $name, float $price, ?int $catId, array $parts) => tap($t('combos')->insertGetId([
            'branch_id' => null, 'category_id' => $catId, 'code' => $code, 'name' => $name, 'price' => $price, 'sort_order' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]), function (int $id) use ($parts, $t, $now) {
            foreach ($parts as $i => [$pid, $qty]) {
                $t('combo_components')->insert(['combo_id' => $id, 'product_id' => $pid, 'quantity' => $qty, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
            }
        });
        $combo('FAMILY', 'Family Deal', 1999, $deals, [[$tikka, 2], [$naan, 4], [$drink, 2]]);
        $combo('TEATIME', 'Tea Time Deal', 250, $deals, [[$tea, 2], [$gulab, 1]]);

        // ── tenders, waiters, floors/tables, void reasons, delivery, customers ──────────────────────────────────
        $this->makePaymentMethod(['code' => 'CASH', 'name' => 'Cash', 'method_type' => 'cash']);
        $this->makePaymentMethod(['code' => 'CARD', 'name' => 'Card', 'method_type' => 'card']);
        $this->makePaymentMethod(['code' => 'BANK', 'name' => 'Bank Transfer', 'method_type' => 'bank_transfer']);
        foreach (['Ali', 'Bilal', 'Danish'] as $w) {
            $this->makeWaiter($branchId, ['name' => $w, 'code' => 'W-' . strtoupper(substr($w, 0, 3))]);
        }
        $ground = $t('restaurant_floors')->insertGetId(['branch_id' => $branchId, 'name' => 'Ground Floor', 'code' => 'G', 'status' => 'active', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $family = $t('restaurant_floors')->insertGetId(['branch_id' => $branchId, 'name' => 'Family Hall', 'code' => 'F', 'status' => 'active', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now]);
        foreach (range(1, 6) as $i) {
            $t('restaurant_tables')->insert(['branch_id' => $branchId, 'restaurant_floor_id' => $ground, 'table_no' => 'G' . $i, 'name' => 'Ground ' . $i, 'capacity' => 4, 'status' => 'available', 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (range(1, 4) as $i) {
            $t('restaurant_tables')->insert(['branch_id' => $branchId, 'restaurant_floor_id' => $family, 'table_no' => 'F' . $i, 'name' => 'Family ' . $i, 'capacity' => 6, 'status' => 'available', 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([['Customer changed mind', 'cancellation', 0], ['Wrong item punched', 'void', 0], ['Kitchen delay', 'cancellation', 1]] as [$n, $type, $mgr]) {
            $t('void_reasons')->insert(['name' => $n, 'reason_type' => $type, 'requires_manager_approval' => $mgr, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }
        $own = $t('delivery_channels')->insertGetId(['name' => 'Own Delivery', 'type' => 'own', 'commission_percent' => 0, 'is_active' => 1, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $t('delivery_channels')->insert(['name' => 'FoodPanda', 'type' => 'aggregator', 'commission_percent' => 25, 'is_active' => 1, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now]);
        foreach (['Kamran', 'Saad'] as $r) {
            $t('delivery_riders')->insert(['branch_id' => $branchId, 'name' => 'Rider ' . $r, 'phone' => '0300' . random_int(1000000, 9999999), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        }
        $cust = fn (string $code, string $name, string $phone) => $t('customers')->insertGetId(['code' => $code, 'name' => $name, 'phone' => $phone, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $c1 = $cust('CUST-001', 'Ahmed Raza', '03001234567');
        $c2 = $cust('CUST-002', 'Sana Khan', '03211234567');
        $cust('CUST-003', 'Usman Tariq', '03331234567');
        $t('customer_addresses')->insert(['customer_id' => $c1, 'label' => 'Home', 'address' => 'House 12, Street 4, Gulberg III, Lahore', 'is_default' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $t('customer_addresses')->insert(['customer_id' => $c1, 'label' => 'Office', 'address' => 'Suite 5, Liberty Plaza, Lahore', 'is_default' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $t('customer_addresses')->insert(['customer_id' => $c2, 'label' => 'Home', 'address' => 'Flat 3B, DHA Phase 5, Lahore', 'is_default' => 1, 'created_at' => $now, 'updated_at' => $now]);

        // ── printers (LAB FakePrinter on loopback), terminal settings, category routing ─────────────────────────
        $kotPrinter = $this->makePrinter(['branch_id' => $branchId, 'name' => 'Kitchen (FakePrinter 9100)', 'code' => 'KOT-1', 'print_role' => 'kot', 'ip_address' => '127.0.0.1', 'port' => 9100, 'paper_size' => '80mm', 'is_default' => 1]);
        $rcpPrinter = $this->makePrinter(['branch_id' => $branchId, 'name' => 'Counter receipt (FakePrinter 9100)', 'code' => 'RCP-1', 'print_role' => 'receipt', 'ip_address' => '127.0.0.1', 'port' => 9100, 'paper_size' => '80mm', 'is_default' => 1]);
        foreach ([$counter1, $counter2] as $term) {
            $t('terminal_printer_settings')->insert(['terminal_id' => $term, 'receipt_printer_id' => $rcpPrinter, 'kot_printer_id' => $kotPrinter, 'auto_print_receipt' => 1, 'auto_print_kot' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([$grills, $karahi, $breads] as $cid) {
            $t('category_printer_mappings')->insert(['branch_id' => $branchId, 'category_id' => $cid, 'printer_id' => $kotPrinter, 'print_role' => 'kot', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }

        // ── operational stock baseline for everything sellable ─────────────────────────────────────────────────
        $items = [];
        foreach ([$tikka, $boti, $kabab, $tea, $drink, $naan, $roti, $kheer, $gulab, $raita, $salad] as $pid) {
            $items[] = ['product_id' => $pid, 'product_variant_id' => null, 'quantity' => 50];
        }
        $items[] = ['product_id' => $mtKarahi, 'product_variant_id' => null, 'quantity' => 25];
        foreach ([[$chKarahi, $karahiHalf], [$chKarahi, $karahiFull], [$juice, $juiceSmall], [$juice, $juiceLarge]] as [$pid, $vid]) {
            $items[] = ['product_id' => $pid, 'product_variant_id' => $vid, 'quantity' => 20];
        }
        $this->acceptTestBaseline($items);

        $summary = sprintf(
            "DEV EDGE SEEDED db=%s branch=%d terminals=%d/%d products=%d variants=%d modifier_groups=%d combos=%d tables=%d cashier=DEVCASH1 manager=DEVMGR1\n",
            $this->tenantDb, $branchId, $counter1, $counter2,
            $t('products')->count(), $t('product_variants')->count(), $t('modifier_groups')->count(), $t('combos')->count(), $t('restaurant_tables')->count()
        );
        fwrite(STDERR, $summary);
        $this->assertSame(14, $t('products')->count());
        $this->assertSame(1, $t('edge_local_meta')->count());
    }
}
