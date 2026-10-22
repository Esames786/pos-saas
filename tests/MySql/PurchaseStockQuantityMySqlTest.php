<?php

namespace Tests\MySql;

use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\Supplier;
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
 * W2-C (23 Oct) — the counter's quantity rules on purchasing and stock, through the real routes:
 *  - every typed quantity is exact to 3 decimals once: 2.5 kg and 0.125 kg are taken, a real 4th decimal is refused
 *    and nothing is written (PO, GRN old and new screen, stock adjustment, transfer, count);
 *  - a counted item is whole only where the business switched that on; with the switch off a part is still taken;
 *  - a supplier return says, when the draft is saved, how much of the receipt can still go back;
 *  - the stock book's cost is the stored quantity × unit cost, a derived 4-decimal quantity included, and the old
 *    PO's total is the sum of its lines.
 */
class PurchaseStockQuantityMySqlTest extends MySqlTenantTestCase
{
    private const CODE = 'psqtest';

    private const BASE = ['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true];

    private static bool $built = false;

    private string $host;
    private string $storeDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = self::CODE . '.' . config('tenancy.tenant_base_domain');
        $this->storeDb = $this->tenantDb . '_psq';

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
        $this->setFeatures(self::BASE);
    }

    /* ── 3 decimals, everywhere ──────────────────────────────────────────── */

    public function test_kg_takes_three_decimals_and_a_fourth_is_refused_with_nothing_written_on_every_screen(): void
    {
        $rice = $this->kgItem('W2C Rice');
        $c = DB::connection('tenant');
        $before = $this->counts();

        // Refused, with the item's name and nothing written.
        $this->grnOld($rice, '0.1255')->assertSessionHasErrors(['lines.0.quantity_received' => 'W2C Rice: a quantity can have at most 3 decimal places — 0.1255 has more.']);
        $this->grnV2($rice, ['base' => '0.1255'])->assertSessionHasErrors('lines.0.packs');
        $this->poOld($rice, '1.2345')->assertSessionHasErrors('lines.0.quantity_ordered');
        $this->owner()->post($this->url('/purchase-returns'), ['_entry_v2' => '1', 'branch_id' => $this->branchId(), 'supplier_id' => $this->supplier()->id,
            'return_date' => now()->toDateString(), 'reason_code' => 'damaged',
            'lines' => [['product_id' => $rice->id, 'buy_pack' => 'base', 'price' => '50', 'packs' => ['base' => '0.1255']]]])
            ->assertSessionHasErrors(['lines.0.packs' => 'W2C Rice: a quantity can have at most 3 decimal places — 0.1255 has more.']);
        $this->adjust($rice, '0.1255')->assertSessionHasErrors('lines.0.quantity');
        $this->assertSame($before, $this->counts(), 'a refused quantity writes nothing');

        // Taken.
        $this->grnOld($rice, '2.5')->assertSessionHasNoErrors();
        $this->assertSame('2.500', (string) $this->lastGrnLine($rice)->quantity_received);
        $this->grnV2($rice, ['base' => '0.125'])->assertSessionHasNoErrors();
        $this->assertSame('0.125', (string) $this->lastGrnLine($rice)->quantity_received);
        $this->adjust($rice, '1.25')->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(3.875, $this->stock($rice), 0.0001);

        // The old PO's total is the sum of its lines, from the exact quantity (float noise 0.1 + 0.2 cleaned once).
        $this->poOld($rice, (string) (0.1 + 0.2), '280')->assertSessionHasNoErrors();
        $po = $c->table('purchase_orders')->orderByDesc('id')->first();
        $this->assertSame('0.300', (string) $c->table('purchase_order_lines')->where('purchase_order_id', $po->id)->value('quantity_ordered'));
        $this->assertEqualsWithDelta(84.0, (float) $po->total_amount, 0.0001);

        // A count line: refused, not rounded silently.
        [$session, $line] = $this->countLine($rice);
        $this->owner()->patch($this->url("/stock-counts/{$session}/lines/{$line}"), ['counted_quantity' => '3.8755'])
            ->assertSessionHasErrors(['counted_quantity' => 'W2C Rice: a quantity can have at most 3 decimal places — 3.8755 has more.']);
        $this->assertNull($c->table('stock_count_lines')->where('id', $line)->value('counted_quantity'));
        $this->owner()->patch($this->url("/stock-counts/{$session}/lines/{$line}"), ['counted_quantity' => '3.875'])->assertSessionHasNoErrors();
        $this->assertSame('3.875', (string) $c->table('stock_count_lines')->where('id', $line)->value('counted_quantity'));
    }

    /* ── counted items: whole only where switched on ─────────────────────── */

    public function test_a_counted_item_is_whole_only_where_the_business_switched_it_on(): void
    {
        $napkin = $this->countedItem('W2C Napkin', 12.5);
        $c = DB::connection('tenant');

        // Switch off (every live business today): a part is taken, made exact — as Khatri buys some "each" items.
        $this->grnOld($napkin, '2.5')->assertSessionHasNoErrors();
        $this->assertSame('2.500', (string) $this->lastGrnLine($napkin)->quantity_received);
        $this->grnV2($napkin, ['buy' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('12.500', (string) $this->lastGrnLine($napkin)->quantity_received, 'a carton of 12.5 comes in as 12.5');

        // Switch on: refused on every screen, in the counter's words, and nothing written.
        $this->setFeatures(self::BASE + ['whole_counted_stock' => true]);
        $before = $this->counts();
        $this->grnOld($napkin, '2.5')->assertSessionHasErrors(['lines.0.quantity_received' => 'W2C Napkin is counted in whole numbers (Piece) — 2.5 is not a whole number.']);
        $this->grnV2($napkin, ['buy' => '1'])->assertSessionHasErrors(['lines.0.packs' => 'W2C Napkin is counted in whole numbers (Piece) — 12.5 is not a whole number.']);
        $this->poOld($napkin, '1.5')->assertSessionHasErrors('lines.0.quantity_ordered');
        $this->adjust($napkin, '1.5')->assertSessionHasErrors('lines.0.quantity');
        $this->transfer($napkin, '0.5')->assertSessionHasErrors('lines.0.quantity');
        $this->assertSame($before, $this->counts());
        [$session, $line] = $this->countLine($napkin);
        $this->owner()->patch($this->url("/stock-counts/{$session}/lines/{$line}"), ['counted_quantity' => '2.5'])->assertSessionHasErrors('counted_quantity');

        // Whole numbers still go through, and kg keeps its decimals with the switch on.
        $this->grnOld($napkin, '3')->assertSessionHasNoErrors();
        $this->transfer($napkin, '2')->assertSessionHasNoErrors();
        $this->adjust($this->kgItem('W2C Flour'), '0.75')->assertSessionHasNoErrors();
    }

    /* ── supplier returns ────────────────────────────────────────────────── */

    public function test_a_supplier_return_says_what_is_left_when_the_draft_is_saved(): void
    {
        $juice = $this->countedItem('W2C Juice');
        $this->grnOld($juice, '10')->assertSessionHasNoErrors();
        $grnLine = $this->lastGrnLine($juice);
        $c = DB::connection('tenant');

        $this->supplierReturn($grnLine, '4')->assertSessionHasNoErrors();
        $first = $c->table('purchase_returns')->orderByDesc('id')->value('id');
        $this->owner()->post($this->url("/purchase-returns/{$first}/post"))->assertSessionHasNoErrors();

        $drafts = $c->table('purchase_returns')->count();
        $this->supplierReturn($grnLine, '7')->assertSessionHasErrors(['return' => 'W2C Juice: only 6 Piece can still be returned, not 7.']);
        $this->assertSame($drafts, $c->table('purchase_returns')->count(), 'the refused draft is not kept');

        $this->supplierReturn($grnLine, '6')->assertSessionHasNoErrors();
        $second = $c->table('purchase_returns')->orderByDesc('id')->value('id');
        $this->owner()->post($this->url("/purchase-returns/{$second}/post"))->assertSessionHasNoErrors();

        $this->supplierReturn($grnLine, '1')->assertSessionHasErrors(['return' => 'W2C Juice: nothing is left to return on this receipt — all 10 Piece already went back.']);

        // Editing a draft is held to the same rule.
        $this->grnOld($juice, '5')->assertSessionHasNoErrors();
        $fresh = $this->lastGrnLine($juice);
        $this->supplierReturn($fresh, '2')->assertSessionHasNoErrors();
        $draft = $c->table('purchase_returns')->orderByDesc('id')->value('id');
        $this->owner()->put($this->url("/purchase-returns/{$draft}"), ['return_date' => now()->toDateString(), 'reason_code' => 'damaged',
            'lines' => [['product_id' => $juice->id, 'source_line_id' => $fresh->id, 'quantity' => '6', 'unit_cost' => '50']]])
            ->assertSessionHasErrors(['return' => 'W2C Juice: only 5 Piece can still be returned, not 6.']);
        $this->assertSame('2.000', (string) $c->table('purchase_return_lines')->where('purchase_return_id', $draft)->value('quantity'), 'the draft keeps its lines');
    }

    /* ── the stock book's cost ───────────────────────────────────────────── */

    public function test_the_stock_book_cost_is_the_stored_quantity_times_its_cost_for_a_derived_quantity_too(): void
    {
        $oil = $this->kgItem('W2C Oil');
        $this->grnOld($oil, '5', '333.3333')->assertSessionHasNoErrors();
        $c = DB::connection('tenant');
        $branch = Branch::findOrFail($this->branchId());
        $inv = app(InventoryService::class);

        // A recipe at 4 decimals × plates comes to 0.12345 kg: rounded once (never refused), cost from the same number.
        $legs = $inv->postOutFefo($branch, $oil->fresh(), null, 0.12345, 'sale', null, null, 'W2C-TEST');
        $this->assertCount(1, $legs);
        $row = $c->table('stock_ledgers')->where('id', $legs[0]->id)->first();
        $this->assertSame('0.123', (string) $row->quantity);
        $this->assertEqualsWithDelta(round(0.123 * (float) $row->unit_cost, 4), (float) $row->total_cost, 0.00001);
        $this->assertEqualsWithDelta(4.877, $this->stock($oil), 0.00001);

        // Kitchen production at 4 decimals comes in through postIn: the same single rounding, the cost from that number.
        $in = $inv->postIn($branch, $oil->fresh(), null, 0.12345, 100.0, 'production_in', null, null, 'W2C-TEST');
        $row = $c->table('stock_ledgers')->where('id', $in->id)->first();
        $this->assertSame('0.123', (string) $row->quantity);
        $this->assertSame('12.3000', (string) $row->total_cost, 'cost of the 0.123 kg stored, not of 0.12345');
        $this->assertEqualsWithDelta(5.0, $this->stock($oil), 0.00001);

        // Less than half a thousandth is nothing to store: no 0.000 leg is written.
        $this->assertSame([], $inv->postOutFefo($branch, $oil->fresh(), null, 0.0004, 'sale', null, null, 'W2C-TEST'));
        $this->assertSame(0, $c->table('stock_ledgers')->where('quantity', 0)->count());

        // Every row in the book agrees with itself.
        $this->assertSame(0, $c->table('stock_ledgers')->whereRaw('ABS(total_cost - ROUND(quantity * unit_cost, 4)) > 0.00005')->count());
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    private function grnOld(Product $p, string $qty, string $cost = '50')
    {
        return $this->owner()->post($this->url('/goods-receipts'), ['branch_id' => $this->branchId(), 'supplier_id' => $this->supplier()->id,
            'receipt_date' => now()->toDateString(), 'lines' => [['product_id' => $p->id, 'product_variant_id' => $this->variantOf($p), 'quantity_received' => $qty, 'unit_cost' => $cost]]]);
    }

    private function grnV2(Product $p, array $packs)
    {
        return $this->owner()->post($this->url('/goods-receipts'), ['_entry_v2' => '1', 'branch_id' => $this->branchId(), 'supplier_id' => $this->supplier()->id,
            'receipt_date' => now()->toDateString(), 'lines' => [['product_id' => $p->id, 'buy_pack' => array_key_first($packs), 'price' => '50', 'packs' => $packs]]]);
    }

    private function poOld(Product $p, string $qty, string $cost = '50')
    {
        return $this->owner()->post($this->url('/purchase-orders'), ['branch_id' => $this->branchId(), 'supplier_id' => $this->supplier()->id,
            'order_date' => now()->toDateString(), 'lines' => [['product_id' => $p->id, 'quantity_ordered' => $qty, 'unit_cost' => $cost]]]);
    }

    private function adjust(Product $p, string $qty)
    {
        return $this->owner()->post($this->url('/stock-adjustments'), ['branch_id' => $this->branchId(), 'adjustment_type' => 'increase',
            'adjustment_date' => now()->toDateString(), 'lines' => [['product_id' => $p->id, 'quantity' => $qty, 'unit_cost' => '50']]]);
    }

    private function transfer(Product $p, string $qty)
    {
        $other = Branch::firstOrCreate(['code' => 'W2C-B2'], ['name' => 'W2C Second Branch', 'status' => 'active']);

        return $this->owner()->post($this->url('/stock-transfers'), ['from_branch_id' => $this->branchId(), 'to_branch_id' => $other->id,
            'transfer_date' => now()->toDateString(), 'lines' => [['product_id' => $p->id, 'quantity' => $qty]]]);
    }

    private function supplierReturn(object $grnLine, string $qty)
    {
        $grn = DB::connection('tenant')->table('goods_receipts')->where('id', $grnLine->goods_receipt_id)->first();

        return $this->owner()->post($this->url('/purchase-returns'), ['branch_id' => $grn->branch_id, 'supplier_id' => $grn->supplier_id,
            'goods_receipt_id' => $grn->id, 'return_date' => now()->toDateString(), 'reason_code' => 'damaged',
            'lines' => [['product_id' => $grnLine->product_id, 'source_line_id' => $grnLine->id, 'quantity' => $qty, 'unit_cost' => '50']]]);
    }

    /** @return array{0:int,1:int} a fresh count session and its line for $p */
    private function countLine(Product $p): array
    {
        $c = DB::connection('tenant');
        $this->owner()->post($this->url('/stock-counts'), ['branch_id' => $this->branchId()])->assertSessionHasNoErrors();
        $session = (int) $c->table('stock_count_sessions')->orderByDesc('id')->value('id');
        $this->owner()->post($this->url("/stock-counts/{$session}/lines"), ['product_id' => $p->id, 'product_variant_id' => $this->variantOf($p)])->assertSessionHasNoErrors();

        return [$session, (int) $c->table('stock_count_lines')->where('stock_count_session_id', $session)->where('product_id', $p->id)->value('id')];
    }

    private function counts(): array
    {
        $c = DB::connection('tenant');

        return collect(['goods_receipts', 'goods_receipt_lines', 'purchase_orders', 'stock_adjustments', 'stock_transfers', 'stock_ledgers', 'purchase_returns'])
            ->mapWithKeys(fn ($t) => [$t => $c->table($t)->count()])->all();
    }

    private function kgItem(string $name): Product
    {
        $kg = Unit::updateOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);

        return $this->product($name, $kg->id);
    }

    /** A counted item (Piece); with $cartonOf, bought by the carton of that many (a fraction allowed, as on the product screen). */
    private function countedItem(string $name, ?float $cartonOf = null): Product
    {
        $pc = Unit::updateOrCreate(['code' => 'PC'], ['name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $carton = Unit::updateOrCreate(['code' => 'CTN'], ['name' => 'Carton', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => false, 'is_active' => true]);

        return $this->product($name, $pc->id, $cartonOf ? ['purchase_unit_id' => $carton->id, 'purchase_pack_size' => $cartonOf] : []);
    }

    private function product(string $name, int $unitId, array $extra = []): Product
    {
        $sku = 'W2C-' . strtoupper(substr(md5($name), 0, 6));
        $p = Product::create($extra + ['sku' => $sku, 'name' => $name, 'slug' => Str::slug($name), 'unit_id' => $unitId, 'product_type' => 'simple',
            'product_kind' => 'sale_item', 'inventory_consumption_method' => 'stock_item', 'is_sellable' => true, 'is_pos_visible' => true,
            'is_purchasable' => true, 'is_stock_tracked' => true, 'has_expiry' => false, 'requires_batch' => false, 'default_selling_price' => 0,
            'status' => 'active']);
        ProductVariant::create(['product_id' => $p->id, 'sku' => $sku, 'name' => $name, 'is_default' => true, 'is_active' => true]);

        return $p->fresh();
    }

    /** The item's own (default) variant — what the screens send from the product lookup. */
    private function variantOf(Product $p): int
    {
        return (int) ProductVariant::where('product_id', $p->id)->where('is_default', true)->value('id');
    }

    private function supplier(): Supplier
    {
        return Supplier::firstOrCreate(['code' => 'W2C-S1'], ['name' => 'W2C Supplier', 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0]);
    }

    private function lastGrnLine(Product $p)
    {
        return DB::connection('tenant')->table('goods_receipt_lines')->where('product_id', $p->id)->orderByDesc('id')->first();
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
        DB::connection('master')->table('tenants')->where('tenant_code', self::CODE)->update(['features' => json_encode($features)]);
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
            'tenant_code' => self::CODE, 'business_name' => 'Purchase Quantity Test', 'owner_name' => 'Demo Owner', 'owner_email' => 'owner@pharmacydemo.com',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0, 'primary_business' => 'pharmacy', 'features' => json_encode(self::BASE),
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
