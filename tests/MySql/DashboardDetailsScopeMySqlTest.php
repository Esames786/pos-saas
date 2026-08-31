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
 * DASHBOARD-DETAILS-1 — "Top 5 Products Today" and "Last 7 Days — Net Sales" belong to the owner.
 *
 * Both cards read the WHOLE branch: what sells, and how each day compared. A counter operator has
 * no business with either. `tenant.dashboard` could not be trimmed to do this — it is a baseline
 * permission every role holds, or the role 403s on its own landing page — so the split needed a
 * new one.
 *
 * The test asks for the real page over HTTP and looks at the HTML, because that is the only thing
 * that proves the operator cannot see the numbers. It also asserts the queries do not RUN without
 * the permission: a blade-only gate would still hit the database and merely throw the rows away.
 */
class DashboardDetailsScopeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const PERMISSION = 'tenant.dashboard.details';

    private string $host;
    private int $tenantId;
    private int $ownerId;
    private int $operatorId;
    private int $operatorRoleId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'dashdetails.' . config('tenancy.tenant_base_domain');
        $this->seedMaster();
        $this->seedSubscription();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('tenant_code', 'dashdetails')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    private function dashboardAs(int $userId)
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->find($userId), 'tenant')
            ->get('http://' . $this->host . '/dashboard');
    }

    /** The owner keeps both cards, with real figures in them. */
    public function test_the_owner_sees_both_cards(): void
    {
        $res = $this->dashboardAs($this->ownerId);

        $res->assertOk();
        $res->assertSee('Top 5 Products Today');
        $res->assertSee('Last 7 Days');
        $res->assertSee('Chicken Biryani');           // the product that was actually sold
    }

    /** The operator gets the page and the tiles — and neither of the two cards. */
    public function test_an_operator_without_the_permission_sees_neither_card(): void
    {
        $this->revokeFromOperator();

        $res = $this->dashboardAs($this->operatorId);

        $res->assertOk();
        $res->assertSee('Net Sales Today');            // the tiles stay — the owner asked for that
        $res->assertDontSee('Top 5 Products Today');
        $res->assertDontSee('Last 7 Days');
        $res->assertDontSee('Chicken Biryani',  false);
    }

    /**
     * Not merely hidden — not fetched. A blade-only gate would still run both queries on every
     * dashboard load for every operator, which is the cost this permission exists to avoid.
     */
    public function test_the_queries_do_not_run_without_the_permission(): void
    {
        $this->revokeFromOperator();

        $ran = [];
        DB::connection('tenant')->listen(function ($q) use (&$ran) { $ran[] = $q->sql; });
        $this->dashboardAs($this->operatorId)->assertOk();

        $topProducts = array_filter($ran, fn ($s) => str_contains($s, 'qty_sold'));
        $sevenDays   = array_filter($ran, fn ($s) => str_contains($s, 'net_sales'));

        $this->assertSame([], array_values($topProducts), 'the Top Products query must not run');
        $this->assertSame([], array_values($sevenDays), 'the 7-day query must not run');
    }

    /** Grant it back and the operator sees them again — the gate is the only thing deciding. */
    public function test_granting_it_back_restores_both_cards(): void
    {
        $this->revokeFromOperator();
        $this->dashboardAs($this->operatorId)->assertDontSee('Top 5 Products Today');

        $this->grantToOperator();

        $res = $this->dashboardAs($this->operatorId);
        $res->assertSee('Top 5 Products Today');
        $res->assertSee('Last 7 Days');
    }

    private function permissionId(): int
    {
        $c = DB::connection('tenant');
        $id = $c->table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'tenant')->value('id');

        return (int) ($id ?: $c->table('permissions')->insertGetId([
            'name' => self::PERMISSION, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    private function revokeFromOperator(): void
    {
        DB::connection('tenant')->table('role_has_permissions')
            ->where('role_id', $this->operatorRoleId)->where('permission_id', $this->permissionId())->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function grantToOperator(): void
    {
        DB::connection('tenant')->table('role_has_permissions')->updateOrInsert(
            ['role_id' => $this->operatorRoleId, 'permission_id' => $this->permissionId()], []
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenants')->where('tenant_code', 'dashdetails')->delete();

        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'dashdetails', 'business_name' => 'Dashboard Details',
            'owner_name' => 'Owner', 'owner_email' => 'owner@dashdetails.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Without a live plan carrying the dashboard's module the page 403s before the controller runs. */
    private function seedSubscription(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $planId = $m->table('plans')->where('code', 'dashdetails-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'dashdetails-plan', 'name' => 'Dashboard Details', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();

        // If a module owns the dashboard route, the plan must carry it. If none does, the access
        // service treats the route as `unmapped_route_module_key` and ALLOWS it (a deliberate
        // fail-open, so one missing mapping cannot lock a tenant out of its own app) — so there is
        // nothing to enable, and the 200 assertions below still mean what they say.
        $routeModuleKey = $m->table('route_catalogs')->where('route_name', 'tenant.dashboard')->value('module_key');
        $module = $routeModuleKey ? Module::forRouteModuleKey($routeModuleKey)->first() : null;
        if ($module) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $module->id, 'is_enabled' => 1]);
        }

        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert([
            'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedTenant(): void
    {
        // permissions/roles come from a tenant MIGRATION — never truncate them.
        $this->cleanTenant([
            'sales_order_lines', 'sales_orders', 'model_has_roles', 'users',
            'products', 'categories', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        $this->operatorRoleId = (int) ($c->table('roles')->where('name', 'DashOperator')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'DashOperator', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]));

        foreach (['tenant.dashboard', self::PERMISSION] as $name) {
            $c->table('permissions')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'tenant'], ['created_at' => now(), 'updated_at' => now()]
            );
        }
        // Owner holds everything, exactly as deploy.sh leaves a real tenant.
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $pid) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $pid, 'role_id' => $ownerRole], []);
        }
        // The operator starts with BOTH — the migration back-grants to every role, so the hide has
        // to come from a deliberate revoke, not from the permission being absent by accident.
        foreach (['tenant.dashboard', self::PERMISSION] as $name) {
            $pid = $c->table('permissions')->where('name', $name)->where('guard_name', 'tenant')->value('id');
            $c->table('role_has_permissions')->updateOrInsert(
                ['permission_id' => $pid, 'role_id' => $this->operatorRoleId], []
            );
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'DashOwner', 'email' => 'dashowner@dashdetails.test', 'password' => bcrypt('x'),
            'employee_code' => 'DSHOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->operatorId = $c->table('users')->insertGetId([
            'name' => 'DashOperator', 'email' => 'dashop@dashdetails.test', 'password' => bcrypt('x'),
            'employee_code' => 'DSHOP', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            ['role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId],
            ['role_id' => $this->operatorRoleId, 'model_type' => User::class, 'model_id' => $this->operatorId],
        ]);

        $this->branchId = $this->makeBranch();
        $category = $this->makeCategory(['name' => 'Rice', 'slug' => 'rice-' . Str::random(4)]);
        $product = $this->makeProduct($category, ['name' => 'Chicken Biryani']);

        // one paid sale today, so both cards have something real to show
        $saleId = $this->makeSale($this->branchId, ['status' => 'paid', 'order_type' => 'takeaway', 'grand_total' => 850]);
        $this->makeSaleLine($saleId, $product, ['product_name' => 'Chicken Biryani', 'quantity' => 2]);

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
