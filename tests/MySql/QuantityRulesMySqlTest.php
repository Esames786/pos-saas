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
 * W2-B (22 Oct) — one set of quantity rules on the server, for every new sale, hold, split and return line:
 *  - a counted item (bottle, piece, no unit) is a whole number; weight / volume / length take parts;
 *  - a quantity is made exact once (3 decimals) before price, stock or a return reads it — a real fourth decimal is
 *    refused, browser float noise is cleaned;
 *  - a return never asks for more than is left without being told how much is left, in the line's own unit; packs
 *    come back whole; a counted line split in the past can still be closed with everything left.
 * Through the real routes on an isolated store. Old lines are read as stored.
 */
class QuantityRulesMySqlTest extends MySqlTenantTestCase
{
    private const CODE = 'qtytest';

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
        $this->storeDb = $this->tenantDb . '_qtr';

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

    /* ── counted items: whole numbers on sale, hold and split ───────────── */

    public function test_a_counted_item_sells_holds_and_splits_only_in_whole_numbers(): void
    {
        $this->setFeatures(self::NORMAL_POS);
        $btl = Unit::updateOrCreate(['code' => 'BTL'], ['name' => 'Bottle', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $cola = $this->product('Rules Cola', $btl->id, ['default_selling_price' => 100]);
        $this->receive([['product_id' => $cola->id, 'buy_pack' => 'base', 'price' => '80', 'packs' => ['base' => '10']]]);
        $orders = SalesOrder::count();

        $sale = $this->sell([['product_id' => $cola->id, 'quantity' => 1.5, 'unit_price' => 100]], 150);
        $this->assertSame(422, $sale->getStatusCode());
        $this->assertSame('Rules Cola is counted in whole numbers (Bottle) — 1.5 is not a whole number.', $sale->json('message'));
        $this->assertSame($orders, SalesOrder::count(), 'nothing saved');
        $this->assertEqualsWithDelta(10, $this->stock($cola), 0.0001, 'nothing left the shelf');

        $held = $this->owner()->postJson($this->url('/held-sales'), $this->holdPayload([['product_id' => $cola->id, 'quantity' => 2.5, 'unit_price' => 100]]));
        $this->assertSame(422, $held->getStatusCode(), 'a held line may not hold what the sale would refuse');
        $this->assertStringContainsString('2.5 is not a whole number', (string) $held->json('message'));
        $this->assertSame($orders, SalesOrder::count());

        $this->assertTrue($this->sell([['product_id' => $cola->id, 'quantity' => 2, 'unit_price' => 100]], 200)->isSuccessful(), 'whole numbers sell as before');
        $this->assertEqualsWithDelta(8, $this->stock($cola), 0.0001);

        $ok = $this->owner()->postJson($this->url('/held-sales'), $this->holdPayload([['product_id' => $cola->id, 'quantity' => 3, 'unit_price' => 100]]));
        $this->assertTrue($ok->isSuccessful(), Str::limit($ok->getContent(), 300));
        $heldOrder = SalesOrder::where('status', 'held')->latest('id')->firstOrFail();
        $heldLine = $heldOrder->lines()->firstOrFail();
        $split = $this->owner()->post($this->url('/sales-orders/' . $heldOrder->id . '/split-bill'), ['lines' => [['sales_order_line_id' => $heldLine->id, 'quantity' => 1.5]]]);
        $split->assertSessionHasErrors('split');
        $this->assertStringContainsString('1.5 is not a whole number', session('errors')->first('split'));
        $this->assertSame(1, SalesOrder::where('status', 'held')->count(), 'no split order was made');
        $this->assertEquals(3, (float) $heldLine->fresh()->quantity, 'the held line is untouched');

        $this->owner()->post($this->url('/sales-orders/' . $heldOrder->id . '/split-bill'), ['lines' => [['sales_order_line_id' => $heldLine->id, 'quantity' => 1]]])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, SalesOrder::where('status', 'held')->count(), 'a whole split still works');
    }

    /* ── precision: exact once, before price and stock ──────────────────── */

    public function test_weight_takes_parts_never_more_than_three_decimals_and_float_noise_is_cleaned_once(): void
    {
        $this->setFeatures(self::NORMAL_POS);
        $kg = Unit::updateOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $rice = $this->product('Rules Rice', $kg->id, ['default_selling_price' => 300]);
        $this->receive([['product_id' => $rice->id, 'buy_pack' => 'base', 'price' => '280', 'packs' => ['base' => '25']]]);
        $orders = SalesOrder::count();

        $four = $this->sell([['product_id' => $rice->id, 'quantity' => 1.2345, 'unit_price' => 300]], 370.35);
        $this->assertSame(422, $four->getStatusCode(), 'a fourth decimal is refused, never rounded silently');
        $this->assertStringContainsString('at most 3 decimal places', (string) $four->json('message'));
        $this->assertSame($orders, SalesOrder::count());
        $this->assertEqualsWithDelta(25, $this->stock($rice), 0.0001);

        $sale = $this->sell([['product_id' => $rice->id, 'quantity' => 1.25, 'unit_price' => 300]], 375);
        $this->assertTrue($sale->isSuccessful(), Str::limit($sale->getContent(), 300));
        $this->assertEqualsWithDelta(23.75, $this->stock($rice), 0.0001);

        // 0.1 + 0.2 typed into the browser arrives as 0.30000000000000004: stored, priced and taken as 0.300.
        $noise = $this->sell([['product_id' => $rice->id, 'quantity' => 0.1 + 0.2, 'unit_price' => 300]], 90);
        $this->assertTrue($noise->isSuccessful(), Str::limit($noise->getContent(), 300));
        $line = $this->lastSale()->lines()->firstOrFail();
        $this->assertSame('0.300', (string) $line->getRawOriginal('quantity'));
        $this->assertEqualsWithDelta(90, (float) $line->line_total, 0.00001, 'the line total uses the stored quantity');
        $this->assertEqualsWithDelta(round((float) $line->quantity * (float) $line->unit_price, 2), (float) $line->line_total, 0.00001,
            'quantity × price = line total, to the paisa');
        $this->assertEqualsWithDelta(23.45, $this->stock($rice), 0.0001, 'stock moved by the same 0.300');

        // A part of a kg comes back in parts.
        $kgSale = SalesOrder::where('status', 'paid')->orderByDesc('id')->skip(1)->firstOrFail();
        $kgLine = $kgSale->lines()->firstOrFail();
        $this->giveBack($kgSale->id, $kgLine->id, 0.25, 75)->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(23.7, $this->stock($rice), 0.0001);
    }

    /* ── returns: what is left, in its unit; whole packs ────────────────── */

    public function test_a_return_says_what_is_left_in_its_unit_and_packs_come_back_whole(): void
    {
        $tab = Unit::updateOrCreate(['code' => 'TAB'], ['name' => 'Tablet', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $med = $this->product('Rules Paracetamol', $tab->id, ['has_expiry' => true, 'requires_batch' => true, 'default_selling_price' => 10]);
        ProductPackUnit::create(['product_id' => $med->id, 'name' => 'Tablet', 'factor' => 1, 'can_sell' => true, 'can_buy' => true]);
        $strip = ProductPackUnit::create(['product_id' => $med->id, 'name' => 'Strip', 'factor' => 10, 'can_sell' => true, 'can_buy' => true]);
        $this->receive([['product_id' => $med->id, 'batch_no' => 'qr-1', 'expiry' => now('Asia/Karachi')->addYear()->format('m/y'),
            'buy_pack' => (string) $strip->id, 'price' => '90', 'packs' => [(string) $strip->id => '5']]]);
        $this->assertTrue($this->sell([['product_id' => $med->id, 'quantity' => 3, 'unit_price' => 95, 'pack_unit_id' => $strip->id]], 285)->isSuccessful());
        $line = $this->lastSale()->lines()->firstOrFail();
        $stock = fn () => $this->stock($med);
        $after = $stock();

        $refuse = function (float $qty, string $says) use ($line, $stock, $after) {
            $this->giveBack($line->sales_order_id, $line->id, $qty, round($qty * 95, 2))->assertSessionHasErrors();
            $this->assertStringContainsString($says, collect(session('errors')->all())->implode(' '));
            $this->assertEqualsWithDelta($after, $stock(), 0.0001, 'a refused return moves nothing');
        };
        $refuse(0.5, 'Strip comes back whole — 0.5 is not a whole number (or return all 3 still left).');
        $refuse(5, 'only 3 Strip can still be returned, not 5.');

        $this->giveBack($line->sales_order_id, $line->id, 2, 190)->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta($after + 20, $stock(), 0.0001);
        $this->giveBack($line->sales_order_id, $line->id, 2, 190)->assertSessionHasErrors();
        $this->assertStringContainsString('only 1 Strip can still be returned, not 2.', collect(session('errors')->all())->implode(' '));
        $this->giveBack($line->sales_order_id, $line->id, 1, 95)->assertSessionHasNoErrors();
        // A bill with nothing left is not offered for a return at all (the screen answers 404); the service's own
        // "nothing is left to return" is proven in SalesReturnPostedOnceMySqlTest.
        $this->assertSame(404, $this->giveBack($line->sales_order_id, $line->id, 1, 95)->getStatusCode());
        $this->assertEqualsWithDelta($after + 30, $stock(), 0.0001, 'all 3 strips back, and nothing more');
        $this->assertEquals(3, (float) $line->fresh()->returned_quantity);
    }

    /**
     * Khatri, 19 Aug: a line of 1 had 0.001 returned, then 0.999. A counted line split like that in the past must still
     * be closable — "everything still left" is always a valid return — while a new part (0.5) is refused.
     */
    public function test_a_counted_line_split_in_the_past_can_still_be_closed(): void
    {
        $this->setFeatures(self::NORMAL_POS);
        $btl = Unit::updateOrCreate(['code' => 'BTL'], ['name' => 'Bottle', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $drink = $this->product('Rules Coldrink', $btl->id, ['default_selling_price' => 160]);
        $this->receive([['product_id' => $drink->id, 'buy_pack' => 'base', 'price' => '120', 'packs' => ['base' => '5']]]);
        $this->assertTrue($this->sell([['product_id' => $drink->id, 'quantity' => 1, 'unit_price' => 160]], 160)->isSuccessful());
        $line = $this->lastSale()->lines()->firstOrFail();
        DB::connection('tenant')->table('sales_order_lines')->where('id', $line->id)->update(['returned_quantity' => 0.001]);   // the old sliver

        $this->giveBack($line->sales_order_id, $line->id, 0.5, 80)->assertSessionHasErrors();
        $this->assertStringContainsString('or return all 0.999 still left', collect(session('errors')->all())->implode(' '));
        $this->giveBack($line->sales_order_id, $line->id, 0.999, 159.84)->assertSessionHasNoErrors();
        $this->assertEquals(1, (float) $line->fresh()->returned_quantity, 'the line is closed');
    }

    private function holdPayload(array $lines): array
    {
        return ['branch_id' => $this->branchId(), 'terminal_id' => Terminal::where('code', 'C1')->firstOrFail()->id, 'order_type' => 'takeaway',
            'discount_type' => 'none', 'lines' => $lines, 'save_as_draft' => '0'];
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
