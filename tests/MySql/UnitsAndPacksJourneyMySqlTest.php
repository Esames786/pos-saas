<?php

namespace Tests\MySql;

use App\Models\Master\Tenant;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductPackUnit;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User;
use App\Services\Inventory\InventoryService;
use App\Services\Tenancy\TenantProvisioner;
use Database\Seeders\Demos\PharmacyDemoSeeder;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Spatie\Permission\PermissionRegistrar;

/**
 * UNIFIED-BUSINESS W2 (22 Oct) — one quantity, end to end: buy → stock → counter sale → return, through the real routes.
 *
 * The roadmap's acceptance pack, on an isolated store:
 *  A03 medicine  1 Box = 10 Strip = 100 Tablet: buy 2 Box, sell 3 Strip, return 1 Strip → 200 → 170 → 180 tablets;
 *  A05 mart      1 Carton = 24 Bottle: receive 2 Carton, sell 3 Bottle, return 1 → 48 → 45 → 46; a carton sells at its
 *                own price, never 24 × the bottle;
 *  A06 loose     rice in kg on the normal POS: receive 25, sell 0.250, return 0.100 → 25 → 24.750 → 24.850;
 *  UB-08         a recipe in grams takes kilograms from stock with no conversion row (kg/g is a rule every shop
 *                agrees on), and the POS counts what it can make in the same unit; a unit with no rule at all (a packet)
 *                still refuses the sale, and the POS then says none can be made instead of reading the number in the
 *                wrong unit.
 */
class UnitsAndPacksJourneyMySqlTest extends MySqlTenantTestCase
{
    private const CODE = 'ujrtest';

    private const COUNTER = ['product_screen_v2' => true, 'packs' => true, 'medical_counter' => true, 'purchasing_v2' => true];
    private const NORMAL_POS = ['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true];

    private static bool $built = false;

    private string $host;
    private string $storeDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = self::CODE . '.' . config('tenancy.tenant_base_domain');
        $this->storeDb = $this->tenantDb . '_ujr';

        if (! self::$built) {
            $this->createStoreDatabase();
            DB::setDefaultConnection('master');
            (new MasterSeeder)->run();
            Artisan::call('system:routes-sync');
            $this->seedMasterTenant();
            $this->useStoreDb();
            $this->buildStore();
            self::$built = true;
        } else {
            $this->useStoreDb();
        }
        $this->setFeatures(self::COUNTER);
    }

    /* ── A03 ─────────────────────────────────────────────────────────────── */

    public function test_a_medicine_bought_in_boxes_sold_in_strips_and_returned_counts_in_tablets_all_the_way(): void
    {
        $tab = Unit::updateOrCreate(['code' => 'TAB'], ['name' => 'Tablet', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $p = $this->product('Journey Paracetamol', $tab->id, ['has_expiry' => true, 'requires_batch' => true, 'default_selling_price' => 10]);
        ProductPackUnit::create(['product_id' => $p->id, 'name' => 'Tablet', 'factor' => 1, 'can_sell' => true, 'can_buy' => true]);
        $strip = ProductPackUnit::create(['product_id' => $p->id, 'name' => 'Strip', 'factor' => 10, 'can_sell' => true, 'can_buy' => false]);
        $box = ProductPackUnit::create(['product_id' => $p->id, 'name' => 'Box', 'factor' => 100, 'can_sell' => true, 'can_buy' => true]);

        $this->receive([['product_id' => $p->id, 'batch_no' => 'jp-1', 'expiry' => now('Asia/Karachi')->addYear()->format('m/y'),
            'buy_pack' => (string) $box->id, 'price' => '900', 'packs' => [(string) $box->id => '2']]]);
        $this->assertEqualsWithDelta(200, $this->stock($p), 0.001, '2 Box = 200 tablets');
        $this->assertEqualsWithDelta(9, (float) $this->lastGrnLine($p)->unit_cost, 0.0001, '900 a box over 100 tablets');

        $sale = $this->sell([['product_id' => $p->id, 'quantity' => 3, 'unit_price' => 95, 'pack_unit_id' => $strip->id]], 285);
        $this->assertTrue($sale->isSuccessful(), 'sale refused: ' . Str::limit($sale->getContent(), 300));
        $this->assertEqualsWithDelta(170, $this->stock($p), 0.001, '3 Strip = 30 tablets out');
        $line = $this->lastSale()->lines()->firstOrFail();
        $this->assertEquals(3, (float) $line->quantity, 'the bill line is in strips');
        $this->assertSame(10, (int) $line->pack_factor);
        $this->assertEquals(30, (float) $line->base_quantity);

        $this->giveBack($line->sales_order_id, $line->id, 1, 95)->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(180, $this->stock($p), 0.001, '1 Strip back = 10 tablets back');
        $this->assertEquals(1, (float) $line->fresh()->returned_quantity, 'counted in the strips it was sold in');
        $this->assertEqualsWithDelta(180, $this->ledgerSum($p), 0.001, 'the stock book says the same: +200 −30 +10');
    }

    /* ── A05 ─────────────────────────────────────────────────────────────── */

    public function test_a_mart_carton_of_24_bottles_received_sold_loose_returned_and_sold_whole_at_its_own_price(): void
    {
        $btl = Unit::updateOrCreate(['code' => 'BTL'], ['name' => 'Bottle', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $p = $this->product('Journey Cola 345ml', $btl->id, ['default_selling_price' => 100]);
        ProductPackUnit::create(['product_id' => $p->id, 'name' => 'Bottle', 'factor' => 1, 'can_sell' => true, 'can_buy' => true]);
        $carton = ProductPackUnit::create(['product_id' => $p->id, 'name' => 'Carton', 'factor' => 24, 'can_sell' => true, 'can_buy' => true,
            'sale_price' => 2300, 'barcode' => '2' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT)]);

        $this->receive([['product_id' => $p->id, 'buy_pack' => (string) $carton->id, 'price' => '2000', 'packs' => [(string) $carton->id => '2']]]);
        $this->assertEqualsWithDelta(48, $this->stock($p), 0.001, '2 Carton = 48 bottles');

        $this->assertTrue($this->sell([['product_id' => $p->id, 'quantity' => 3, 'unit_price' => 100]], 300)->isSuccessful());
        $this->assertEqualsWithDelta(45, $this->stock($p), 0.001, '3 loose bottles out');
        $loose = $this->lastSale()->lines()->firstOrFail();
        $this->assertNull($loose->base_quantity, 'a loose bottle is a plain line');

        $this->giveBack($loose->sales_order_id, $loose->id, 1, 100)->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(46, $this->stock($p), 0.001, 'one bottle back');

        // A whole carton, as a scan posts it: the pack and no price of its own → the carton's price, not 24 × 100.
        $whole = $this->sell([['product_id' => $p->id, 'quantity' => 1, 'pack_unit_id' => $carton->id]], 2300);
        $this->assertTrue($whole->isSuccessful(), Str::limit($whole->getContent(), 300));
        $cartonLine = $this->lastSale()->lines()->firstOrFail();
        $this->assertEqualsWithDelta(2300, (float) $cartonLine->unit_price, 0.001, 'the carton\'s own price');
        $this->assertEquals(24, (float) $cartonLine->base_quantity);
        $this->assertEqualsWithDelta(22, $this->stock($p), 0.001, 'a carton out = 24 bottles');
    }

    /* ── A06 ─────────────────────────────────────────────────────────────── */

    public function test_loose_rice_in_kg_received_sold_and_returned_in_parts_on_the_normal_pos(): void
    {
        $this->setFeatures(self::NORMAL_POS);
        $kg = Unit::updateOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $p = $this->product('Journey Loose Rice', $kg->id, ['default_selling_price' => 300]);

        $this->receive([['product_id' => $p->id, 'buy_pack' => 'base', 'price' => '280', 'packs' => ['base' => '25']]]);
        $this->assertEqualsWithDelta(25, $this->stock($p), 0.0001);

        $sale = $this->sell([['product_id' => $p->id, 'quantity' => 0.25, 'unit_price' => 300]], 75);
        $this->assertTrue($sale->isSuccessful(), 'sale refused: ' . Str::limit($sale->getContent(), 300));
        $order = $this->lastSale();
        $this->assertEqualsWithDelta(75, (float) $order->grand_total, 0.001, '0.250 kg at 300 a kg');
        $this->assertEqualsWithDelta(24.75, $this->stock($p), 0.0001);
        $line = $order->lines()->firstOrFail();

        $this->giveBack($order->id, $line->id, 0.1, 30)->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(24.85, $this->stock($p), 0.0001, '0.100 kg back');
        $this->assertEqualsWithDelta(0.1, (float) $line->fresh()->returned_quantity, 0.0001);
        $this->assertEqualsWithDelta(24.85, $this->ledgerSum($p), 0.0001, '+25 −0.250 +0.100');
    }

    /* ── UB-08 ───────────────────────────────────────────────────────────── */

    public function test_a_recipe_in_grams_takes_kilograms_with_no_conversion_row_and_the_pos_counts_the_same(): void
    {
        $this->setFeatures(self::NORMAL_POS);
        [$rice, $g] = $this->kgRiceWithStock('Journey Plate Rice', 10);
        $dish = $this->dish('Journey Rice Plate', $rice, 250, $g->id);
        $this->assertSame(0, DB::connection('tenant')->table('unit_conversions')->count(), 'no row typed by anyone');

        $this->assertSame(40, $this->makeable($dish), '10 kg ÷ 250 g = 40 plates');

        $sale = $this->sell([['product_id' => $dish->id, 'quantity' => 2, 'unit_price' => 200]], 400);
        $this->assertTrue($sale->isSuccessful(), 'a gram recipe on kilogram stock must sell: ' . Str::limit($sale->getContent(), 300));
        $this->assertEqualsWithDelta(9.5, $this->stock($rice), 0.0001, '2 × 250 g = 0.5 kg out');
        $this->assertSame(38, $this->makeable($dish));
    }

    public function test_a_unit_with_no_rule_still_refuses_the_sale_and_the_pos_says_none_can_be_made(): void
    {
        $this->setFeatures(self::NORMAL_POS);
        [$rice] = $this->kgRiceWithStock('Journey Packet Rice', 10);
        $pkt = Unit::updateOrCreate(['code' => 'PKT'], ['name' => 'Packet', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => false, 'is_active' => true]);
        $dish = $this->dish('Journey Packet Plate', $rice, 1, $pkt->id);

        $this->assertSame(0, $this->makeable($dish), 'no rule from a packet to a kg: none can be made, never "10"');

        $orders = SalesOrder::count();
        $this->assertFalse($this->sell([['product_id' => $dish->id, 'quantity' => 1, 'unit_price' => 200]], 200)->isSuccessful(),
            'the sale is refused, as before');
        $this->assertSame($orders, SalesOrder::count(), 'no order is left behind');
        $this->assertEqualsWithDelta(10, $this->stock($rice), 0.0001, 'nothing left the stock');
    }

    /**
     * Stock bought on the old GRN screen (or opening stock) carries no variant; the new GRN screen and the POS use the
     * default variant. They are the same goods: a sale takes from both, and each stock-book row says which row it left.
     */
    public function test_stock_with_no_variant_and_on_the_default_variant_is_one_stock_for_a_sale_and_a_recipe(): void
    {
        $this->setFeatures(self::NORMAL_POS);
        $kg = Unit::updateOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $sugar = $this->product('Journey Sugar', $kg->id, ['default_selling_price' => 160]);
        $c = DB::connection('tenant');

        app(InventoryService::class)->postIn(
            branch: \App\Models\Tenant\Branch::findOrFail($this->branchId()), product: $sugar, variant: null,
            quantity: 5, unitCost: 140, movementType: 'opening_stock', referenceType: 'test', notes: 'old screen / opening: no variant',
        );
        $this->receive([['product_id' => $sugar->id, 'buy_pack' => 'base', 'price' => '150', 'packs' => ['base' => '5']]]);
        $rows = fn () => $c->table('stock_balances')->where('product_id', $sugar->id)->orderBy('id')->pluck('quantity_on_hand', 'product_variant_id')->map(fn ($q) => (float) $q)->all();
        $this->assertSame(['' => 5.0, (string) $sugar->defaultVariant->id => 5.0], array_combine(array_map('strval', array_keys($rows())), array_values($rows())),
            'precondition: the same sugar sits on two rows, as on a tenant that used both screens');

        $sale = $this->sell([['product_id' => $sugar->id, 'quantity' => 7, 'unit_price' => 160]], 1120);
        $this->assertTrue($sale->isSuccessful(), '7 kg from 5 + 5 must sell: ' . Str::limit($sale->getContent(), 300));
        $this->assertEqualsWithDelta(3, $this->stock($sugar), 0.0001);
        $this->assertSame(0, $c->table('stock_balances')->where('product_id', $sugar->id)->where('quantity_on_hand', '<', 0)->count(), 'no row went below zero');
        $out = $c->table('stock_ledgers')->where('product_id', $sugar->id)->where('movement_type', 'sale')->get();
        $this->assertEqualsWithDelta(7, (float) $out->sum('quantity'), 0.0001);
        foreach ($out as $leg) {
            $row = $c->table('stock_balances')->where('product_id', $sugar->id)->where(fn ($q) => $leg->product_variant_id
                ? $q->where('product_variant_id', $leg->product_variant_id) : $q->whereNull('product_variant_id'))->first();
            $this->assertNotNull($row, 'every stock-book line names a row that exists');
        }

        // A recipe (no variant on its line) takes the rest the same way.
        $g = Unit::updateOrCreate(['code' => 'G'], ['name' => 'Gram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => false, 'is_active' => true]);
        $tea = $this->dish('Journey Sweet Tea', $sugar, 500, $g->id);
        $this->assertSame(6, $this->makeable($tea), '3 kg ÷ 500 g');
        $this->assertTrue($this->sell([['product_id' => $tea->id, 'quantity' => 2, 'unit_price' => 200]], 400)->isSuccessful());
        $this->assertEqualsWithDelta(2, $this->stock($sugar), 0.0001);
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    private function product(string $name, int $unitId, array $extra = []): Product
    {
        $sku = 'UJ-' . strtoupper(substr(md5($name), 0, 6));
        $p = Product::create($extra + ['sku' => $sku, 'name' => $name, 'slug' => Str::slug($name), 'unit_id' => $unitId, 'product_type' => 'simple',
            'product_kind' => 'sale_item', 'inventory_consumption_method' => 'stock_item', 'is_sellable' => true, 'is_pos_visible' => true,
            'is_purchasable' => true, 'is_stock_tracked' => true, 'has_expiry' => false, 'requires_batch' => false, 'default_selling_price' => 0,
            'status' => 'active']);
        ProductVariant::create(['product_id' => $p->id, 'sku' => $sku, 'name' => $name, 'is_default' => true, 'is_active' => true]);

        return $p->fresh();
    }

    /** @return array{0: Product, 1: Unit} rice kept in kg with $kg in stock, and the gram unit */
    private function kgRiceWithStock(string $name, float $kgInStock): array
    {
        $kg = Unit::updateOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $g = Unit::updateOrCreate(['code' => 'G'], ['name' => 'Gram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => false, 'is_active' => true]);
        $rice = $this->product($name, $kg->id, ['is_sellable' => false, 'is_pos_visible' => false, 'item_kind' => 'ingredient']);
        // Bought the way a restaurant buys it on the new GRN screen (that screen files it under the default variant).
        $this->receive([['product_id' => $rice->id, 'buy_pack' => 'base', 'price' => '280', 'packs' => ['base' => (string) $kgInStock]]]);

        return [$rice, $g];
    }

    private function dish(string $name, Product $ingredient, float $qty, int $unitId): Product
    {
        $dish = $this->product($name, (int) $ingredient->unit_id, ['inventory_consumption_method' => 'recipe', 'is_stock_tracked' => false,
            'is_purchasable' => false, 'default_selling_price' => 200]);
        $c = DB::connection('tenant');
        $recipeId = $c->table('recipes')->insertGetId(['product_id' => $dish->id, 'name' => $name, 'yield_quantity' => 1,
            'yield_unit_id' => $dish->unit_id, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $c->table('recipe_ingredients')->insert(['recipe_id' => $recipeId, 'product_id' => $ingredient->id, 'quantity' => $qty,
            'unit_id' => $unitId, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return $dish->fresh();
    }

    /** How many the POS screen says this branch can make, read from the page's own payload. */
    private function makeable(Product $dish): int
    {
        $html = $this->owner()->get($this->url('/pos?branch_id=' . $this->branchId()))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/const products\s*=\s*(\[.*?\]);\s*$/m', $html, $m), 'the POS page must embed its products');
        $row = collect(json_decode($m[1], true))->firstWhere('id', $dish->id);
        $this->assertNotNull($row, 'the dish must be on the POS');
        $this->assertTrue($row['is_recipe']);

        return (int) ($row['makeable_by_branch'][$this->branchId()] ?? -1);
    }

    private function receive(array $lines): void
    {
        $supplier = Supplier::firstOrCreate(['code' => 'UJ-S1'], ['name' => 'Journey Supplier', 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0]);
        $this->owner()->post($this->url('/goods-receipts'), ['_entry_v2' => '1', 'branch_id' => $this->branchId(), 'supplier_id' => $supplier->id,
            'receipt_date' => now()->toDateString(), 'lines' => $lines])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function lastGrnLine(Product $p)
    {
        return DB::connection('tenant')->table('goods_receipt_lines')->where('product_id', $p->id)->orderByDesc('id')->first();
    }

    private function sell(array $lines, float $total)
    {
        return $this->owner()->postJson($this->url('/pos'), [
            'branch_id' => $this->branchId(), 'terminal_id' => Terminal::where('code', 'C1')->firstOrFail()->id, 'order_type' => 'takeaway',
            'discount_type' => 'none', 'client_uuid' => (string) Str::uuid(), 'lines' => $lines,
            'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip',
            'payments' => [['payment_method_id' => (int) DB::connection('tenant')->table('payment_methods')->where('method_type', 'cash')->value('id'),
                'amount' => $total, 'tendered_amount' => ceil($total / 100) * 100]],
        ]);
    }

    private function giveBack(int $orderId, int $lineId, float $qty, float $refund)
    {
        return $this->owner()->post($this->url('/sales-returns'), [
            'sales_order_id' => $orderId, 'reason' => 'customer brought it back', 'refund_method' => 'cash', 'refund_amount' => $refund,
            'submission_key' => \App\Support\SubmissionKey::fresh(), 'lines' => [['sales_order_line_id' => $lineId, 'quantity' => $qty]],
        ]);
    }

    private function lastSale(): SalesOrder
    {
        return SalesOrder::where('status', 'paid')->latest('id')->firstOrFail();
    }

    private function stock(Product $p): float
    {
        return (float) DB::connection('tenant')->table('stock_balances')->where('product_id', $p->id)->sum('quantity_on_hand');
    }

    private function ledgerSum(Product $p): float
    {
        return (float) DB::connection('tenant')->table('stock_ledgers')->where('product_id', $p->id)->sum(DB::raw("CASE WHEN direction = 'in' THEN quantity ELSE -quantity END"));
    }

    private function branchId(): int
    {
        return (int) DB::connection('tenant')->table('branches')->orderBy('id')->value('id');
    }

    private function url(string $path): string
    {
        return 'http://' . $this->host . $path;
    }

    private function owner()
    {
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->where('email', 'owner@pharmacydemo.com')->firstOrFail(), 'tenant');
    }

    private function setFeatures(array $features): void
    {
        DB::connection('master')->table('tenants')->where('tenant_code', self::CODE)->update(['primary_business' => 'pharmacy', 'features' => json_encode($features)]);
        if (app()->bound('tenant')) {
            app('tenant')->features = $features;
        }
    }

    private function useStoreDb(): void
    {
        config(['database.connections.tenant.database' => $this->storeDb]);
        DB::purge('tenant');
    }

    private function createStoreDatabase(): void
    {
        if (stripos($this->storeDb, 'test') === false) {
            throw new \RuntimeException('the test database name must contain "test"');
        }
        $config = config('database.connections.tenant');
        $pdo = new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password'] ?? '');
        $pdo->exec('DROP DATABASE IF EXISTS `' . $this->storeDb . '`');
        $pdo->exec('CREATE DATABASE `' . $this->storeDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->useStoreDb();
        if (Artisan::call('migrate:fresh', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]) !== 0) {
            throw new \RuntimeException('migrations failed: ' . Artisan::output());
        }
    }

    private function seedMasterTenant(): void
    {
        $m = DB::connection('master');
        if ($old = $m->table('tenants')->where('tenant_code', self::CODE)->value('id')) {
            foreach (['tenant_domains', 'tenant_databases', 'subscriptions', 'tenant_setting_changes'] as $t) {
                $m->table($t)->where('tenant_id', $old)->delete();
            }
            $m->table('tenants')->where('id', $old)->delete();
        }
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenant_databases')->where('db_database', $this->storeDb)->delete();

        $id = $m->table('tenants')->insertGetId([
            'tenant_code' => self::CODE, 'business_name' => 'Units Journey Test', 'owner_name' => 'Demo Owner', 'owner_email' => 'owner@pharmacydemo.com',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0, 'primary_business' => 'pharmacy', 'features' => json_encode(self::COUNTER),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->insert([
            'tenant_id' => $id, 'db_connection' => 'tenant', 'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'), 'db_database' => $this->storeDb,
            'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert([
            'tenant_id' => $id, 'domain' => $this->host, 'is_primary' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('subscriptions')->insert([
            'tenant_id' => $id, 'plan_id' => $m->table('plans')->where('code', 'pharmacy_demo')->value('id'),
            'status' => 'active', 'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function buildStore(): void
    {
        $tenant = Tenant::on('master')->where('tenant_code', self::CODE)->firstOrFail();
        app()->instance('tenant', $tenant);
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        (new \ReflectionMethod(TenantProvisioner::class, 'seedTenantBaseData'))->invoke(app(TenantProvisioner::class), $tenant, 'demo1234');
        app(PharmacyDemoSeeder::class)->run();
    }
}
