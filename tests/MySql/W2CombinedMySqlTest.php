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
 * W2 INTEGRATION (22 Oct) — the candidate's three parts working TOGETHER, through the real routes on an isolated store:
 * standard-unit conversion (kg ↔ g), one plain stock for no-variant and default-variant rows, and the server's
 * quantity / return rules. Separate passing branches do not prove the combination; these do:
 *  1. a gram recipe sells whole plates from kg stock split across both kinds of row;
 *  2. loose kg across both rows keeps 3 decimals, and a return says what is left in its unit;
 *  3. strips sell from tablets on two batches and two rows by expiry, and come back whole;
 *  4. a tenant configured like the live ones (every new switch off) posts new lines while old lines and old stock-book
 *     rows stay byte-identical, and an old split line can still be closed.
 */
class W2CombinedMySqlTest extends MySqlTenantTestCase
{
    private const CODE = 'w2comb';

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
        $this->storeDb = $this->tenantDb . '_w2c';

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

    /* ── 1. gram recipe + kg stock on two kinds of row + whole plates ───── */

    public function test_a_gram_recipe_sells_whole_plates_from_kg_stock_on_both_kinds_of_row(): void
    {
        $this->setFeatures(self::NORMAL_POS);
        $kg = Unit::updateOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $g = Unit::updateOrCreate(['code' => 'G'], ['name' => 'Gram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => false, 'is_active' => true]);
        $plate = Unit::updateOrCreate(['code' => 'PLT'], ['name' => 'Plate', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $rice = $this->product('Comb Rice', $kg->id, ['is_sellable' => false, 'is_pos_visible' => false, 'item_kind' => 'ingredient']);
        $this->openingStock($rice, 5, 260);                                     // old screen / opening: no variant
        $this->receive([['product_id' => $rice->id, 'buy_pack' => 'base', 'price' => '280', 'packs' => ['base' => '5']]]);   // new screen: default variant
        $dish = $this->plateDish('Comb Rice Plate', $plate->id, $rice, 250, $g->id);
        $this->assertSame(0, DB::connection('tenant')->table('unit_conversions')->count());

        $this->assertSame(40, $this->makeable($dish), '5 + 5 kg ÷ 250 g, read across both rows');

        $half = $this->sell([['product_id' => $dish->id, 'quantity' => 1.5, 'unit_price' => 200]], 300);
        $this->assertSame(422, $half->getStatusCode(), 'a plate is counted');
        $this->assertStringContainsString('1.5 is not a whole number', (string) $half->json('message'));
        $this->assertEqualsWithDelta(10, $this->stock($rice), 0.0001);

        $sale = $this->sell([['product_id' => $dish->id, 'quantity' => 30, 'unit_price' => 200]], 6000);
        $this->assertTrue($sale->isSuccessful(), Str::limit($sale->getContent(), 300));
        $this->assertEqualsWithDelta(2.5, $this->stock($rice), 0.0001, '30 × 250 g = 7.5 kg, from both rows');
        $this->assertNoRowBelowZero($rice);
        $this->assertRowsMatchTheirBook($rice);
        $this->assertSame(10, $this->makeable($dish));
    }

    /* ── 2. loose kg on two kinds of row + precision + returns ──────────── */

    public function test_loose_kg_across_both_rows_keeps_three_decimals_and_returns_say_what_is_left(): void
    {
        $this->setFeatures(self::NORMAL_POS);
        $kg = Unit::updateOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $sugar = $this->product('Comb Sugar', $kg->id, ['default_selling_price' => 160]);
        $this->openingStock($sugar, 5, 140);
        $this->receive([['product_id' => $sugar->id, 'buy_pack' => 'base', 'price' => '150', 'packs' => ['base' => '5']]]);

        $four = $this->sell([['product_id' => $sugar->id, 'quantity' => 7.2505, 'unit_price' => 160]], 1160.08);
        $this->assertSame(422, $four->getStatusCode());
        $this->assertEqualsWithDelta(10, $this->stock($sugar), 0.0001, 'a refused fourth decimal moves nothing');

        $sale = $this->sell([['product_id' => $sugar->id, 'quantity' => 7.25, 'unit_price' => 160]], 1160);
        $this->assertTrue($sale->isSuccessful(), Str::limit($sale->getContent(), 300));
        $line = $this->lastSale()->lines()->firstOrFail();
        $this->assertEqualsWithDelta(1160, (float) $line->line_total, 0.001);
        $this->assertEqualsWithDelta(2.75, $this->stock($sugar), 0.0001, '7.250 kg out of 5 + 5');
        $this->assertNoRowBelowZero($sugar);

        $this->giveBack($line->sales_order_id, $line->id, 0.25, 40)->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(3.0, $this->stock($sugar), 0.0001, 'a part of a kg comes back');
        $this->giveBack($line->sales_order_id, $line->id, 8, 1280)->assertSessionHasErrors();
        $this->assertStringContainsString('only 7 Kilogram can still be returned, not 8.', collect(session('errors')->all())->implode(' '));
        $this->assertEqualsWithDelta(3.0, $this->stock($sugar), 0.0001);
        $this->assertRowsMatchTheirBook($sugar);
    }

    /* ── 3. strips from tablets on two batches and two kinds of row ─────── */

    public function test_strips_sell_from_tablets_on_both_rows_by_expiry_and_come_back_whole(): void
    {
        $tabU = Unit::updateOrCreate(['code' => 'TAB'], ['name' => 'Tablet', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $med = $this->product('Comb Paracetamol', $tabU->id, ['has_expiry' => true, 'requires_batch' => true, 'default_selling_price' => 10]);
        ProductPackUnit::create(['product_id' => $med->id, 'name' => 'Tablet', 'factor' => 1, 'can_sell' => true, 'can_buy' => true]);
        $strip = ProductPackUnit::create(['product_id' => $med->id, 'name' => 'Strip', 'factor' => 10, 'can_sell' => true, 'can_buy' => true]);
        $this->openingStock($med, 30, 8, 'CB-SOON', now()->addMonths(2)->endOfMonth()->toDateString());   // no variant, sooner
        $this->receive([['product_id' => $med->id, 'batch_no' => 'cb-late', 'expiry' => now('Asia/Karachi')->addYear()->format('m/y'),
            'buy_pack' => (string) $strip->id, 'price' => '90', 'packs' => [(string) $strip->id => '5']]]);                 // default variant, later

        $sale = $this->sell([['product_id' => $med->id, 'quantity' => 6, 'unit_price' => 95, 'pack_unit_id' => $strip->id]], 570);
        $this->assertTrue($sale->isSuccessful(), Str::limit($sale->getContent(), 300));
        $this->assertSame(['CB-LATE' => 30.0, 'CB-SOON' => 30.0], $this->takenByBatch($med), 'the sooner batch first, then the later — across both rows');
        $this->assertEqualsWithDelta(20, $this->stock($med), 0.0001);
        $line = $this->lastSale()->lines()->firstOrFail();
        $this->assertEquals(60, (float) $line->base_quantity);

        $this->giveBack($line->sales_order_id, $line->id, 0.5, 47.5)->assertSessionHasErrors();
        $this->assertStringContainsString('Strip comes back whole', collect(session('errors')->all())->implode(' '));
        $this->giveBack($line->sales_order_id, $line->id, 1, 95)->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(30, $this->stock($med), 0.0001, 'one strip = 10 tablets back');
        $this->assertNoRowBelowZero($med);
    }

    /* ── 4. a tenant set up like the live ones: new postings, history untouched ── */

    public function test_a_tenant_configured_like_the_live_ones_posts_new_lines_and_leaves_history_alone(): void
    {
        $this->setFeatures([]);   // every new switch off, as on the live tenants
        $c = DB::connection('tenant');
        $ea = Unit::updateOrCreate(['code' => 'EA'], ['name' => 'Each', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $kg = Unit::updateOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $drink = $this->product('Comb Coldrink', $ea->id, ['default_selling_price' => 160]);
        $meat = $this->product('Comb Meat', $kg->id, ['default_selling_price' => 1200]);
        $this->openingStock($drink, 20, 120);
        $this->openingStock($meat, 10, 900);

        // History, as live tenants have it: a line of 1 that once had 0.001 returned (19 Aug) and an old 0.999 sale.
        $this->assertTrue($this->sell([['product_id' => $drink->id, 'quantity' => 1, 'unit_price' => 160]], 160)->isSuccessful());
        $old = $this->lastSale()->lines()->firstOrFail();
        $c->table('sales_order_lines')->where('id', $old->id)->update(['returned_quantity' => 0.001]);
        $this->assertTrue($this->sell([['product_id' => $drink->id, 'quantity' => 2, 'unit_price' => 160]], 320)->isSuccessful());
        $older = $this->lastSale()->lines()->firstOrFail();
        $c->table('sales_order_lines')->where('id', $older->id)->update(['quantity' => 0.999]);
        $historyIds = $c->table('sales_order_lines')->pluck('id')->all();
        $history = fn () => $c->table('sales_order_lines')->whereIn('id', $historyIds)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $ledgerIds = $c->table('stock_ledgers')->pluck('id')->all();
        $book = fn () => $c->table('stock_ledgers')->whereIn('id', $ledgerIds)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        [$historyBefore, $bookBefore] = [$history(), $book()];

        // New postings under the new rules.
        $this->assertSame(422, $this->sell([['product_id' => $drink->id, 'quantity' => 1.5, 'unit_price' => 160]], 240)->getStatusCode());
        $this->assertTrue($this->sell([['product_id' => $drink->id, 'quantity' => 3, 'unit_price' => 160]], 480)->isSuccessful());
        $this->assertTrue($this->sell([['product_id' => $meat->id, 'quantity' => 1.25, 'unit_price' => 1200]], 1500)->isSuccessful());

        $this->assertSame($historyBefore, $history(), 'old sale lines are byte-identical after new postings');
        $this->assertSame($bookBefore, $book(), 'old stock-book lines are byte-identical');

        // The old split line can still be closed; a new part of it cannot be returned.
        $this->giveBack($old->sales_order_id, $old->id, 0.5, 80)->assertSessionHasErrors();
        $this->giveBack($old->sales_order_id, $old->id, 0.999, 159.84)->assertSessionHasNoErrors();
        $this->assertEquals(1, (float) $old->fresh()->returned_quantity);
    }

    /* ── helpers for this file ──────────────────────────────────────────── */

    /** A dish sold by the plate (a counted unit), one plate = $qty of the ingredient in $unitId. */
    private function plateDish(string $name, int $plateUnit, Product $ingredient, float $qty, int $unitId): Product
    {
        $dish = $this->product($name, $plateUnit, ['inventory_consumption_method' => 'recipe', 'is_stock_tracked' => false,
            'is_purchasable' => false, 'default_selling_price' => 200]);
        $c = DB::connection('tenant');
        $recipeId = $c->table('recipes')->insertGetId(['product_id' => $dish->id, 'name' => $name, 'yield_quantity' => 1,
            'yield_unit_id' => $plateUnit, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $c->table('recipe_ingredients')->insert(['recipe_id' => $recipeId, 'product_id' => $ingredient->id, 'quantity' => $qty,
            'unit_id' => $unitId, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return $dish->fresh();
    }

    private function openingStock(Product $p, float $qty, float $cost, ?string $batch = null, ?string $expiry = null): void
    {
        app(InventoryService::class)->postIn(branch: \App\Models\Tenant\Branch::findOrFail($this->branchId()), product: $p, variant: null,
            quantity: $qty, unitCost: $cost, movementType: 'opening_stock', referenceType: 'test', batchNo: $batch, expiryDate: $expiry);
    }

    private function assertNoRowBelowZero(Product $p): void
    {
        $this->assertSame(0, DB::connection('tenant')->table('stock_balances')->where('product_id', $p->id)->where('quantity_on_hand', '<', 0)->count(),
            'no stock row below zero');
    }

    /** Each balance row equals the stock-book lines written against it (branch + variant + batch). */
    private function assertRowsMatchTheirBook(Product $p): void
    {
        $c = DB::connection('tenant');
        foreach ($c->table('stock_balances')->where('product_id', $p->id)->get() as $row) {
            $book = (float) $c->table('stock_ledgers')->where('product_id', $p->id)->where('branch_id', $row->branch_id)
                ->where(fn ($q) => $row->product_variant_id ? $q->where('product_variant_id', $row->product_variant_id) : $q->whereNull('product_variant_id'))
                ->where(fn ($q) => $row->inventory_batch_id ? $q->where('inventory_batch_id', $row->inventory_batch_id) : $q->whereNull('inventory_batch_id'))
                ->sum(DB::raw("CASE WHEN direction = 'in' THEN quantity ELSE -quantity END"));
            $this->assertEqualsWithDelta((float) $row->quantity_on_hand, $book, 0.0001, 'a row and its stock-book lines agree');
        }
    }

    /** @return array<string, float> batch number => tablets taken by sales */
    private function takenByBatch(Product $p): array
    {
        $rows = DB::connection('tenant')->table('stock_ledgers as l')->join('inventory_batches as b', 'b.id', '=', 'l.inventory_batch_id')
            ->where('l.product_id', $p->id)->where('l.movement_type', 'sale')->groupBy('b.batch_no')->selectRaw('b.batch_no, SUM(l.quantity) q')->orderBy('b.batch_no')->get();

        return $rows->mapWithKeys(fn ($r) => [$r->batch_no => (float) $r->q])->all();
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
