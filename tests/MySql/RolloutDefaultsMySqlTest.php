<?php

namespace Tests\MySql;

use App\Models\Master\CentralUser;
use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Tenant;
use App\Services\Saas\DemoProvisioner;
use App\Services\Saas\DemoResetService;
use App\Services\Saas\SelfSignupService;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * ROLLOUT-1 (25 Oct) — one defaults policy (TenantFeatureDefaults) for every way a tenant is made, through the real paths:
 *  - self-signup and Master create start with the screens their plan carries (the business is not known yet);
 *  - the business set for the first time fills its business switches, only where the tenant never set them;
 *  - every public demo starts with its business's defaults, and the nightly reset (provision fresh, then seed) keeps
 *    them — the inventory and pharmacy demos are provisioned, changed by hand, reset and re-seeded for real;
 *  - no other tenant's switches change, ever.
 */
class RolloutDefaultsMySqlTest extends MySqlTenantTestCase
{
    private static bool $seeded = false;

    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('master');
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        if (! self::$seeded) {
            (new MasterSeeder)->run();   // the real plans and modules the demos are built from
            self::$seeded = true;
        }
        $this->clean();
    }

    protected function tearDown(): void
    {
        try {
            $this->clean();
            foreach ($this->userIds as $id) {
                DB::connection('master')->table('model_has_permissions')->where('model_id', $id)->where('model_type', CentralUser::class)->delete();
                DB::connection('master')->table('central_users')->where('id', $id)->delete();
            }
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    /* ── signup and Master create ────────────────────────────────────────── */

    public function test_a_signup_starts_with_the_screens_its_plan_carries(): void
    {
        $others = $this->otherTenants();
        $pro = $this->plan(['pos', 'catalog', 'inventory', 'purchasing', 'restaurant']);
        $starter = $this->plan(['pos', 'catalog', 'printing']);

        $a = app(SelfSignupService::class)->createPendingTrial($this->signupForm('rodsignupa', $pro));
        $b = app(SelfSignupService::class)->createPendingTrial($this->signupForm('rodsignupb', $starter));

        $this->assertSame(['product_screen_v2', 'purchase_workspace', 'purchasing_v2'], $this->on($a), 'purchasing in the plan: the purchase screens too');
        $this->assertSame(['product_screen_v2'], $this->on($b));
        $this->assertNull($a->fresh()->primary_business, 'the business is chosen later, so no business switch yet');
        $this->assertSame($others, $this->otherTenants());
    }

    public function test_a_tenant_made_in_master_starts_with_the_screens_of_the_plan_picked(): void
    {
        $others = $this->otherTenants();
        $plan = $this->plan(['pos', 'catalog', 'inventory', 'purchasing']);
        $admin = $this->admin();

        $this->actingAs($admin, 'central')->post($this->central('/tenants'), [
            'tenant_code' => 'rodmastera', 'business_name' => 'Rollout Master A', 'currency_code' => 'PKR', 'subdomain' => 'rodmastera', 'plan_id' => $plan->id,
        ])->assertRedirect();
        $this->actingAs($admin, 'central')->post($this->central('/tenants'), [
            'tenant_code' => 'rodmasterb', 'business_name' => 'Rollout Master B', 'currency_code' => 'PKR', 'subdomain' => 'rodmasterb',
        ])->assertRedirect();

        $this->assertSame(['product_screen_v2', 'purchase_workspace', 'purchasing_v2'], $this->on(Tenant::where('tenant_code', 'rodmastera')->firstOrFail()));
        $this->assertSame([], $this->on(Tenant::where('tenant_code', 'rodmasterb')->firstOrFail()), 'no plan, no switches');
        $this->assertSame($others, $this->otherTenants());
    }

    /* ── the business, set for the first time ────────────────────────────── */

    public function test_the_first_business_fills_only_switches_the_tenant_never_set(): void
    {
        $others = $this->otherTenants();
        $plan = $this->plan(['pos', 'catalog', 'inventory', 'purchasing']);
        $admin = $this->admin();

        // Packs explicitly OFF; everything else as a signup gets it.
        $shop = $this->tenantRow('rodbiza', $plan, ['product_screen_v2' => true, 'purchasing_v2' => true, 'purchase_workspace' => true, 'packs' => false]);
        $this->actingAs($admin, 'central')->put($this->central('/tenants/' . $shop->id . '/business'), ['primary_business' => 'mart', 'confirm_code' => 'rodbiza'])
            ->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, 'Switched on as this business\'s defaults: "Whole numbers for counted items in purchasing and stock".'));
        $f = $shop->fresh()->features;
        $this->assertTrue($f['whole_counted_stock']);
        $this->assertFalse($f['packs'], 'explicitly off stays off');
        $this->assertArrayNotHasKey('counter_packs', $f, 'a carton at the counter needs packs, which the tenant turned off');
        $row = DB::connection('master')->table('tenant_setting_changes')->where('tenant_id', $shop->id)->where('setting', 'feature:whole_counted_stock')->first();
        $this->assertSame(['off', 'on', 'default for Mart / Shop', $admin->id], [$row->old_value, $row->new_value, $row->note, (int) $row->changed_by_central_user_id]);

        // A later change of business leaves the switches alone.
        $this->actingAs($admin, 'central')->put($this->central('/tenants/' . $shop->id . '/business'), ['primary_business' => 'pharmacy', 'confirm_code' => 'rodbiza'])
            ->assertRedirect()->assertSessionHas('status', fn ($s) => ! str_contains($s, 'Switched on'));
        $this->assertSame($f, $shop->fresh()->features);
        $this->assertSame(1, DB::connection('master')->table('tenant_setting_changes')->where('tenant_id', $shop->id)->where('setting', 'like', 'feature:%')->count());

        // A tenant that never set anything (made before this policy): business switches only — packs need the new
        // product screen, which is not on, so neither packs nor the counter's carton come on.
        $old = $this->tenantRow('rodbizb', $plan, null);
        $this->actingAs($admin, 'central')->put($this->central('/tenants/' . $old->id . '/business'), ['primary_business' => 'mart', 'confirm_code' => 'rodbizb'])->assertRedirect();
        $this->assertSame(['whole_counted_stock'], $this->on($old));

        // A restaurant's first business adds nothing: whole numbers are not its policy.
        $kitchen = $this->tenantRow('rodbizc', $plan, ['product_screen_v2' => true]);
        $this->actingAs($admin, 'central')->put($this->central('/tenants/' . $kitchen->id . '/business'), ['primary_business' => 'restaurant', 'confirm_code' => 'rodbizc'])->assertRedirect();
        $this->assertSame(['product_screen_v2'], $this->on($kitchen));

        $this->assertSame($others, $this->otherTenants());
    }

    /* ── the public demos ────────────────────────────────────────────────── */

    public function test_every_demo_starts_with_its_business_defaults(): void
    {
        $plan = fn (string $code) => Plan::where('code', $code)->firstOrFail();
        $on = function (string $industry, string $code) use ($plan) {
            $keys = array_keys(DemoProvisioner::featuresFor($industry, $plan($code)));
            sort($keys);

            return $keys;
        };

        $this->assertSame(['product_screen_v2'], $on('retail', 'retail_starter'), 'no stock, no packs');
        $this->assertSame(['counter_packs', 'packs', 'product_screen_v2', 'purchase_workspace', 'purchasing_v2', 'whole_counted_stock'], $on('inventory', 'inventory_store'));
        $this->assertSame(['product_screen_v2'], $on('restaurant', 'restaurant_starter'));
        $this->assertSame(['product_screen_v2', 'purchase_workspace', 'purchasing_v2'], $on('restaurant_pro', 'restaurant_pro'), 'a restaurant: no whole numbers');
        $this->assertSame(['product_screen_v2', 'purchase_workspace', 'purchasing_v2'], $on('enterprise', 'enterprise'));
        $this->assertSame(['product_screen_v2', 'purchase_workspace', 'purchasing_v2'], $on('finance', 'finance_erp'));
        $this->assertSame(['medical_counter', 'packs', 'product_screen_v2', 'purchase_workspace', 'purchasing_v2', 'return_no_bill', 'whole_counted_stock'], $on('pharmacy', 'pharmacy_demo'),
            'the pharmacy demo shows returns without a bill; a live pharmacy opts in');
    }

    public function test_the_nightly_reset_keeps_the_defaults_and_the_sample_data_seeds_with_them(): void
    {
        $others = $this->otherTenants();
        config(['saas.demos.tenant_codes' => ['inventory' => 'rodinv', 'pharmacy' => 'rodpharm'],
            'saas.demos.allowlist' => ['rodinv', 'rodpharm'], 'saas.demos.reset_tenant_codes' => ['rodinv', 'rodpharm']]);

        foreach (['inventory' => 'rodinv', 'pharmacy' => 'rodpharm'] as $industry => $code) {
            $expected = array_keys(DemoProvisioner::featuresFor($industry, Plan::where('code', $industry === 'pharmacy' ? 'pharmacy_demo' : 'inventory_store')->firstOrFail()));
            sort($expected);

            $tenant = app(DemoProvisioner::class)->provision($industry);
            $this->assertSame($expected, $this->on($tenant), "{$industry}: provisioned with its defaults");

            // A switch changed by hand does not survive the night: the reset recreates the demo with its defaults and
            // seeds its sample data with them on (resetTenantCode throws when the seeding fails).
            $tenant->update(['features' => ['product_screen_v2' => true]]);
            $reset = app(DemoResetService::class)->resetTenantCode($code);
            $this->assertSame($expected, $this->on($reset), "{$industry}: the reset put the defaults back");
            $this->assertTrue((bool) $reset->fresh()->is_demo);
        }

        $this->assertSame($others, $this->otherTenants());
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    /** @return list<string> the switches ON, sorted */
    private function on(Tenant $tenant): array
    {
        $keys = array_keys(array_filter($tenant->fresh()->features ?? []));
        sort($keys);

        return $keys;
    }

    /** Every tenant this class did not make, with its switches exactly as stored. */
    private function otherTenants(): array
    {
        return DB::connection('master')->table('tenants')->where('tenant_code', 'not like', 'rod%')->orderBy('id')->pluck('features', 'tenant_code')->all();
    }

    private function plan(array $modules): Plan
    {
        $plan = Plan::create(['code' => 'rodtest-' . uniqid(), 'name' => 'Rollout Test Plan', 'price' => 0, 'currency_code' => 'PKR',
            'billing_period' => 'monthly', 'is_active' => true, 'is_public' => true, 'is_custom' => false, 'trial_days' => 30]);
        foreach ($modules as $key) {
            PlanModule::create(['plan_id' => $plan->id, 'module_id' => Module::where('key', $key)->firstOrFail()->id, 'is_enabled' => true]);
        }

        return $plan->fresh();
    }

    private function tenantRow(string $code, Plan $plan, ?array $features): Tenant
    {
        $tenant = Tenant::create(['tenant_code' => $code, 'business_name' => 'Rollout ' . $code, 'currency_code' => 'PKR', 'status' => 'active', 'features' => $features]);
        DB::connection('master')->table('subscriptions')->insert(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);

        return $tenant->fresh();
    }

    private function signupForm(string $code, Plan $plan): array
    {
        return ['business_name' => 'Rollout Signup', 'tenant_code' => $code, 'owner_name' => 'Test Owner', 'owner_email' => $code . '@example.test', 'plan_id' => $plan->id];
    }

    private function admin(): CentralUser
    {
        $permissions = ['central.tenants.index', 'central.tenants.show', 'central.tenants.create', 'central.tenants.store', 'central.tenants.business.update'];
        $user = CentralUser::create(['name' => 'Rollout Admin', 'email' => 'rod' . uniqid() . '@example.test', 'password' => bcrypt('secret'), 'status' => 'active']);
        $this->userIds[] = $user->id;
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'central');
        }
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function central(string $path): string
    {
        return 'http://' . config('tenancy.central_domain') . $path;
    }

    /** Master rows and tenant databases this class made. Left behind they break later suites. */
    private function clean(): void
    {
        $m = DB::connection('master');
        $ids = $m->table('tenants')->where('tenant_code', 'like', 'rod%')->pluck('id');
        foreach (['tenant_setting_changes', 'tenant_databases', 'tenant_domains', 'subscriptions'] as $table) {
            $m->table($table)->whereIn('tenant_id', $ids)->delete();
        }
        $m->table('tenants')->whereIn('id', $ids)->delete();
        $planIds = $m->table('plans')->where('code', 'like', 'rodtest-%')->pluck('id');
        $m->table('plan_modules')->whereIn('plan_id', $planIds)->delete();
        $m->table('plans')->whereIn('id', $planIds)->delete();
        foreach ($m->select("SHOW DATABASES LIKE 'pos\\_tenant\\_rod%'") as $row) {
            $m->statement('DROP DATABASE IF EXISTS `' . array_values((array) $row)[0] . '`');
        }
    }
}
