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
 * CATERING-SIDEBAR-COLLAPSE-1 — catering ki kaam wali screenein band navigation
 * ke saath khulti hain.
 *
 * Event ki screen par ye pehle se tha; 22 September ko malik ne kaha ke fehrist
 * wali screen par bhi chahiye, kyunke us table ke das column khuli navigation ke
 * saath dahini taraf katt rahe thay.
 *
 * Ye test ASLI HTTP par chalta hai. Blade me `@include` dhoond lena is ka
 * mutabadil nahi: is codebase me do martaba aisa ho chuka hai ke guard ne wo
 * cheez parkhi jo code me LIKHI thi, aur wo cheez nahi jo safha waqai deta hai.
 *
 * Aur sirf "mojood hai" kaafi nahi — script ka DO BAAR jana bhi kharabi hai
 * (do listeners, aur Enter ki tarah ek click do baar chalta), is liye ginti
 * bhi ki jati hai.
 */
class CateringSidebarCollapseHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;

    private int $tenantId;

    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'sidebarcollapse.'.config('tenancy.tenant_base_domain');

        $this->seedMaster();
        $this->seedPlan();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
            $planId = $m->table('plans')->where('code', 'sidebarcollapse-plan')->value('id');
            if ($planId) {
                $m->table('plan_modules')->where('plan_id', $planId)->delete();
                $m->table('subscriptions')->where('plan_id', $planId)->delete();
                $m->table('plans')->where('id', $planId)->delete();
            }
            $m->table('tenants')->where('tenant_code', 'sidebarcollapse')->delete();
        } catch (\Throwable $e) {
            // tearDown ki nakami asal nateeje ko chhupa deti hai.
        }

        parent::tearDown();
    }

    /** Fehrist wali screen — yehi maangi gayi thi. */
    public function test_the_events_list_starts_with_the_navigation_collapsed(): void
    {
        $html = $this->fetch('/catering/events');

        $this->assertStringContainsString("classList.add('nosidebar')", $html,
            'fehrist wali screen band navigation ke saath khulni chahiye');
        $this->assertStringContainsString('id="catering-sidebar-toggle"', $html,
            'aur usay wapas laane ka button bhi hona chahiye');
    }

    /**
     * Event ki screen par ye pehle se kaam kar raha tha aur ab wo isi sanjhe
     * partial se aata hai. Ye test us naql-o-harkat ka pehra hai: purani
     * khoobi na toote.
     */
    public function test_the_event_screen_keeps_the_behaviour_after_the_extraction(): void
    {
        $eventId = $this->makeEvent();

        $html = $this->fetch("/catering/events/{$eventId}");

        $this->assertStringContainsString("classList.add('nosidebar')", $html);
        $this->assertStringContainsString('id="catering-sidebar-toggle"', $html);
    }

    /** Do copiyan = do listeners = ek click do baar. */
    public function test_the_script_is_emitted_exactly_once(): void
    {
        $html = $this->fetch('/catering/events');

        $this->assertSame(1, substr_count($html, "classList.add('nosidebar')"),
            'script sirf ek baar jani chahiye');
        $this->assertSame(1, substr_count($html, 'id="catering-sidebar-toggle"'),
            'button bhi sirf ek');
    }

    /**
     * Ye faisla YAAD nahi rakha jata. Ek revision ne kabhi operator ki aakhri
     * pasand localStorage me rakhi thi, aur mahine pehle ki ek click ne
     * navigation hamesha ke liye khula chhod diya — 9 September ko floor se
     * report hui. Wo wapas na aa jaye.
     */
    public function test_the_choice_is_never_remembered_between_visits(): void
    {
        $html = $this->fetch('/catering/events');

        // Sirf usi hissay ko dekho jo ye partial deta hai, warna safhe ki koi
        // aur script is assert ko jhoota tod degi.
        $start = strpos($html, "classList.remove('mini-sidebar'");
        $this->assertNotFalse($start);
        $block = substr($html, $start, 600);

        $this->assertStringNotContainsString('localStorage', $block,
            'sidebar ka faisla yaad rakhna 9 Sep ko floor par khud ek bug bana tha');
        $this->assertStringNotContainsString('sessionStorage', $block);
    }

    private function fetch(string $uri): string
    {
        $response = $this->actingAs(User::findOrFail($this->ownerId), 'tenant')
            ->get('http://'.$this->host.$uri);

        $response->assertOk();

        return $response->getContent();
    }

    private function makeEvent(): int
    {
        DB::setDefaultConnection('tenant');
        $branchId = DB::connection('tenant')->table('branches')->value('id');

        return app(\App\Services\Catering\CateringEstimateService::class)->createEvent([
            'branch_id' => $branchId,
            'customer_name' => 'Sidebar Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 50,
        ])->id;
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenants')->where('tenant_code', 'sidebarcollapse')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'sidebarcollapse', 'business_name' => 'Sidebar Collapse',
            'owner_name' => 'Owner', 'owner_email' => 'owner@sidebarcollapse.test',
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

    /** Catering module ke baghair screen 403 degi aur test kuch sabit nahi karega. */
    private function seedPlan(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));

        $plan = Plan::updateOrCreate(['code' => 'sidebarcollapse-plan'], [
            'name' => 'Sidebar Collapse', 'price' => 0, 'is_active' => true,
        ]);
        PlanModule::where('plan_id', $plan->id)->delete();

        $module = Module::updateOrCreate(['key' => 'catering'], [
            'name' => 'Catering', 'category' => 'Operations',
            'route_module_keys' => ['tenant.catering'], 'is_core' => false, 'is_active' => true,
        ]);
        PlanModule::create(['plan_id' => $plan->id, 'module_id' => $module->id, 'is_enabled' => true]);

        Subscription::updateOrCreate(['tenant_id' => $this->tenantId], [
            'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addYear(),
        ]);
    }

    private function seedTenant(): void
    {
        // permissions/roles ko truncate NAHI karna — wo rows ek MIGRATION se
        // aati hain aur koi baad wala test unhe wapas nahi la sakta.
        $this->cleanTenant([
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'model_has_roles', 'users', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);

        foreach (['tenant.catering.events.index', 'tenant.catering.events.show'] as $perm) {
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
            'name' => 'SidebarOwner', 'email' => 'owner@sidebarcollapse.test', 'password' => bcrypt('x'),
            'employee_code' => 'SIDEOWN', 'status' => 'active', 'locale' => 'en',
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
