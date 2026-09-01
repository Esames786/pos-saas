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
 * The POS screen must actually LOAD. Over real HTTP, through the real routes.
 *
 * Twice in one day a change shipped on a full green suite and took the tills down:
 *
 *   - `$request` was read inside a transaction closure that never captured it, so every
 *     Complete Sale died;
 *   - HIDDEN-PRODUCT-HELD-BILL-1 called `SalesOrderLine::salesOrder()`, a relation that does
 *     not exist (it is `order()`), so every POS screen died with BadMethodCallException.
 *
 * Both times the guards were service-level, and both times they rebuilt the query with
 * DB::table() joins instead of running the controller — so the broken line was never
 * executed by anything. A test that reproduces the code under test cannot fail when that
 * code is wrong; only one that CALLS it can.
 *
 * So this test asks for the page the way a cashier's browser does and insists on a 200,
 * with an open bill carrying a hidden product present — the exact state that makes the
 * payload query run its third branch.
 */
class PosScreenRendersHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;
    private int $tenantId;
    private int $ownerId;
    private int $branchId;
    private int $hiddenProductId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'posrender.' . config('tenancy.tenant_base_domain');
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
            $m->table('tenants')->where('tenant_code', 'posrender')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    /** The 403 page prints the reason in its own words — surface that, not 400 chars of CSS. */
    private function whyRefused($res): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($res->getContent())));
        if (preg_match('/Module Not Available (.+?) (Back to Dashboard|$)/', $text, $m)) {
            return 'MODULE GATE: ' . trim($m[1]);
        }

        return Str::limit($text, 300);
    }

    private function get_pos(string $query = '')
    {
        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->get('http://' . $this->host . '/pos' . $query);
    }

    /**
     * The plain case. If POSController@index throws for ANY reason — a missing relation, an
     * undefined variable, a bad view binding — this is where it surfaces.
     */
    public function test_the_pos_screen_loads(): void
    {
        $res = $this->get_pos();

        $this->assertSame(200, $res->getStatusCode(),
            'the POS screen must load; got ' . $res->getStatusCode() . ' — ' . $this->whyRefused($res));
    }

    /**
     * The regression that took Kashif Food down at 11:24 PKT on 31 Aug.
     *
     * A hidden product sitting on a held bill is what makes the payload query reach for the
     * SalesOrderLine -> SalesOrder relation. With the relation misnamed the page 500s, so this
     * fails loudly instead of passing on an empty table.
     */
    public function test_the_pos_screen_loads_with_a_hidden_product_on_an_open_bill(): void
    {
        $saleId = $this->makeSale($this->branchId, ['status' => 'held', 'order_type' => 'takeaway']);
        $this->makeSaleLine($saleId, $this->hiddenProductId, ['quantity' => 2]);
        DB::connection('tenant')->table('products')->where('id', $this->hiddenProductId)
            ->update(['is_pos_visible' => 0, 'is_sellable' => 1]);

        $res = $this->get_pos();

        $this->assertSame(200, $res->getStatusCode(),
            'a hidden product on an open bill must not break the POS screen; got '
            . $res->getStatusCode() . ' — ' . $this->whyRefused($res));

        // and it must be IN the payload, or the bill cannot be recalled or paid
        $res->assertSee('"id":' . $this->hiddenProductId, false);
    }

    /** A draft bill has the same claim on the payload as a held one. */
    public function test_the_pos_screen_loads_with_a_draft_bill(): void
    {
        $saleId = $this->makeSale($this->branchId, ['status' => 'draft', 'order_type' => 'takeaway']);
        $this->makeSaleLine($saleId, $this->hiddenProductId, ['quantity' => 1]);
        DB::connection('tenant')->table('products')->where('id', $this->hiddenProductId)->update(['is_pos_visible' => 0]);

        $this->assertSame(200, $this->get_pos()->getStatusCode());
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenants')->where('tenant_code', 'posrender')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'posrender', 'business_name' => 'POS Render',
            'owner_name' => 'Owner', 'owner_email' => 'owner@posrender.test',
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

    /**
     * A tenant with no subscription is refused by EnsureTenantSubscriptionAccess before the
     * controller is ever reached ("Module Not Available"), which would make this test prove
     * nothing about the page. So give it a live plan carrying the module that actually owns the
     * POS route — resolved through the same lookup the middleware uses, so the test cannot drift
     * out of coverage if that mapping is renamed.
     */
    private function seedSubscription(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $planId = $m->table('plans')->where('code', 'posrender-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'posrender-plan', 'name' => 'POS Render', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();

        $routeModuleKey = $m->table('route_catalogs')->where('route_name', 'tenant.pos.index')->value('module_key');
        $module = $routeModuleKey ? Module::forRouteModuleKey($routeModuleKey)->first() : null;

        // An unmapped route is fail-open, so a missing module is not a failure here — but a MAPPED
        // route whose module cannot be enabled would silently 403 and make the test vacuous.
        if ($routeModuleKey) {
            $this->assertNotNull($module,
                "route [tenant.pos.index] maps to [{$routeModuleKey}] but no module claims it — the "
                . 'POS screen would 403 and this test would prove nothing');
            $m->table('plan_modules')->insert([
                'plan_id' => $planId, 'module_id' => $module->id, 'is_enabled' => 1,
            ]);
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
            'sales_order_lines', 'sales_orders', 'combo_components', 'combos',
            'model_has_roles', 'users', 'products', 'categories', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);

        // EnsureRoutePermission gates on the ROUTE NAME itself, so the row has to exist — in a real
        // tenant it is created by the migration + routes-sync, which this harness does not run.
        $c->table('permissions')->updateOrInsert(
            ['name' => 'tenant.pos.index', 'guard_name' => 'tenant'],
            ['created_at' => now(), 'updated_at' => now()]
        );

        // Everything the screen touches on the way in. The Owner of a real tenant holds every
        // tenant.* permission, so withholding one here would only test the gate, not the page.
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'PosOwner', 'email' => 'posowner@posrender.test', 'password' => bcrypt('x'),
            'employee_code' => 'POSOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        $this->branchId = $this->makeBranch();
        $category = $this->makeCategory(['name' => 'Rice', 'slug' => 'rice-' . Str::random(4)]);
        $this->hiddenProductId = $this->makeProduct($category, ['name' => 'Singaporean Rice (Khass)']);

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
