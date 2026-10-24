<?php

namespace Tests\MySql;

use App\Models\Master\Plan;
use App\Models\Master\Tenant;
use App\Models\Tenant\InventoryBatch;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Saas\TenantSubscriptionAccessService;
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
 * PHARMACY-DEMO-1 — the medical-store demo a client is shown.
 *
 * It has to survive being driven live: open a shift, sell loose tablets and a syrup, take one
 * back. So the counter is driven here through the real routes, on a tenant flagged is_demo (the
 * demo guard sits in front of every write), on the real pharmacy_demo plan from MasterSeeder.
 *
 * The store is built ONCE into its own database (seeding 25 days of sales is not cheap), and
 * nothing else in the suite uses that database.
 */
class PharmacyDemoMySqlTest extends MySqlTenantTestCase
{
    private const CODE = 'pharmtest';

    private static bool $built = false;
    private static array $counts = [];

    private string $host;
    private string $storeDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host    = self::CODE . '.' . config('tenancy.tenant_base_domain');
        $this->storeDb = $this->tenantDb . '_pharm';

        if (! self::$built) {
            $this->createStoreDatabase();
            DB::setDefaultConnection('master');
            (new MasterSeeder)->run();
            Artisan::call('system:routes-sync');
            $this->seedMasterTenant('pharmacy_demo');
            $this->useStoreDb();
            $this->buildStore();
            self::$built = true;
        } else {
            $this->useStoreDb();
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->onPlan('pharmacy_demo');
        } catch (\Throwable) {
            // best effort
        }
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The plan
    // ══════════════════════════════════════════════════════════════════════════

    /** Private plan, store modules, and — the reason it exists — shifts open on it. */
    public function test_the_pharmacy_plan_is_private_and_can_open_a_shift(): void
    {
        $plan = Plan::on('master')->where('code', 'pharmacy_demo')->with('enabledModules')->firstOrFail();
        $this->assertFalse((bool) $plan->is_public, 'never on the public pricing page');

        $modules = $plan->enabledModules->pluck('key')->all();
        foreach (['pos', 'catalog', 'inventory', 'stock_count', 'purchasing', 'reports', 'multi_branch'] as $key) {
            $this->assertContains($key, $modules);
        }
        $this->assertNotContains('restaurant', $modules);

        $tenant = Tenant::on('master')->where('tenant_code', self::CODE)->firstOrFail();
        $access = app(TenantSubscriptionAccessService::class);
        foreach (['tenant.shifts.store', 'tenant.terminals.index', 'tenant.sales-returns.store',
                  'tenant.goods-receipts.store', 'tenant.reports.inventory.expiry'] as $route) {
            $this->assertTrue($access->check($tenant, $route)['allowed'], "{$route} must be open on the pharmacy plan");
        }

        // The check must be able to say no — otherwise the yeses above prove nothing.
        $this->assertFalse($access->check($tenant, 'tenant.restaurant.floors.index')['allowed'],
            'a restaurant screen stays closed on the pharmacy plan');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The seeded store
    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_seeded_store_adds_up(): void
    {
        $c = DB::connection('tenant');

        $this->assertSame(47, Product::count());
        $this->assertSame(5, $c->table('goods_receipts')->count(), 'first stock from three distributors, restock from the two medicine ones');
        $this->assertSame(5, $c->table('purchase_bills')->count());
        $this->assertStringNotContainsString('skipped', (string) self::$counts['sales'], 'every planned sale had the stock');
        $this->assertGreaterThan(150, SalesOrder::where('status', '!=', 'draft')->count());
        $this->assertSame(2, SalesReturn::count());

        // Nothing on the shelf is past its expiry — the counter would otherwise sell it first.
        $expiredWithStock = $c->table('stock_balances as s')->join('inventory_batches as b', 'b.id', '=', 's.inventory_batch_id')
            ->where('b.expiry_date', '<', today()->toDateString())->where('s.quantity_on_hand', '>', 0)->count();
        $this->assertSame(0, $expiredWithStock);
        $this->assertSame(0, $c->table('stock_balances')->where('quantity_on_hand', '<', 0)->count());

        // Every medicine's stock sits in a batch with an expiry date.
        $undated = $c->table('stock_balances as s')->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('inventory_batches as b', 'b.id', '=', 's.inventory_batch_id')
            ->where('p.has_expiry', 1)->where('s.quantity_on_hand', '>', 0)->whereNull('b.expiry_date')->count();
        $this->assertSame(0, $undated);

        // Panadol: received 1,600 tablets; on hand = received − sold + returned.
        $panadol = Product::where('sku', 'PH-PNL500')->firstOrFail();
        $sold = (float) $c->table('sales_order_lines as l')->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->where('o.status', '!=', 'draft')->where('l.product_id', $panadol->id)->sum('l.quantity');
        $returned = (float) $c->table('sales_return_lines as r')->join('sales_order_lines as l', 'l.id', '=', 'r.sales_order_line_id')
            ->where('l.product_id', $panadol->id)->sum('r.quantity');
        $onHand = (float) $c->table('stock_balances')->where('product_id', $panadol->id)->sum('quantity_on_hand');
        $this->assertEqualsWithDelta(1600 - $sold + $returned, $onHand, 0.001);
        $this->assertSame(2, $c->table('inventory_batches')->where('product_id', $panadol->id)->count(), 'two batches: first stock and the restock');

        // The salt has its own field (PRODUCT-SCREEN-V2), so "paracetamol" finds every brand on the POS.
        $this->assertSame('Panadol 500mg Tablet', $panadol->name);
        $this->assertSame('Paracetamol', $panadol->generic_name);
        $this->assertSame('500mg', $panadol->strength);
        $this->assertSame(4, Product::where('generic_name', 'like', 'Paracetamol%')->count());

        // Barcodes scan: valid EAN-13.
        foreach ($c->table('product_barcodes')->pluck('barcode') as $code) {
            $this->assertMatchesRegularExpression('/^\d{13}$/', $code);
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int) $code[$i] * ($i % 2 === 0 ? 1 : 3);
            }
            $this->assertSame((10 - $sum % 10) % 10, (int) $code[12], "{$code} check digit");
        }

        // A counter of a medical store: takeaway only, a counter that needs a shift.
        foreach (User::all() as $user) {
            $this->assertSame(['takeaway'], $user->effectiveAllowedOrderTypes());
        }
        $this->assertSame(1, Terminal::where('code', 'C1')->count());

        // PHARMACY-PACKS-1: strips and boxes, read from each medicine's pack text; stock stays in tablets.
        $packs = fn (string $sku) => Product::where('sku', $sku)->firstOrFail()->packUnits()->get()->map(fn ($p) => $p->name . ':' . $p->factor)->all();
        $this->assertSame(['Tablet:1', 'Strip:10', 'Box:200'], $packs('PH-PNL500'));
        $this->assertSame(['Tablet:1', 'Strip:6'], $packs('PH-AUG625'), 'a box of one strip is just the strip');
        $this->assertSame(['Piece:1', 'Box:100'], $packs('PH-SYR5'));
        $this->assertSame([], $packs('PH-CALSY'), 'a syrup bottle has no packs');
        $box = Product::where('sku', 'PH-PNL500')->firstOrFail()->packUnits()->where('factor', 200)->firstOrFail();
        $this->assertMatchesRegularExpression('/^2009200\d{6}$/', (string) $box->barcode, 'the box carries its own barcode');
        $this->assertSame(0, DB::connection('tenant')->table('product_barcodes')->where('barcode', $box->barcode)->count(), 'never mixed with single-item barcodes');
    }

    public function test_seeded_purchase_numbers_come_from_the_round_and_distributor_not_a_random_draw(): void
    {
        $c = DB::connection('tenant');
        $grns = $c->table('goods_receipts')->orderBy('id')->pluck('grn_no', 'id');
        $bills = $c->table('purchase_bills')->orderBy('id')->pluck('bill_no', 'goods_receipt_id');

        $this->assertCount(5, $grns);
        $this->assertCount(5, $grns->unique(), 'five GRNs made in the same second still have five numbers');
        $this->assertCount(5, $bills->unique());
        foreach ($grns as $id => $grnNo) {
            $this->assertMatchesRegularExpression('/^GRN-\d{8}-S[123][AB]$/', $grnNo, 'the number says the date, distributor and round');
            $this->assertSame('BILL-' . substr($grnNo, 4), $bills[$id] ?? null, 'each bill carries its own GRN\'s number');
        }
    }

    /** The near-expiry syrup is sold first: FEFO takes the older batch before the restock. */
    public function test_the_older_syrup_batch_goes_first(): void
    {
        $syrup = Product::where('sku', 'PH-CALSY')->firstOrFail();
        [$old, $new] = InventoryBatch::where('product_id', $syrup->id)->orderBy('expiry_date')->get()->all();
        $left = fn ($b) => (float) DB::connection('tenant')->table('stock_balances')->where('inventory_batch_id', $b->id)->sum('quantity_on_hand');

        $this->assertTrue($old->expiry_date->lte(today()->addDays(30)), 'the first batch is the near-expiry one');
        $this->assertLessThan(30, $left($old), 'sales came out of the older batch');
        $this->assertSame(24.0, $left($new), 'the restock is untouched while the older batch still has stock');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Through the screens, as a person would
    // ══════════════════════════════════════════════════════════════════════════

    /** Was: Qty 0.000 for every batch, and every row red with "0" days. */
    public function test_the_expiry_report_shows_real_stock_and_days_left(): void
    {
        $syrup = Product::where('sku', 'PH-CALSY')->firstOrFail();
        $batch = InventoryBatch::where('product_id', $syrup->id)->orderBy('expiry_date')->firstOrFail();
        $qty = (float) DB::connection('tenant')->table('stock_balances')->where('inventory_batch_id', $batch->id)->sum('quantity_on_hand');
        $this->assertGreaterThan(0, $qty);

        $html = $this->owner()->get('http://' . $this->host . '/reports/inventory/expiry?days=30')->assertOk()->getContent();
        $row = $this->rowOf($html, $batch->batch_no);

        $daysLeft = (int) today()->diffInDays($batch->expiry_date->copy()->startOfDay(), false);
        $this->assertGreaterThan(0, $daysLeft);
        $this->assertStringContainsString(number_format($qty, 3), $row, 'the batch shows the stock it really has');
        $this->assertStringContainsString('>' . $daysLeft . '</td>', $row, 'days LEFT, counted forward');
        $this->assertDoesNotMatchRegularExpression('/>\s*0\.000\s*</', $html, 'no batch with nothing left is listed');
    }

    /**
     * Open a shift, sell 10 loose tablets and a syrup, take the syrup back — on a demo tenant, through
     * the POS routes. And the counter never asks for a kitchen ticket; a restaurant plan still does.
     */
    public function test_a_counter_sale_and_return_work_live_and_no_kitchen_prompt(): void
    {
        $pos = $this->owner()->get('http://' . $this->host . '/pos')->assertOk()->getContent();
        $this->assertStringContainsString('const KITCHEN_TICKETS = false', $pos);
        $this->assertStringNotContainsString('Restaurant POS', $pos);
        $this->assertStringNotContainsString('id="view-tables-btn"', $pos, 'no dine-in on a medical counter');

        $c = DB::connection('tenant');
        $branchId = (int) $c->table('branches')->orderBy('id')->value('id');
        $terminal = Terminal::where('code', 'C1')->firstOrFail();

        // The demo starts the day with Counter 1 open (the reset used to leave "No Open Shift"), and
        // every login is on Counter 1.
        $this->assertSame(1, $c->table('shifts')->where('terminal_id', $terminal->id)->where('status', 'open')->count(), 'the demo opens with Counter 1 ready');
        $this->assertStringNotContainsString('No Open Shift', $pos);
        // The header said "No terminal" even with Counter 1 picked: it is now refreshed after the pick.
        $this->assertStringContainsString('window.__posUpdateContext = updateContextSummary', $pos);
        $this->assertStringContainsString('if (window.__posUpdateContext) window.__posUpdateContext();', $pos);
        $this->assertSame((int) $terminal->id, (int) $this->ownerUser()->default_terminal_id);

        $panadol = Product::where('sku', 'PH-PNL500')->firstOrFail();
        $syrup = Product::where('sku', 'PH-CALSY')->firstOrFail();
        $stock = fn (Product $p) => (float) $c->table('stock_balances')->where('product_id', $p->id)->sum('quantity_on_hand');
        [$panadolBefore, $syrupBefore] = [$stock($panadol), $stock($syrup)];

        $total = 10 * 5.25 + 190;
        // The POS posts here (tenant.pos.store), with both print intents — as its JS always does.
        $sale = $this->owner()->postJson('http://' . $this->host . '/pos', [
            'branch_id'            => $branchId,
            'terminal_id'          => $terminal->id,
            'order_type'           => 'takeaway',
            'discount_type'        => 'none',
            'client_uuid'          => (string) Str::uuid(),
            'kot_print_intent'     => 'skip',
            'receipt_print_intent' => 'skip',
            'lines'                => [
                ['product_id' => $panadol->id, 'quantity' => 10, 'unit_price' => 5.25],
                ['product_id' => $syrup->id,   'quantity' => 1,  'unit_price' => 190],
            ],
            'payments' => [['payment_method_id' => (int) $c->table('payment_methods')->where('method_type', 'cash')->value('id'),
                            'amount' => $total, 'tendered_amount' => 300]],
        ]);
        $this->assertTrue($sale->isSuccessful(), 'sale refused: ' . Str::limit($sale->getContent(), 300));

        $order = SalesOrder::where('status', 'paid')->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta($total, (float) $order->grand_total, 0.001);
        $this->assertEqualsWithDelta($panadolBefore - 10, $stock($panadol), 0.001, 'a strip of 10 tablets left the shelf');
        $this->assertEqualsWithDelta($syrupBefore - 1, $stock($syrup), 0.001);

        $syrupLine = $order->lines()->where('product_id', $syrup->id)->firstOrFail();
        $returnsBefore = SalesReturn::count();
        $ret = $this->owner()->post('http://' . $this->host . '/sales-returns', [
            'sales_order_id' => $order->id,
            'reason'         => 'Unopened, customer changed mind',
            'refund_method'  => 'cash',
            'refund_amount'  => 190,
            'lines'          => [
                ['sales_order_line_id' => $syrupLine->id, 'quantity' => 1],
                ['sales_order_line_id' => $order->lines()->where('product_id', $panadol->id)->value('id'), 'quantity' => 0],
            ],
        ]);
        $ret->assertSessionHasNoErrors();
        $this->assertSame($returnsBefore + 1, SalesReturn::count(), 'the return posted on the demo tenant');
        $this->assertEqualsWithDelta($syrupBefore, $stock($syrup), 0.001, 'the bottle is back on the shelf');

        // ...and back in the batch it was sold from, with its expiry — not a new undated batch.
        $soldFrom = $c->table('stock_ledgers')->where('reference_type', 'sales_order')->where('reference_id', $order->id)
            ->where('product_id', $syrup->id)->where('direction', 'out')->value('inventory_batch_id');
        $cameBackTo = $c->table('stock_ledgers')->where('reference_type', 'sales_return')->where('product_id', $syrup->id)
            ->orderByDesc('id')->value('inventory_batch_id');
        $this->assertSame((int) $soldFrom, (int) $cameBackTo);
        $this->assertSame(0, InventoryBatch::where('product_id', $syrup->id)->whereNull('expiry_date')->count());

        // A restaurant plan keeps its kitchen question exactly as before.
        $this->onPlan('restaurant_pro');
        $pos = $this->owner()->get('http://' . $this->host . '/pos')->assertOk()->getContent();
        $this->assertStringContainsString('const KITCHEN_TICKETS = true', $pos);
        $this->assertStringContainsString('Restaurant POS', $pos);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

    private function owner()
    {
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs($this->ownerUser(), 'tenant');
    }

    private function ownerUser(): User
    {
        return User::on('tenant')->where('email', 'owner@pharmacydemo.com')->firstOrFail();
    }

    private function rowOf(string $html, string $needle): string
    {
        $at = strpos($html, $needle);
        $this->assertNotFalse($at, "[{$needle}] on the page");
        $start = strrpos(substr($html, 0, $at), '<tr');
        $end = strpos($html, '</tr>', $at);

        return substr($html, $start, $end - $start);
    }

    private function onPlan(string $code): void
    {
        $m = DB::connection('master');
        $tenantId = $m->table('tenants')->where('tenant_code', self::CODE)->value('id');
        $m->table('subscriptions')->where('tenant_id', $tenantId)->update(['plan_id' => $m->table('plans')->where('code', $code)->value('id')]);
    }

    private function useStoreDb(): void
    {
        config(['database.connections.tenant.database' => $this->storeDb]);
        DB::purge('tenant');
    }

    private function createStoreDatabase(): void
    {
        if (stripos($this->storeDb, 'test') === false) {
            throw new \RuntimeException('the pharmacy test database name must contain "test"');
        }
        $config = config('database.connections.tenant');
        $pdo = new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password'] ?? '');
        $pdo->exec('DROP DATABASE IF EXISTS `' . $this->storeDb . '`');
        $pdo->exec('CREATE DATABASE `' . $this->storeDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->useStoreDb();
        $code = Artisan::call('migrate:fresh', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]);
        if ($code !== 0) {
            throw new \RuntimeException('pharmacy test migrations failed: ' . Artisan::output());
        }
    }

    private function seedMasterTenant(string $planCode): void
    {
        $m = DB::connection('master');
        $old = $m->table('tenants')->where('tenant_code', self::CODE)->value('id');
        if ($old) {
            foreach (['tenant_domains', 'tenant_databases', 'subscriptions'] as $t) {
                $m->table($t)->where('tenant_id', $old)->delete();
            }
            $m->table('tenants')->where('id', $old)->delete();
        }
        // Another test may wipe tenants with foreign keys off, leaving this host's domain behind.
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenant_databases')->where('db_database', $this->storeDb)->delete();

        $tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => self::CODE, 'business_name' => 'Bingoo Demo Pharmacy',
            'owner_name' => 'Demo Owner', 'owner_email' => 'owner@pharmacydemo.com',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->insert([
            'tenant_id' => $tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->storeDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert([
            'tenant_id' => $tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('subscriptions')->insert([
            'tenant_id' => $tenantId, 'plan_id' => $m->table('plans')->where('code', $planCode)->value('id'),
            'status' => 'active', 'current_period_ends_at' => now()->addYear(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** What demo:provision + demo:seed do on the server: base tenant data, then the store. */
    private function buildStore(): void
    {
        $tenant = Tenant::on('master')->where('tenant_code', self::CODE)->firstOrFail();
        app()->instance('tenant', $tenant);
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $base = new \ReflectionMethod(TenantProvisioner::class, 'seedTenantBaseData');
        $base->invoke(app(TenantProvisioner::class), $tenant, 'demo1234');

        self::$counts = app(PharmacyDemoSeeder::class)->run();
    }
}
