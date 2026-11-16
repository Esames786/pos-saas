<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * DASHBOARD-CARDS-WHEN-USED-1 — owner, 2 Nov (crossed both cards on a restaurant's dashboard): "tax collected and
 * expiry tab aen agar waqai mai tax collect hua hai and product ki expiry waqai mai ho". The same rule the
 * dashboard already applies to Service Charge and Tips. Rendered through the real dashboard route.
 */
class DashboardCardsWhenUsedMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;

    private int $tenantId;

    private int $ownerId;

    private int $branchId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = 'dashcards.'.config('tenancy.tenant_base_domain');
        $this->seedMaster();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('tenant_code', 'dashcards')->delete();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function test_a_restaurant_without_tax_or_expiry_sees_neither_card(): void
    {
        $this->sale(1000, 0);
        $page = $this->dashboard()->assertOk();
        $page->assertSee('1,000.00');
        $page->assertDontSee('Tax Collected');
        $page->assertDontSee('Expiry Alerts (30d)');
        $page->assertSee('Open Shifts', false);   // the other cards stay
    }

    public function test_tax_shows_when_a_branch_charges_it_or_tax_was_taken(): void
    {
        $this->sale(1000, 50);   // tax taken today, even with the branch switch off
        $this->dashboard()->assertOk()->assertSee('Tax Collected');

        DB::connection('tenant')->table('sales_orders')->update(['tax_amount' => 0]);
        $this->dashboard()->assertOk()->assertDontSee('Tax Collected');

        DB::connection('tenant')->table('branches')->where('id', $this->branchId)->update(['is_tax_enabled' => 1]);
        $this->dashboard()->assertOk()->assertSee('Tax Collected');   // a tax-registered branch sees it every day
    }

    public function test_expiry_shows_where_products_track_expiry(): void
    {
        $this->dashboard()->assertOk()->assertDontSee('Expiry Alerts (30d)');

        DB::connection('tenant')->table('products')->where('id', $this->productId)->update(['has_expiry' => 1]);
        $this->dashboard()->assertOk()->assertSee('Expiry Alerts (30d)');
    }

    // ── helpers ──

    private function dashboard()
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')->get('http://'.$this->host.'/dashboard');
    }

    private function sale(float $total, float $tax): int
    {
        $id = $this->makeSale($this->branchId, [
            'status' => 'paid', 'order_type' => 'takeaway', 'grand_total' => $total, 'tax_amount' => $tax,
            'business_date' => app(\App\Support\TenantClock::class)->currentBusinessDate(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId)),
        ]);
        $this->makeSaleLine($id, $this->productId, ['quantity' => 1]);

        return $id;
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        if ($old = $m->table('tenants')->where('tenant_code', 'dashcards')->value('id')) {
            $m->table('subscriptions')->where('tenant_id', $old)->delete();
            $m->table('tenant_databases')->where('tenant_id', $old)->delete();
            $m->table('tenants')->where('id', $old)->delete();
        }
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'dashcards', 'business_name' => 'Dash Cards', 'owner_name' => 'Owner', 'owner_email' => 'owner@dashcards.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'), 'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb, 'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $planId = $m->table('plans')->where('code', 'dashcards-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'dashcards-plan', 'name' => 'Dash Cards', 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        $key = $m->table('route_catalogs')->where('route_name', 'tenant.dashboard')->value('module_key');
        $module = $key ? Module::forRouteModuleKey($key)->first() : null;
        if ($module) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $module->id, 'is_enabled' => 1]);
        }
        $m->table('subscriptions')->insert([
            'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedTenant(): void
    {
        // permissions/roles come from a tenant MIGRATION — never truncate them.
        $this->cleanTenant(['sale_payments', 'sales_order_lines', 'sales_orders', 'inventory_batches', 'model_has_roles', 'users', 'products', 'categories', 'branches']);
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');
        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId(['name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['tenant.dashboard', 'tenant.dashboard.details'] as $name) {
            $c->table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'tenant'], ['created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $pid) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $pid, 'role_id' => $ownerRole], []);
        }
        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'CardsOwner', 'email' => 'cardsowner@dashcards.test', 'password' => bcrypt('x'),
            'employee_code' => 'CRDOWN', 'status' => 'active', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId]);
        $this->branchId = $this->makeBranch();
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'Food', 'slug' => 'food-'.Str::random(4)]));
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
