<?php

namespace Tests\MySql\Support;

use App\Models\Master\Tenant;
use App\Models\Tenant\Product;
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
 * UNITS-PACKS batch: one isolated store (its own tenant database, the pharmacy demo seeded into it, an open shift on
 * counter C1) for the batch's journey tests, reached through the real routes as its owner.
 *
 * The using class sets STORE_CODE, STORE_SUFFIX and STORE_FEATURES and calls bootStore() from setUp().
 */
trait IsolatedPharmacyStore
{
    private static bool $storeBuilt = false;

    private string $host;
    private string $storeDb;

    protected function bootStore(): void
    {
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = static::STORE_CODE . '.' . config('tenancy.tenant_base_domain');
        $this->storeDb = $this->tenantDb . static::STORE_SUFFIX;

        if (! self::$storeBuilt) {
            $this->createStoreDatabase();
            DB::setDefaultConnection('master');
            (new MasterSeeder)->run();
            Artisan::call('system:routes-sync');
            $this->seedMasterTenant();
            $this->useStoreDb();
            $this->buildStore();
            self::$storeBuilt = true;
        } else {
            $this->useStoreDb();
        }
        $this->setFeatures(static::STORE_FEATURES);
    }

    protected function kg(): Unit
    {
        return Unit::updateOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
    }

    protected function litre(): Unit
    {
        return Unit::updateOrCreate(['code' => 'L'], ['name' => 'Litre', 'unit_type' => 'volume', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
    }

    protected function piece(): Unit
    {
        return Unit::updateOrCreate(['code' => 'PC'], ['name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
    }

    protected function product(string $name, int $unitId, array $extra = []): Product
    {
        $sku = strtoupper(static::STORE_CODE) . '-' . strtoupper(substr(md5($name), 0, 6));
        $p = Product::create($extra + ['sku' => $sku, 'name' => $name, 'slug' => Str::slug($name), 'unit_id' => $unitId, 'product_type' => 'simple',
            'product_kind' => 'sale_item', 'inventory_consumption_method' => 'stock_item', 'is_sellable' => true, 'is_pos_visible' => true,
            'is_purchasable' => true, 'is_stock_tracked' => true, 'has_expiry' => false, 'requires_batch' => false, 'default_selling_price' => 0,
            'status' => 'active']);
        ProductVariant::create(['product_id' => $p->id, 'sku' => $sku, 'name' => $name, 'is_default' => true, 'is_active' => true]);

        return $p->fresh();
    }

    protected function supplier(): Supplier
    {
        return Supplier::firstOrCreate(['code' => strtoupper(static::STORE_CODE) . '-S1'], ['name' => 'Store Supplier', 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0]);
    }

    /** A GRN on the new screen: $packs = [pack key => how many], priced per $buyPack; $extra = batch_no, expiry (MM/YY). */
    protected function receive(Product $p, string $buyPack, string $price, array $packs, array $extra = [])
    {
        return $this->owner()->post($this->url('/goods-receipts'), ['_entry_v2' => '1', 'branch_id' => $this->branchId(), 'supplier_id' => $this->supplier()->id,
            'receipt_date' => now()->toDateString(), 'lines' => [$extra + ['product_id' => $p->id, 'buy_pack' => $buyPack, 'price' => $price, 'packs' => $packs]]]);
    }

    protected function sell(array $lines, float $total)
    {
        return $this->owner()->postJson($this->url('/pos'), [
            'branch_id' => $this->branchId(), 'terminal_id' => Terminal::where('code', 'C1')->firstOrFail()->id, 'order_type' => 'takeaway',
            'discount_type' => 'none', 'client_uuid' => (string) Str::uuid(), 'lines' => $lines,
            'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip',
            'payments' => [['payment_method_id' => (int) DB::connection('tenant')->table('payment_methods')->where('method_type', 'cash')->value('id'),
                'amount' => $total, 'tendered_amount' => ceil($total / 100) * 100]],
        ]);
    }

    protected function hold(array $lines)
    {
        return $this->owner()->postJson($this->url('/held-sales'), ['branch_id' => $this->branchId(), 'terminal_id' => Terminal::where('code', 'C1')->firstOrFail()->id,
            'order_type' => 'takeaway', 'discount_type' => 'none', 'lines' => $lines, 'save_as_draft' => '0']);
    }

    protected function giveBack(int $orderId, int $lineId, float $qty, float $refund)
    {
        return $this->owner()->post($this->url('/sales-returns'), [
            'sales_order_id' => $orderId, 'reason' => 'customer brought it back', 'refund_method' => 'cash', 'refund_amount' => $refund,
            'submission_key' => \App\Support\SubmissionKey::fresh(), 'lines' => [['sales_order_line_id' => $lineId, 'quantity' => $qty]],
        ]);
    }

    protected function lastSale(): SalesOrder
    {
        return SalesOrder::where('status', 'paid')->latest('id')->firstOrFail();
    }

    protected function stock(Product $p): float
    {
        return (float) DB::connection('tenant')->table('stock_balances')->where('branch_id', $this->branchId())->where('product_id', $p->id)->sum('quantity_on_hand');
    }

    protected function branchId(): int
    {
        return (int) DB::connection('tenant')->table('branches')->orderBy('id')->value('id');
    }

    protected function url(string $path): string
    {
        return 'http://' . $this->host . $path;
    }

    protected function owner()
    {
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->where('email', 'owner@pharmacydemo.com')->firstOrFail(), 'tenant');
    }

    protected function setFeatures(array $features): void
    {
        DB::connection('master')->table('tenants')->where('tenant_code', static::STORE_CODE)->update(['primary_business' => $this->storeBusiness(), 'features' => json_encode($features)]);
        if (app()->bound('tenant')) {
            app('tenant')->features = $features;
        }
    }

    /** W3/W4 S3: a using class may set STORE_BUSINESS / STORE_PLAN; without them the store is the pharmacy demo, as before. */
    private function storeBusiness(): string
    {
        return defined(static::class . '::STORE_BUSINESS') ? static::STORE_BUSINESS : 'pharmacy';
    }

    private function storePlan(): string
    {
        return defined(static::class . '::STORE_PLAN') ? static::STORE_PLAN : 'pharmacy_demo';
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
        if ($old = $m->table('tenants')->where('tenant_code', static::STORE_CODE)->value('id')) {
            foreach (['tenant_domains', 'tenant_databases', 'subscriptions', 'tenant_setting_changes'] as $t) {
                $m->table($t)->where('tenant_id', $old)->delete();
            }
            $m->table('tenants')->where('id', $old)->delete();
        }
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenant_databases')->where('db_database', $this->storeDb)->delete();

        $id = $m->table('tenants')->insertGetId([
            'tenant_code' => static::STORE_CODE, 'business_name' => 'Units Packs Store', 'owner_name' => 'Demo Owner', 'owner_email' => 'owner@pharmacydemo.com',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0, 'primary_business' => $this->storeBusiness(), 'features' => json_encode(static::STORE_FEATURES),
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
            'tenant_id' => $id, 'plan_id' => $m->table('plans')->where('code', $this->storePlan())->value('id'),
            'status' => 'active', 'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function buildStore(): void
    {
        $tenant = Tenant::on('master')->where('tenant_code', static::STORE_CODE)->firstOrFail();
        app()->instance('tenant', $tenant);
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        (new \ReflectionMethod(TenantProvisioner::class, 'seedTenantBaseData'))->invoke(app(TenantProvisioner::class), $tenant, 'demo1234');
        app(PharmacyDemoSeeder::class)->run();
    }
}
