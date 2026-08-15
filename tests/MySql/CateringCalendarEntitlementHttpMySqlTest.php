<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-CALENDAR-1 — the widget belongs to catering plans only.
 *
 * This is the exact bug class that already reached a client once: deploy.sh
 * grants the Owner every tenant.* permission regardless of plan, so a @can
 * check is not an entitlement decision and leaked POS menus into a
 * catering-only tenant. The same mistake in reverse would put a booking
 * calendar on a restaurant's dashboard.
 *
 * So the proof runs over real HTTP against the SAME tenant, flipping only the
 * plan between the two cases. Nothing else differs — same user, same
 * permissions, same database — which means a pass can only be caused by the
 * entitlement check itself.
 */
class CateringCalendarEntitlementHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;

    private string $uri;

    private int $tenantId;

    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'calentitle.'.config('tenancy.tenant_base_domain');
        $this->uri = 'http://'.$this->host.'/dashboard/catering-calendar';

        $this->seedMaster();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();

            // The plan MUST go too. The master DB is shared across the suite, and
            // a leftover catering-enabled plan is not inert: CateringEntitlement
            // asserts that exactly one plan has catering enabled, so leaving this
            // one behind fails a test that has nothing to do with calendars.
            $planId = $m->table('plans')->where('code', 'calentitle-plan')->value('id');
            if ($planId) {
                $m->table('plan_modules')->where('plan_id', $planId)->delete();
                $m->table('subscriptions')->where('plan_id', $planId)->delete();
                $m->table('plans')->where('id', $planId)->delete();
            }

            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('tenant_code', 'calentitle')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real test outcome
        }
        parent::tearDown();
    }

    /** With catering on the plan, the calendar is reachable. */
    public function test_a_catering_tenant_can_load_the_calendar(): void
    {
        $this->setPlanModules(['catering', 'printing']);

        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')->get($this->uri);

        $this->assertNotContains($res->getStatusCode(), [403, 404],
            'a catering tenant must reach its own booking calendar; got '.$res->getStatusCode());
        $res->assertStatus(200);
    }

    /**
     * Same tenant, same user, same permissions — catering removed from the plan.
     * The only variable is entitlement, so a 404 here can have no other cause.
     */
    public function test_a_tenant_without_the_catering_module_gets_nothing(): void
    {
        $this->setPlanModules(['pos', 'printing']);

        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')->get($this->uri);

        $this->assertSame(404, $res->getStatusCode(),
            'a restaurant or retail tenant must not be able to load a catering calendar, '
            .'even though its Owner holds every tenant.* permission');
    }

    /** The dashboard view itself renders nothing when the variable is absent. */
    public function test_the_dashboard_partial_is_not_rendered_without_the_module(): void
    {
        $blade = file_get_contents(resource_path('views/tenant/dashboard.blade.php'));

        $this->assertStringContainsString('@if(! empty($cateringCalendar))', $blade,
            'the widget must be behind an explicit emptiness gate, not rendered unconditionally');
        $this->assertStringContainsString("@include('tenant.partials.catering-calendar')", $blade);

        // And the gate must be driven by entitlement, never by a permission check.
        $controller = file_get_contents(app_path('Http/Controllers/Tenant/DashboardController.php'));
        $this->assertStringContainsString("hasEnabledModuleKey('catering')", $controller,
            'the dashboard must decide from the PLAN');
        $this->assertStringNotContainsString("can('tenant.catering", $controller,
            'a permission check here would be meaningless — every Owner holds every permission');
    }

    /**
     * Build the plan from the given module keys.
     *
     * The module rows are CREATED if absent rather than skipped. An earlier
     * version did `if ($module = Module::where(...)->first())`, which silently
     * produced a plan with no catering module whenever the row happened to be
     * missing — so the test passed or failed according to which other test had
     * run first, and proved nothing on its own. Every key is asserted enabled
     * afterwards so a silent skip can never masquerade as a pass again.
     */
    private function setPlanModules(array $keys): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));

        $plan = Plan::updateOrCreate(['code' => 'calentitle-plan'], [
            'name' => 'Cal Entitle', 'price' => 0, 'is_active' => true,
        ]);
        PlanModule::where('plan_id', $plan->id)->delete();

        foreach ($keys as $key) {
            $module = Module::updateOrCreate(['key' => $key], [
                'name' => ucfirst($key),
                'category' => 'Operations',
                'route_module_keys' => ['tenant.'.$key],
                'is_core' => false,
                'is_active' => true,
            ]);
            PlanModule::create(['plan_id' => $plan->id, 'module_id' => $module->id, 'is_enabled' => true]);
        }

        Subscription::updateOrCreate(['tenant_id' => $this->tenantId], [
            'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addYear(),
        ]);

        // The plan must genuinely carry what was asked for, or the entitlement
        // assertions downstream are meaningless.
        $enabled = $plan->fresh()->loadMissing('enabledModules')->enabledModules->pluck('key')->all();
        foreach ($keys as $key) {
            $this->assertContains($key, $enabled,
                "the test plan must actually enable [{$key}] — a silently skipped module would make this test vacuous");
        }
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenants')->where('tenant_code', 'calentitle')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'calentitle', 'business_name' => 'Calendar Entitlement',
            'owner_name' => 'Owner', 'owner_email' => 'owner@calentitle.test',
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

    /** The Owner holds EVERY permission — exactly as deploy.sh leaves a real tenant. */
    private function seedTenant(): void
    {
        // permissions/roles are NOT truncated. Those rows come from a tenant
        // MIGRATION, so wiping them removes data no later test can restore — it
        // broke CateringEntitlement's expectation of 36 catering permissions.
        $this->cleanTenant([
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'model_has_roles', 'users', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        // Reuse the role if a previous run left it; never a second "Owner".
        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id');
        if (! $ownerRole) {
            $ownerRole = $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // The permission may already exist from the migration — take it as it is
        // rather than inserting a duplicate.
        foreach (['tenant.dashboard', 'tenant.dashboard.catering-calendar', 'tenant.catering.events.index'] as $perm) {
            $permId = $c->table('permissions')->where('name', $perm)->where('guard_name', 'tenant')->value('id');
            if (! $permId) {
                $permId = $c->table('permissions')->insertGetId([
                    'name' => $perm, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $c->table('role_has_permissions')->updateOrInsert(
                ['permission_id' => $permId, 'role_id' => $ownerRole], []
            );
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'CalOwner', 'email' => 'calowner@calentitle.test', 'password' => bcrypt('x'),
            'employee_code' => 'CALOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        $this->makeBranch();

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
