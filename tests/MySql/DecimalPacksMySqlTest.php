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
 * UNITS-PACKS batch, feature 1 (23 Oct) — a kg, litre or metre item may come in packs that hold a part: Bag = 2.5 kg.
 *  - the product screen saves Bag = 2.5 kg and Sack = 10 Bag (= 25 kg), and refuses a size that is not more than 1, has
 *    a 4th decimal, or is not whole above the first pack; a counted item still takes whole packs only;
 *  - 4 Bags bought = 10 kg in; 1 Bag back to the supplier = 2.5 kg out; 1 Bag sold at the Bag's own price = 2.5 kg out;
 *    the customer brings it back = 2.5 kg in. Every line keeps the exact size (pack_size 2.5); none needs pack_factor.
 * Through the real routes on an isolated store; old lines are read as stored.
 */
class DecimalPacksMySqlTest extends MySqlTenantTestCase
{
    private const CODE = 'dptest';

    private const FEATURES = ['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true, 'medical_counter' => true];

    private static bool $built = false;

    private string $host;
    private string $storeDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = self::CODE . '.' . config('tenancy.tenant_base_domain');
        $this->storeDb = $this->tenantDb . '_dp';

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
        $this->setFeatures(self::FEATURES);
    }

    /* ── the product screen ─────────────────────────────────────────────── */

    public function test_a_kg_item_saves_a_bag_of_2_5_kg_and_a_sack_of_10_bags_and_wrong_sizes_are_refused(): void
    {
        $row = fn (string $name, string $contains) => ['name' => $name, 'contains' => $contains, 'price' => '', 'barcode' => '', 'sell' => '1', 'buy' => '1'];

        $this->owner()->post($this->url('/products'), $this->form('DP Basmati', $this->kg()->id, [$row('Bag', '2.5'), $row('Sack', '10')]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $rice = Product::where('name', 'DP Basmati')->firstOrFail();
        $sizes = $rice->packUnits()->orderBy('factor')->pluck('factor', 'name')->map(fn ($f) => (float) $f)->all();
        $this->assertSame(['Kilogram' => 1.0, 'Bag' => 2.5, 'Sack' => 25.0], $sizes, '1 Sack = 10 Bag = 25 kg');
        $edit = $this->owner()->get($this->url('/products/' . $rice->id . '/edit'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="packs\[0\]\[contains\]"[^>]*value="2\.5"/', $edit, 'the screen shows Bag holds 2.5');
        $this->assertMatchesRegularExpression('/name="packs\[1\]\[contains\]"[^>]*value="10"/', $edit, 'and Sack holds 10 Bag');

        $cases = [
            'not more than 1'      => [$this->kg()->id, [$row('Bag', '1')], 'packs.0.contains'],
            'a 4th decimal'        => [$this->kg()->id, [$row('Bag', '2.5555')], 'packs.0.contains'],
            'a part above the bag' => [$this->kg()->id, [$row('Bag', '2.5'), $row('Sack', '2.5')], 'packs.1.contains'],
            'a counted item'       => [$this->piece()->id, [$row('Box', '2.5')], 'packs.0.contains'],
        ];
        foreach ($cases as $what => [$unitId, $packs, $key]) {
            $this->owner()->post($this->url('/products'), $this->form('DP Refused ' . $what, $unitId, $packs))->assertRedirect()->assertSessionHasErrors($key);
            $this->assertFalse(Product::where('name', 'DP Refused ' . $what)->exists(), "{$what}: nothing is saved");
        }
    }

    /* ── bought, returned, sold and returned in Bags of 2.5 kg ───────────── */

    public function test_bags_of_2_5_kg_are_bought_returned_sold_and_come_back_in_kg(): void
    {
        $rice = $this->product('DP Sella Rice', $this->kg()->id, ['default_selling_price' => 300]);
        ProductPackUnit::create(['product_id' => $rice->id, 'name' => 'Kilogram', 'factor' => 1, 'can_sell' => true, 'can_buy' => true]);
        $bag = ProductPackUnit::create(['product_id' => $rice->id, 'name' => 'Bag', 'factor' => 2.5, 'sale_price' => 700, 'can_sell' => true, 'can_buy' => true,
            'barcode' => '2' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT)]);
        $c = DB::connection('tenant');

        // Bought: 4 Bags at 650 a Bag = 10 kg at 260 a kg.
        $this->owner()->post($this->url('/goods-receipts'), ['_entry_v2' => '1', 'branch_id' => $this->branchId(), 'supplier_id' => $this->supplier()->id,
            'receipt_date' => now()->toDateString(), 'lines' => [['product_id' => $rice->id, 'buy_pack' => (string) $bag->id, 'price' => '650', 'packs' => [(string) $bag->id => '4']]]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $grnLine = $c->table('goods_receipt_lines')->where('product_id', $rice->id)->orderByDesc('id')->first();
        $this->assertSame('10.000', (string) $grnLine->quantity_received);
        $this->assertSame('2.5000', (string) $grnLine->pack_size, 'the line keeps the Bag\'s exact size');
        $this->assertNull($grnLine->pack_factor, 'the whole-number column is left empty for 2.5');
        $this->assertEqualsWithDelta(260.0, (float) $grnLine->unit_cost, 0.0001, 'cost per kg');
        $this->assertEqualsWithDelta(10.0, $this->stock($rice), 0.0001);

        // Back to the supplier: 1 Bag = 2.5 kg.
        $ret = $this->owner()->call('POST', $this->url('/purchase-returns'), ['_entry_v2' => '1', '_post' => '1', 'branch_id' => $this->branchId(),
            'supplier_id' => $this->supplier()->id, 'return_date' => now()->toDateString(),
            'lines' => [['product_id' => $rice->id, 'buy_pack' => (string) $bag->id, 'price' => '650', 'reason_code' => 'damaged', 'packs' => [(string) $bag->id => '1']]]],
            [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $ret->assertOk();
        $this->assertTrue($ret->json('posted'), Str::limit($ret->getContent(), 300));
        $retLine = $c->table('purchase_return_lines')->where('purchase_return_id', $ret->json('id'))->first();
        $this->assertSame('2.500', (string) $retLine->quantity);
        $this->assertSame('2.5000', (string) $retLine->pack_size);
        $this->assertEqualsWithDelta(7.5, $this->stock($rice), 0.0001);

        // Sold at the counter: 1 Bag at the Bag's own price (700, not 2.5 × 300), 2.5 kg out.
        $sale = $this->sell([['product_id' => $rice->id, 'quantity' => 1, 'pack_unit_id' => $bag->id]], 700);
        $this->assertTrue($sale->isSuccessful(), Str::limit($sale->getContent(), 300));
        $line = $this->lastSale()->lines()->firstOrFail();
        $this->assertEqualsWithDelta(700.0, (float) $line->unit_price, 0.001);
        $this->assertSame('2.500', (string) $line->base_quantity);
        $this->assertSame('2.5000', (string) $line->pack_size);
        $this->assertNull($line->pack_factor);
        $this->assertSame('Bag', $line->unit_code);
        $this->assertEqualsWithDelta(5.0, $this->stock($rice), 0.0001, '7.5 kg − one 2.5 kg Bag');

        // The customer brings the Bag back: 2.5 kg back on the shelf.
        $this->giveBack($line->sales_order_id, $line->id, 1, 700)->assertSessionHasNoErrors();
        $back = $c->table('sales_return_lines')->where('sales_order_line_id', $line->id)->first();
        $this->assertSame('2.5000', (string) $back->pack_size);
        $this->assertSame('2.500', (string) $back->base_quantity);
        $this->assertEqualsWithDelta(7.5, $this->stock($rice), 0.0001);

        // The stock, said in packs: 7.5 kg = 3 Bag.
        $this->assertSame('3 Bag', \App\Support\PackUnits::describe($rice->packUnits()->get(), 7.5, 'Kilogram'));
        $this->assertSame('2 Bag 1 Kilogram', \App\Support\PackUnits::describe($rice->packUnits()->get(), 6.0, 'Kilogram'));
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    /** A product-screen form; $packs answers "Yes, it comes in packs". */
    private function form(string $name, int $unitId, array $packs): array
    {
        return ['_form_v2' => '1', '_card' => 'medicine', 'status' => 'active', 'sku' => '', 'category_id' => '', '_tax' => '0',
            'unit_id' => (string) $unitId, 'name' => $name, 'default_selling_price' => '300', '_buy' => '1', '_barcode' => '0',
            '_packs' => '1', 'pack_base_sell' => '1', 'packs' => $packs];
    }

    private function kg(): Unit
    {
        return Unit::updateOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
    }

    private function piece(): Unit
    {
        return Unit::updateOrCreate(['code' => 'PC'], ['name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
    }

    private function product(string $name, int $unitId, array $extra = []): Product
    {
        $sku = 'DP-' . strtoupper(substr(md5($name), 0, 6));
        $p = Product::create($extra + ['sku' => $sku, 'name' => $name, 'slug' => Str::slug($name), 'unit_id' => $unitId, 'product_type' => 'simple',
            'product_kind' => 'sale_item', 'inventory_consumption_method' => 'stock_item', 'is_sellable' => true, 'is_pos_visible' => true,
            'is_purchasable' => true, 'is_stock_tracked' => true, 'has_expiry' => false, 'requires_batch' => false, 'default_selling_price' => 0,
            'status' => 'active']);
        ProductVariant::create(['product_id' => $p->id, 'sku' => $sku, 'name' => $name, 'is_default' => true, 'is_active' => true]);

        return $p->fresh();
    }

    private function supplier(): Supplier
    {
        return Supplier::firstOrCreate(['code' => 'DP-S1'], ['name' => 'DP Supplier', 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0]);
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
        return (float) DB::connection('tenant')->table('stock_balances')->where('branch_id', $this->branchId())->where('product_id', $p->id)->sum('quantity_on_hand');
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
            'tenant_code' => self::CODE, 'business_name' => 'Decimal Packs Test', 'owner_name' => 'Demo Owner', 'owner_email' => 'owner@pharmacydemo.com',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0, 'primary_business' => 'pharmacy', 'features' => json_encode(self::FEATURES),
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
