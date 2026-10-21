<?php

namespace Tests\MySql;

use App\Models\Master\CentralUser;
use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanFeature;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Models\Master\TenantDatabase;
use App\Services\Central\SystemUsageService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * MASTER-UNIVERSE-1 — Bingoo Master: the Universe dashboard, the usage snapshot, the tenant hub (tabs,
 * business change, switches, branches), the module boxes and the tenant list — through the real route →
 * middleware → controller → Blade, as a logged-in central admin. Tenant data must never change except
 * for the one branch the owner adds on purpose.
 */
class MasterUniverseMySqlTest extends MySqlTenantTestCase
{
    private const LIVE = 'muhublive';
    private const BARE = 'muhubbare';
    private const MODULE = 'mu_widgets';

    private string $host;

    /** @var list<int> */
    private array $userIds = [];

    private Tenant $live;
    private Tenant $bare;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('master');
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = 'http://' . config('tenancy.central_domain');

        $this->cleanMaster();
        $this->cleanTenant(['sales_orders', 'branch_user', 'terminals', 'users', 'branches', 'recipes', 'products']);
        $this->seedMaster();
        $this->seedTenantData();
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanMaster();
            foreach ($this->userIds as $id) {
                DB::connection('master')->table('model_has_permissions')->where('model_id', $id)
                    ->where('model_type', CentralUser::class)->delete();
                DB::connection('master')->table('central_users')->where('id', $id)->delete();
            }
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    /* ── fixtures ──────────────────────────────────────────────────────────────────────────────── */

    private function cleanMaster(): void
    {
        $m = DB::connection('master');
        $ids = $m->table('tenants')->whereIn('tenant_code', [self::LIVE, self::BARE])->pluck('id');
        $m->table('system_usage_snapshots')->delete();
        $m->table('tenant_setting_changes')->whereIn('tenant_id', $ids)->delete();
        $m->table('subscription_invoices')->whereIn('tenant_id', $ids)->delete();
        $m->table('tenant_backups')->whereIn('tenant_id', $ids)->delete();
        $m->table('tenant_domains')->whereIn('tenant_id', $ids)->delete();
        $m->table('tenant_databases')->whereIn('tenant_id', $ids)->delete();
        $m->table('subscriptions')->whereIn('tenant_id', $ids)->delete();
        $m->table('tenants')->whereIn('id', $ids)->delete();
        $planIds = $m->table('plans')->where('code', 'like', 'mu-plan-%')->pluck('id');
        $m->table('plan_modules')->whereIn('plan_id', $planIds)->delete();
        $m->table('plan_features')->whereIn('plan_id', $planIds)->delete();
        $m->table('plans')->whereIn('id', $planIds)->delete();
        $m->table('route_catalogs')->where('route_name', 'like', 'tenant.mu-widgets.%')->delete();
        $m->table('modules')->where('key', self::MODULE)->delete();
    }

    private function seedMaster(): void
    {
        $pos = Module::firstOrCreate(['key' => 'pos'], [
            'name' => 'POS', 'category' => 'Sales', 'description' => 'POS',
            'route_module_keys' => ['tenant.pos'], 'sort_order' => 10, 'is_core' => true, 'is_active' => true,
        ]);
        $widgets = Module::create([
            'key' => self::MODULE, 'name' => 'Widget Studio', 'category' => 'Test Shelf',
            'description' => 'Widgets for the test.', 'route_module_keys' => ['tenant.mu-widgets'],
            'sort_order' => 999, 'is_core' => false, 'is_active' => true,
        ]);
        foreach ([['tenant.mu-widgets.index', 'mu-widgets', 'GET|HEAD'], ['tenant.mu-widgets.create', 'mu-widgets/create', 'GET|HEAD'],
                  ['tenant.mu-widgets.store', 'mu-widgets', 'POST']] as [$name, $uri, $method]) {
            DB::connection('master')->table('route_catalogs')->insert([
                'route_name' => $name, 'uri' => $uri, 'method' => $method, 'module_key' => 'tenant.mu-widgets',
                'action_key' => substr($name, strrpos($name, '.') + 1), 'is_published' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->plan = Plan::create(['code' => 'mu-plan-' . uniqid(), 'name' => 'Universe Plan', 'price' => 5000, 'is_active' => true]);
        PlanModule::create(['plan_id' => $this->plan->id, 'module_id' => $pos->id, 'is_enabled' => true]);
        PlanModule::create(['plan_id' => $this->plan->id, 'module_id' => $widgets->id, 'is_enabled' => true]);
        PlanFeature::create(['plan_id' => $this->plan->id, 'feature_key' => 'branch_limit', 'feature_value' => '3']);

        $this->live = Tenant::create([
            'tenant_code' => self::LIVE, 'business_name' => 'Universe Live Kitchen', 'owner_name' => 'Owner',
            'owner_email' => 'owner@muhub.test', 'currency_code' => 'PKR', 'status' => 'active',
            'primary_business' => 'restaurant',
        ]);
        Subscription::create(['tenant_id' => $this->live->id, 'plan_id' => $this->plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);
        $c = config('database.connections.tenant');
        TenantDatabase::create([
            'tenant_id' => $this->live->id, 'db_host' => $c['host'], 'db_port' => $c['port'], 'db_database' => $this->tenantDb,
            'db_username' => $c['username'], 'db_password' => $c['password'] ?? '', 'migration_status' => 'completed',
        ]);
        DB::connection('master')->table('tenant_domains')->insert([
            'tenant_id' => $this->live->id, 'domain' => 'muhublive.' . config('tenancy.tenant_base_domain'), 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // A second, not-yet-active domain so every domain action shows.
        DB::connection('master')->table('tenant_domains')->insert([
            'tenant_id' => $this->live->id, 'domain' => 'muhub-two.' . config('tenancy.tenant_base_domain'), 'is_primary' => 0,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('master')->table('subscription_invoices')->insert([
            'invoice_no' => 'MU-INV-' . uniqid(), 'tenant_id' => $this->live->id, 'plan_id' => $this->plan->id,
            'status' => 'issued', 'currency_code' => 'PKR', 'subtotal' => 5000, 'total_amount' => 5000, 'balance_amount' => 5000,
            'due_date' => now()->subDays(5)->toDateString(), 'issued_at' => now()->subDays(20), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A tenant that was created but never provisioned, and has no business type yet.
        $this->bare = Tenant::create([
            'tenant_code' => self::BARE, 'business_name' => 'Universe Waiting Shop', 'currency_code' => 'PKR', 'status' => 'pending',
        ]);
    }

    private function seedTenantData(): void
    {
        $t = DB::connection('tenant');
        $branchId = $t->table('branches')->insertGetId(['code' => 'MAIN', 'name' => 'Main Branch', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $t->table('terminals')->insert(['branch_id' => $branchId, 'code' => 'T-MU-1', 'name' => 'Counter 1', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['a', 'b'] as $u) {
            $uid = $t->table('users')->insertGetId(['name' => 'Staff ' . $u, 'email' => "staff{$u}@muhub.test", 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
            $t->table('branch_user')->insert(['branch_id' => $branchId, 'user_id' => $uid]);
        }
        foreach (['Tea', 'Samosa', 'Sugar'] as $i => $name) {
            $t->table('products')->insert([
                'sku' => 'MU-' . $i, 'name' => $name, 'slug' => 'mu-' . $i,
                'product_kind' => $name === 'Sugar' ? 'raw_material' : 'finished_good', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $sale = fn (string $no, string $status, $when, float $total) => $t->table('sales_orders')->insert([
            'sale_no' => $no, 'branch_id' => $branchId, 'sale_date' => $when, 'business_date' => \Illuminate\Support\Carbon::parse($when)->toDateString(),
            'status' => $status, 'grand_total' => $total, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sale('MU-S1', 'paid', now()->subDays(2), 1000);
        $sale('MU-S2', 'paid', now()->subDays(10), 500);
        $sale('MU-S3', 'draft', now()->subDay(), 900);        // not a sale
        $sale('MU-S4', 'paid', now()->subDays(40), 700);      // older than 30 days
    }

    private function admin(?array $permissions = null): CentralUser
    {
        $permissions ??= [
            'central.dashboard', 'central.system-usage.refresh',
            'central.tenants.index', 'central.tenants.show', 'central.tenants.edit', 'central.tenants.create',
            'central.tenants.backup', 'central.tenants.backups', 'central.tenants.backup-settings.save', 'central.tenants.sync',
            'central.tenants.reset', 'central.tenants.provision', 'central.tenants.activate', 'central.tenants.suspend', 'central.tenants.cancel',
            'central.tenants.subscription.update', 'central.tenants.business.impact', 'central.tenants.business.update',
            'central.tenants.features.update', 'central.tenants.branches.store',
            'central.tenant-domains.store', 'central.tenant-domains.primary', 'central.tenant-domains.activate',
            'central.tenant-domains.deactivate', 'central.tenant-domains.destroy', 'central.tenant-backups.download',
            'central.invoices.index', 'central.tenants.invoices.create',
            'central.modules.index', 'central.modules.edit', 'central.modules.update', 'central.plans.index', 'central.plans.edit',
        ];
        $user = CentralUser::create([
            'name' => 'Universe Admin ' . count($this->userIds), 'email' => 'mu' . uniqid() . '@example.test',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
        $this->userIds[] = $user->id;
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'central');
        }
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** Row counts that must NOT move when Master only reads or changes a master setting. */
    private function tenantFingerprint(): array
    {
        DB::purge('tenant');
        config(['database.connections.tenant.database' => $this->tenantDb]);

        return collect(['products', 'sales_orders', 'branches', 'users', 'terminals'])
            ->mapWithKeys(fn ($table) => [$table => DB::connection('tenant')->table($table)->count()])->all();
    }

    private function snapshotRow(Tenant $tenant): object
    {
        $row = DB::connection('master')->table('system_usage_snapshots')->where('tenant_id', $tenant->id)->orderByDesc('id')->first();
        $this->assertNotNull($row, 'a snapshot row for ' . $tenant->tenant_code);
        $row->meta = json_decode($row->meta, true);

        return $row;
    }

    /* ── 1. snapshot ───────────────────────────────────────────────────────────────────────────── */

    public function test_the_snapshot_command_records_honest_numbers_and_only_reads_tenants(): void
    {
        $before = $this->tenantFingerprint();

        $this->assertSame(0, Artisan::call('system:usage-snapshot'));

        $live = $this->snapshotRow($this->live);
        $this->assertSame(2, (int) $live->sales_30d, 'paid bills of the last 30 days only (no draft, nothing older)');
        $this->assertEqualsWithDelta(1500.0, $live->meta['sales_30d_amount'], 0.001);
        $this->assertSame(3, (int) $live->products);
        $this->assertSame(2, (int) $live->users);
        $this->assertSame(1, $live->meta['branches']);
        $this->assertGreaterThan(0, (int) $live->db_bytes, 'the tenant database has a size');
        $this->assertNull($live->cpu_load_1, 'per-tenant CPU is never invented');

        $bare = $this->snapshotRow($this->bare);
        $this->assertNull($bare->db_bytes, 'unknown stays NULL, never 0');
        $this->assertNull($bare->sales_30d);
        $this->assertStringContainsString('No database yet', $bare->meta['error']);

        $server = DB::connection('master')->table('system_usage_snapshots')->whereNull('tenant_id')->first();
        $this->assertNotNull($server, 'one row for the whole server');
        $this->assertGreaterThan(0, (int) $server->disk_total_bytes);
        $this->assertGreaterThanOrEqual((int) $live->db_bytes, (int) $server->db_bytes, 'MySQL total includes the tenant database');
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertNull($server->ram_total_kb, 'no /proc on Windows: RAM is unknown, not zero');
        }

        $this->assertSame($before, $this->tenantFingerprint(), 'the snapshot only reads tenant data');
    }

    public function test_the_forecast_waits_for_two_snapshots_a_day_apart(): void
    {
        $usage = app(SystemUsageService::class);
        // Only this test's rows: an earlier test's real snapshot (taken now, real disk) would shift the arithmetic.
        DB::connection('master')->table('system_usage_snapshots')->delete();
        $row = fn ($at, $free) => DB::connection('master')->table('system_usage_snapshots')->insert([
            'taken_at' => $at, 'tenant_id' => null, 'disk_total_bytes' => 100 * 1024 ** 3, 'disk_free_bytes' => $free,
            'meta' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row(now()->subHours(3), 40 * 1024 ** 3);
        $this->assertSame('collecting', $usage->forecast()['state']);
        $this->assertStringContainsString('first forecast after 2 daily snapshots', $usage->forecast()['text']);

        // Two days earlier there was 2 GB more free: 1 GB a day → 40 days left.
        $row(now()->subHours(3)->subDays(2), 42 * 1024 ** 3);
        $f = $usage->forecast();
        $this->assertSame('ok', $f['state']);
        $this->assertSame(40, $f['days']);
        $this->assertStringContainsString('~40 days', $f['text']);
    }

    /* ── 2. dashboard ──────────────────────────────────────────────────────────────────────────── */

    public function test_the_universe_dashboard_shows_server_tenants_alerts_and_modules(): void
    {
        $admin = $this->admin();

        // Before any reading: honest empty state, not zeros.
        $html = $this->actingAs($admin, 'central')->get($this->host . '/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('Bingoo Universe', $html);
        $this->assertStringContainsString('No reading yet', $html);

        $this->actingAs($admin, 'central')->post($this->host . '/system-usage/refresh')
            ->assertRedirect(route('central.dashboard'));
        $this->assertSame(1, DB::connection('master')->table('system_usage_snapshots')->whereNull('tenant_id')->count());
        // A CLI process has no time limit; "Refresh now" must not arm one (it once killed a whole test run at 300 s).
        $this->assertSame('0', (string) ini_get('max_execution_time'));

        $html = $this->actingAs($admin, 'central')->get($this->host . '/dashboard')->assertOk()->getContent();
        // Theme: only here, on a central page.
        $this->assertMatchesRegularExpression('/<body class="[^"]*\bmaster-ui\b[^"]*" data-bs-theme="dark">/', $html);
        $this->assertStringContainsString('assets/css/master-theme.css', $html);
        // Server card + forecast wording.
        $this->assertStringContainsString('Disk forecast', $html);
        $this->assertStringContainsString('Collecting data — first forecast after 2 daily snapshots.', $html);
        // Tenant cards: picture, name, business, plan, numbers; the unset one in red words.
        $this->assertStringContainsString('Universe Live Kitchen', $html);
        $this->assertStringContainsString('assets/img/business/restaurant.png', $html);
        $this->assertStringContainsString('Universe Plan', $html);
        $this->assertStringContainsString('2 bills', $html);
        $this->assertStringContainsString('PKR 5,000', $html, 'invoices due on the card');
        $this->assertStringContainsString('Universe Waiting Shop', $html);
        $this->assertStringContainsString('Business: Not set', $html);
        $this->assertStringContainsString(url('/tenants/' . $this->live->id), $html, 'a card opens the tenant hub');
        // Alerts.
        $this->assertStringContainsString('without a business type', $html);
        $this->assertStringContainsString('with overdue invoices', $html);
        $this->assertStringContainsString('without a backup in 2 days', $html, 'a live tenant with no backup is flagged');
        // Modules strip.
        $this->assertStringContainsString('Widget Studio', $html);
        $this->assertStringContainsString('2 screens · 1 plan', $html);
        $this->assertStringContainsString('Activity share is an estimate', $html);
    }

    public function test_refresh_now_needs_its_own_permission(): void
    {
        $viewer = $this->admin(['central.dashboard']);
        $html = $this->actingAs($viewer, 'central')->get($this->host . '/dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString('Refresh now</span>', $html);

        $this->actingAs($viewer, 'central')->post($this->host . '/system-usage/refresh')->assertForbidden();
        $this->assertSame(0, DB::connection('master')->table('system_usage_snapshots')->count());
    }

    /* ── 3. tenant hub ─────────────────────────────────────────────────────────────────────────── */

    public function test_the_tenant_hub_has_every_tab_and_keeps_every_old_action(): void
    {
        $admin = $this->admin();
        Artisan::call('system:usage-snapshot');

        $html = $this->actingAs($admin, 'central')->get($this->host . '/tenants/' . $this->live->id)->assertOk()->getContent();

        foreach (['Overview', 'Business &amp; switches', 'Modules', 'Branches', 'Billing &amp; invoices', 'Backups', 'Domains &amp; database', 'Danger zone'] as $tab) {
            $this->assertStringContainsString($tab, $html, "tab {$tab}");
        }
        // Everything the old page had.
        foreach (['Tenant information', 'owner@muhub.test', 'Update Subscription', 'Create Invoice', 'MU-INV-',
                     'Add Domain', 'Add Website Domain', 'muhublive.', 'Make Primary', 'Migration Status', $this->tenantDb,
                     'Suspend Tenant', 'Cancel Tenant', 'Backup now', 'Sync', 'Automatic backups', 'Keep (days)'] as $needle) {
            $this->assertStringContainsString($needle, $html, "hub keeps: {$needle}");
        }
        // Business: five pictures, current marked, catering blocked by the plan.
        foreach (['Restaurant', 'Catering', 'Medical Store', 'Mart / Shop', 'Industry'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertMatchesRegularExpression('/data-biz="restaurant"\s+aria-current="true"/', $html);
        $this->assertStringContainsString('The plan has no catering module. Change the plan first.', $html);
        // The confirm panel fetches the impact first (URL handed to the script, JSON-escaped).
        $this->assertStringContainsString(str_replace('/', '\/', route('central.tenants.business.impact', $this->live)), $html);
        // Switches.
        $this->assertStringContainsString('New product screen', $html);
        $this->assertStringContainsString('name="features[product_screen_v2]"', $html);
        // MASTER-NEW-SCREENS-1: each switch shows pictures of the screens it opens (a click = full size).
        $this->assertStringContainsString('assets/img/screens/medical-counter.jpg', $html);
        $this->assertStringContainsString('alt="Prescription popup"', $html);
        $this->assertStringContainsString('assets/img/screens/grn-entry.jpg', $html);
        // Modules tab: on/off from the plan, counts, plain sentence.
        $this->assertStringContainsString('Widget Studio', $html);
        $this->assertStringContainsString('2 screens · 3 permissions', $html);
        $this->assertStringContainsString('Turning this on gives these screens in the sidebar:', $html);
        $this->assertStringContainsString('Mu widgets', $html);
        // Branches tab: read live from the tenant database.
        $this->assertStringContainsString('Main Branch', $html);
        $this->assertStringContainsString('1 of 3 active branches used', $html);
        $this->assertStringContainsString(route('central.tenants.branches.store', $this->live), $html);
        // Overview numbers from the snapshot.
        $this->assertStringContainsString('2 bills', $html);
        // The provision card is only for tenants without a working database.
        $this->assertStringNotContainsString('Provision &amp; Activate', $html);

        $bare = $this->actingAs($admin, 'central')->get($this->host . '/tenants/' . $this->bare->id)->assertOk()->getContent();
        $this->assertStringContainsString('Provision &amp; Activate', $bare);
        $this->assertStringContainsString('Activate Tenant', $bare);
        $this->assertStringContainsString('data-default-tab="domains"', $bare, 'an unprovisioned tenant opens on Domains & database');
        $this->assertStringContainsString('No database yet', $bare);
    }

    public function test_impact_reads_what_the_tenant_has(): void
    {
        $admin = $this->admin();
        $json = $this->actingAs($admin, 'central')->getJson($this->host . '/tenants/' . $this->live->id . '/business/impact')
            ->assertOk()->json();

        $this->assertTrue($json['ok']);
        $this->assertSame(4, $json['sales'], 'all sales bills, any status');
        $this->assertSame(1, $json['products']['Ingredients / raw material']);
        $this->assertSame('restaurant', $json['current']);
        $this->assertContains('pos', $json['plan_modules']);
    }

    public function test_a_business_the_plan_cannot_carry_is_refused(): void
    {
        $admin = $this->admin();
        $before = $this->tenantFingerprint();

        $this->actingAs($admin, 'central')->put($this->host . '/tenants/' . $this->live->id . '/business', [
            'primary_business' => 'catering', 'confirm_code' => self::LIVE,
        ])->assertSessionHasErrors('primary_business');

        $this->assertSame('restaurant', $this->live->fresh()->primary_business);
        $this->assertSame(0, DB::connection('master')->table('tenant_setting_changes')->where('tenant_id', $this->live->id)->count());
        $this->assertSame($before, $this->tenantFingerprint());
    }

    public function test_the_wrong_tenant_code_is_refused(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'central')->put($this->host . '/tenants/' . $this->live->id . '/business', [
            'primary_business' => 'mart', 'confirm_code' => 'someoneelse',
        ])->assertSessionHasErrors('confirm_code');

        $this->assertSame('restaurant', $this->live->fresh()->primary_business);
        $this->assertSame(0, DB::connection('master')->table('tenant_setting_changes')->where('tenant_id', $this->live->id)->count());
    }

    public function test_a_business_change_is_recorded_and_tenant_data_is_untouched(): void
    {
        $admin = $this->admin();
        $before = $this->tenantFingerprint();

        $this->actingAs($admin, 'central')->put($this->host . '/tenants/' . $this->live->id . '/business', [
            'primary_business' => 'mart', 'confirm_code' => strtoupper(self::LIVE), 'note' => 'Owner asked',
        ])->assertRedirect('/tenants/' . $this->live->id . '#business');

        $this->assertSame('mart', $this->live->fresh()->primary_business);
        $row = DB::connection('master')->table('tenant_setting_changes')->where('tenant_id', $this->live->id)->first();
        $this->assertSame('primary_business', $row->setting);
        $this->assertSame('restaurant', $row->old_value);
        $this->assertSame('mart', $row->new_value);
        $this->assertSame($admin->id, (int) $row->changed_by_central_user_id);
        $this->assertSame($before, $this->tenantFingerprint(), 'products, sales, branches, users, terminals unchanged');

        $html = $this->actingAs($admin, 'central')->get($this->host . '/tenants/' . $this->live->id)->assertOk()->getContent();
        $this->assertStringContainsString('Restaurant → <b>Mart / Shop</b>', $html, 'history in plain words');
        $this->assertStringContainsString('Owner asked', $html);
        $this->assertStringContainsString('by ' . $admin->name, $html);
    }

    public function test_switches_turn_on_and_off_with_history(): void
    {
        $admin = $this->admin();
        $url = $this->host . '/tenants/' . $this->live->id . '/features';

        $this->actingAs($admin, 'central')->put($url, ['features' => ['product_screen_v2' => '1']])
            ->assertRedirect('/tenants/' . $this->live->id . '#features');
        $this->assertTrue($this->live->fresh()->features['product_screen_v2']);

        // The hub's form sends a hidden 0 before the checkbox; unticked → only the 0 arrives.
        $this->actingAs($admin, 'central')->put($url, ['features' => ['product_screen_v2' => '0']]);
        $this->assertFalse($this->live->fresh()->features['product_screen_v2']);

        $this->assertSame(['off→on', 'on→off'], DB::connection('master')->table('tenant_setting_changes')
            ->where('tenant_id', $this->live->id)->where('setting', 'feature:product_screen_v2')->orderBy('id')
            ->get()->map(fn ($r) => $r->old_value . '→' . $r->new_value)->all());
    }

    public function test_a_branch_added_from_master_is_the_same_row_the_tenant_screen_makes(): void
    {
        $admin = $this->admin();
        $url = $this->host . '/tenants/' . $this->live->id . '/branches';

        $this->actingAs($admin, 'central')->post($url, [
            'name' => 'North Branch', 'code' => 'NB', 'address' => 'Block 1', 'business_type' => 'restaurant', 'timezone' => 'Asia/Karachi',
        ])->assertRedirect('/tenants/' . $this->live->id . '#branches')->assertSessionHasNoErrors();

        $b = DB::connection('tenant')->table('branches')->where('code', 'NB')->first();
        $this->assertNotNull($b);
        $this->assertSame('North Branch', $b->name);
        $this->assertSame('active', $b->status);
        $this->assertSame('manager_required', $b->held_kot_cancellation_approval_mode);
        $this->assertSame('manager_required', $b->manual_discount_approval_mode);
        $this->assertSame('auto_approve', $b->sales_return_approval_mode);
        $this->assertSame(0, (int) $b->allow_negative_stock);
        $this->assertSame(0, DB::connection('tenant')->table('terminals')->where('branch_id', $b->id)->count(), 'no terminal is invented');
        $this->assertSame('North Branch (NB)', DB::connection('master')->table('tenant_setting_changes')
            ->where('tenant_id', $this->live->id)->where('setting', 'branch:add')->value('new_value'));

        // Same code again → refused with the reason (the plan still has room, so it is the code check).
        $this->actingAs($admin, 'central')->post($url, ['name' => 'Another', 'code' => 'NB', 'business_type' => 'store'])
            ->assertSessionHasErrors(['branch' => 'Branch code "NB" is already used by this tenant.']);
        // Same name → refused too.
        $this->actingAs($admin, 'central')->post($url, ['name' => 'north branch', 'business_type' => 'store'])
            ->assertSessionHasErrors(['branch' => 'A branch named "north branch" already exists.']);

        // The plan allows 3 branches: the third fits, the fourth is refused by the plan and nothing is written.
        $this->actingAs($admin, 'central')->post($url, ['name' => 'Third Branch', 'business_type' => 'store'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin, 'central')->post($url, ['name' => 'Fourth Branch', 'business_type' => 'store'])
            ->assertSessionHasErrors(['branch' => 'Your current plan allows only 3 branch. Please upgrade your plan to add more branches.']);
        $this->assertSame(3, DB::connection('tenant')->table('branches')->count());

        // Without the permission: forbidden.
        $viewer = $this->admin(['central.tenants.show']);
        $this->actingAs($viewer, 'central')->post($url, ['name' => 'Sneaky', 'business_type' => 'store'])->assertForbidden();
    }

    /* ── 4. modules + tenant list ──────────────────────────────────────────────────────────────── */

    public function test_modules_are_big_boxes_with_counts_and_the_technical_part_is_folded(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin, 'central')->get($this->host . '/modules')->assertOk()->getContent();
        $this->assertStringContainsString('Test Shelf', $html, 'grouped by category');
        $this->assertStringContainsString('Widget Studio', $html);
        $this->assertStringContainsString('Widgets for the test.', $html);
        $this->assertStringContainsString('2 screens · 3 permissions', $html);
        $this->assertMatchesRegularExpression('/<span>1 plan<\/span>\s*<span>1 tenant<\/span>/', $html);
        // MASTER-NEW-SCREENS-1: modules show their newer screens, each with the switch that shows it.
        $this->assertStringContainsString('Newer screens', $html);
        $this->assertStringContainsString('assets/img/screens/sales-return.jpg', $html);
        $this->assertStringContainsString('Switch: Pack &amp; batch counter (medical store, mart)', $html);
        foreach (\App\Services\Central\ModuleCatalogService::NEWER_SCREENS as $key => $list) {
            foreach ($list as [$slug]) {
                $this->assertFileExists(public_path('assets/img/screens/' . $slug . '.jpg'), "the picture of {$key} → {$slug} is in the repo");
            }
        }

        $module = Module::where('key', self::MODULE)->first();
        $edit = $this->actingAs($admin, 'central')->get($this->host . '/modules/' . $module->id . '/edit')->assertOk()->getContent();
        $this->assertStringContainsString('Technical details (for developers)', $edit);
        $this->assertMatchesRegularExpression('/id="routeMappingPanel" class="accordion-collapse collapse"/', $edit, 'folded by default');
        $this->assertStringContainsString('name="route_module_keys_text"', $edit);

        // Saving the form still keeps the route keys (the textarea is inside the folded part, still submitted).
        $this->actingAs($admin, 'central')->put($this->host . '/modules/' . $module->id, [
            'name' => 'Widget Studio', 'category' => 'Test Shelf', 'description' => 'Widgets for the test.',
            'route_module_keys_text' => "tenant.mu-widgets", 'sort_order' => 999, 'is_active' => 1,
        ])->assertRedirect('/modules');
        $this->assertSame(['tenant.mu-widgets'], $module->fresh()->route_module_keys);
    }

    public function test_the_tenant_list_shows_the_business_and_filters_by_it(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin, 'central')->get($this->host . '/tenants?search=muhub')->assertOk()->getContent();
        $this->assertStringContainsString('Business type', $html);
        $this->assertStringContainsString('assets/img/business/restaurant.png', $html);
        $this->assertStringContainsString('Not set', $html);

        $none = $this->actingAs($admin, 'central')->get($this->host . '/tenants?search=muhub&business=none')->assertOk()->getContent();
        $this->assertStringContainsString(self::BARE, $none);
        $this->assertStringNotContainsString('Universe Live Kitchen', $none);

        $rest = $this->actingAs($admin, 'central')->get($this->host . '/tenants?search=muhub&business=restaurant')->assertOk()->getContent();
        $this->assertStringContainsString('Universe Live Kitchen', $rest);
        $this->assertStringNotContainsString('Universe Waiting Shop', $rest);
    }

    /* ── 5. theme stays on Master; permissions survive deploy ─────────────────────────────────── */

    public function test_the_master_theme_never_reaches_a_tenant_page(): void
    {
        $render = function (string $routeName) {
            $route = new RoutingRoute(['GET'], '/x', fn () => null);
            $route->name($routeName);
            request()->setRouteResolver(fn () => $route);

            return view('layouts.app')->render();
        };

        $tenant = $render('tenant.dashboard');
        $this->assertStringNotContainsString('master-ui', $tenant);
        $this->assertStringNotContainsString('master-theme.css', $tenant);
        $this->assertStringNotContainsString('data-bs-theme', $tenant);
        $this->assertMatchesRegularExpression('/<body class="[^"]*">/', $tenant, 'the body tag is exactly as before');

        $central = $render('central.dashboard');
        $this->assertStringContainsString('master-theme.css', $central);
        $this->assertMatchesRegularExpression('/<body class="master-ui" data-bs-theme="dark">/', $central);

        // Every rule in the stylesheet is scoped to body.master-ui.
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(public_path('assets/css/master-theme.css')));
        preg_match_all('/([^{}]+)\{/', $css, $m);
        $selectors = 0;
        foreach ($m[1] as $prelude) {
            $prelude = trim($prelude);
            if ($prelude === '' || str_starts_with($prelude, '@')) {
                continue; // @media / @supports wrappers
            }
            foreach (explode(',', $prelude) as $sel) {
                $selectors++;
                $this->assertStringStartsWith('body.master-ui', trim($sel), 'unscoped selector: ' . trim($sel));
            }
        }
        $this->assertGreaterThan(100, $selectors, 'the parser really saw the stylesheet');
    }

    public function test_every_central_route_is_a_super_admin_permission(): void
    {
        // deploy.sh runs MasterSeeder, whose syncPermissions() takes away every central permission not in its list.
        $seeder = file_get_contents(database_path('seeders/MasterSeeder.php'));
        $missing = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->getName())
            ->filter(fn ($n) => $n && str_starts_with($n, 'central.') && ! preg_match('/^central\.(login|logout|password|locale)/', $n))
            ->reject(fn ($n) => str_contains($seeder, "'{$n}'"))
            ->values()->all();
        $this->assertSame([], $missing);
        $this->assertStringContainsString("'central.system-usage.refresh'", $seeder);
        $this->assertStringContainsString("'central.tenants.branches.store'", $seeder);
    }

    public function test_the_snapshot_is_scheduled_daily_on_cloud(): void
    {
        $console = file_get_contents(base_path('routes/console.php'));
        $this->assertMatchesRegularExpression("/Schedule::command\('system:usage-snapshot'\)\s*->timezone\('Asia\/Karachi'\)->dailyAt\('09:45'\)/", $console);
    }
}
