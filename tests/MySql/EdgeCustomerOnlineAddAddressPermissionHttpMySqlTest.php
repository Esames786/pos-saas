<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE PHASE 4 F2 (W15 browser proof finding) — the ONLINE POS quick-add modal's "Save address" over the REAL tenant
 * HTTP route, for a CASHIER (not the Owner).
 *
 * `POST /pos/customers/{customer}/addresses` (tenant.pos.customers.addresses.store) is gated by EnsureRoutePermission
 * by route NAME, and no permission of that name is ever seeded, so a cashier holding the whole `Cashier (Counter)`
 * template still got 403 "Permission denied." on Online while the Edge twin was gated by quick-store. The middleware
 * now aliases the route name to `tenant.pos.customers.quick-store` (EnsureRoutePermission::ROUTE_PERMISSION_ALIASES):
 *
 *  - a cashier holding ONLY quick-store adds an address (200 {ok, address}) and quick-stores a customer (200) —
 *    while the `permissions` table holds NO `tenant.pos.customers.addresses.store` row at all;
 *  - a cashier without quick-store gets 403 on both twins, no row written;
 *  - a tenant that DID publish the route name through the route catalog and granted it explicitly keeps working.
 */
class EdgeCustomerOnlineAddAddressPermissionHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const ADD_ADDRESS = 'tenant.pos.customers.addresses.store';
    private const QUICK_STORE = 'tenant.pos.customers.quick-store';
    private const TENANT_CODE = 'f2addr';

    private string $host;
    private int $tenantId;
    private int $cashierId;
    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = self::TENANT_CODE . '.' . config('tenancy.tenant_base_domain');
        $this->seedMaster();
        $this->seedSubscription();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $t = DB::connection('tenant');
            $t->table('model_has_permissions')->where('model_id', $this->cashierId)->delete();
            // the route-name row only ever exists in this class's legacy test — never leave it behind for the others
            $t->table('permissions')->where('name', self::ADD_ADDRESS)->where('guard_name', 'tenant')->delete();
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('tenant_code', self::TENANT_CODE)->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    public function test_a_cashier_holding_only_quick_store_adds_an_address_on_online(): void
    {
        $this->grant(self::QUICK_STORE);
        $this->assertNoRouteNamePermissionRow();

        $r = $this->postAddress(['label' => 'Home', 'address' => 'House 7, Street 3, Proof Town', 'is_default' => true]);
        $this->assertSame(200, $r->getStatusCode(), 'quick-store must gate the Online add-address route: ' . Str::limit((string) $r->getContent(), 300));
        $r->assertJsonPath('ok', true)->assertJsonPath('address.address', 'House 7, Street 3, Proof Town')
            ->assertJsonPath('address.label', 'Home')->assertJsonPath('address.customer_id', $this->customerId);
        $this->assertSame(1, DB::connection('tenant')->table('customer_addresses')->where('customer_id', $this->customerId)->count());

        // the same permission still gates quick-store itself (the one "Add Customer" workflow, both twins)
        $this->postJson('http://' . $this->host . '/pos/customers/quick-store', ['name' => 'Zed Online', 'phone' => '0300 2223344'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('reused', false);
    }

    public function test_a_cashier_without_quick_store_is_refused_on_both_twins(): void
    {
        $this->grant('tenant.pos.index');
        $this->grant('tenant.pos.store');

        $this->postAddress(['address' => 'House 7, Street 3, Proof Town'])->assertStatus(403);
        $this->postJson('http://' . $this->host . '/pos/customers/quick-store', ['name' => 'Zed Online', 'phone' => '0300 2223344'])->assertStatus(403);

        $this->assertSame(0, DB::connection('tenant')->table('customer_addresses')->where('customer_id', $this->customerId)->count());
        $this->assertSame(0, DB::connection('tenant')->table('customers')->where('name', 'Zed Online')->count());
    }

    /** A tenant that published the route name through the route catalog and granted it explicitly keeps working. */
    public function test_an_explicit_route_name_grant_from_the_route_catalog_still_passes(): void
    {
        $this->grant(self::ADD_ADDRESS);

        $this->postAddress(['address' => 'Flat 2, Legacy Lane'])->assertOk()->assertJsonPath('ok', true);
        // ...but it is one-directional: the route-name grant does not open quick-store
        $this->postJson('http://' . $this->host . '/pos/customers/quick-store', ['name' => 'Zed Online', 'phone' => '0300 2223344'])->assertStatus(403);
    }

    private function postAddress(array $payload)
    {
        return $this->actingAs(User::on('tenant')->find($this->cashierId), 'tenant')
            ->postJson('http://' . $this->host . '/pos/customers/' . $this->customerId . '/addresses', $payload);
    }

    private function grant(string $permission): void
    {
        $c = DB::connection('tenant');
        $permId = (int) ($c->table('permissions')->where('name', $permission)->where('guard_name', 'tenant')->value('id')
            ?: $c->table('permissions')->insertGetId(['name' => $permission, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]));
        $c->table('model_has_permissions')->insertOrIgnore(['permission_id' => $permId, 'model_type' => User::class, 'model_id' => $this->cashierId]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function assertNoRouteNamePermissionRow(): void
    {
        $this->assertSame(0, DB::connection('tenant')->table('permissions')->where('name', self::ADD_ADDRESS)->count(),
            'the point of F2: the route name is never a permission row, quick-store is the grant');
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenants')->where('tenant_code', self::TENANT_CODE)->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => self::TENANT_CODE, 'business_name' => 'F2 Add Address',
            'owner_name' => 'Owner', 'owner_email' => 'owner@f2addr.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $master->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $master->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A live plan carrying the module(s) that own the two routes, resolved the way the subscription middleware does. */
    private function seedSubscription(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $planId = $m->table('plans')->where('code', 'f2addr-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'f2addr-plan', 'name' => 'F2 Add Address', 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();

        $moduleIds = [];
        foreach ([self::ADD_ADDRESS, self::QUICK_STORE, 'tenant.pos.index'] as $routeName) {
            $routeModuleKey = $m->table('route_catalogs')->where('route_name', $routeName)->value('module_key');
            if (! $routeModuleKey) {
                continue; // an unmapped route is fail-open in the subscription gate
            }
            $module = Module::forRouteModuleKey($routeModuleKey)->first();
            $this->assertNotNull($module, "route [{$routeName}] maps to [{$routeModuleKey}] but no module claims it — the route would 403 and this test would prove nothing");
            $moduleIds[$module->id] = true;
        }
        foreach (array_keys($moduleIds) as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1]);
        }

        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert([
            'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedTenant(): void
    {
        $this->cleanTenant(['customer_uuid_aliases', 'customer_addresses', 'customers', 'model_has_permissions', 'model_has_roles', 'users', 'branches']);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');
        // never a permission row for the add-address route name — exactly a real tenant (provisioner + migrations seed none)
        $c->table('permissions')->where('name', self::ADD_ADDRESS)->where('guard_name', 'tenant')->delete();

        $this->cashierId = $c->table('users')->insertGetId([
            'name' => 'F2 Cashier', 'email' => 'cashier@f2addr.test', 'password' => bcrypt('x'),
            'employee_code' => 'F2CASH', 'status' => 'active', 'locale' => 'en',
            'default_branch_id' => $this->makeBranch(['name' => 'F2 Branch']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->customerId = (int) $c->table('customers')->insertGetId([
            'customer_uuid' => (string) Str::ulid(), 'name' => 'Lab Customer One', 'phone' => '03001110001',
            'phone_normalized' => '03001110001', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
