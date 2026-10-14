<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Reports\PartnerShareController;
use App\Http\Controllers\Tenant\Reports\SalesReportCenterController;
use App\Models\Tenant\SharePartner;
use App\Models\Tenant\ShareRate;
use App\Models\Tenant\User;
use App\Services\Printing\EscPosPayloadService;
use App\Services\Reports\SalesReportEngine;
use App\Services\Reports\SalesReportExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * PARTNER-SHARE-1 — one dish, two kitchens.
 *
 * A partner kitchen and the restaurant owe each other a FIXED amount per shared dish sold. The
 * Report Center's Partner Share section multiplies the rate list by the same sales every other
 * section counts. These pin the rules the owner agreed: a deal is rated as the deal and never again
 * through its parts; the rate is the one in force on the order's day; a return comes off at the rate
 * of the sale it reverses; a dish given away owes nothing unless the partner counts free lines.
 */
class PartnerShareMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private string $day;
    private int $sandwich;
    private int $roll;
    private int $platter;
    private int $partnerId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'share_rates', 'share_partners', 'sales_return_lines', 'sales_returns', 'sales_order_lines',
            'sales_orders', 'combo_components', 'combos', 'products', 'categories', 'model_has_permissions',
            'branches', 'users',
        ]);
        view()->share('errors', new ViewErrorBag);

        $this->branchId = $this->makeBranch(['name' => 'Main']);
        $this->day = app(\App\Support\TenantClock::class)->currentBusinessDate(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId));
        $cat = $this->makeCategory();
        $this->sandwich = $this->makeProduct($cat, ['name' => 'BBQ Sandwich', 'default_selling_price' => 800]);
        $this->roll = $this->makeProduct($cat, ['name' => 'Crispy Roll', 'default_selling_price' => 500]);
        $this->platter = (int) DB::connection('tenant')->table('combos')->insertGetId([
            'branch_id' => $this->branchId, 'code' => 'PL-' . Str::upper(Str::random(5)), 'name' => 'Grill Platter',
            'price' => 5500, 'sort_order' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->partnerId = SharePartner::create(['name' => 'Partner A', 'is_active' => true])->id;
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant');
        try {
            $m = DB::connection('master');
            $ids = $m->table('tenants')->where('tenant_code', 'like', 'pstest%')->pluck('id');
            $m->table('tenant_databases')->whereIn('tenant_id', $ids)->delete();
            $m->table('subscriptions')->whereIn('tenant_id', $ids)->delete();
            $m->table('tenants')->whereIn('id', $ids)->delete();
            $planIds = $m->table('plans')->where('code', 'like', 'pstest-%')->pluck('id');
            $m->table('plan_modules')->whereIn('plan_id', $planIds)->delete();
            $m->table('plans')->whereIn('id', $planIds)->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────────

    private function daysAgo(int $n): string
    {
        return date('Y-m-d', strtotime($this->day . " -{$n} day"));
    }

    private function rate(string $item, string $payer, float $amount, ?string $from = null, ?int $partnerId = null): void
    {
        [$t, $id] = explode(':', $item);
        ShareRate::create([
            'share_partner_id' => $partnerId ?? $this->partnerId, 'item_key' => $item,
            'product_id' => $t === 'p' ? (int) $id : null, 'combo_id' => $t === 'c' ? (int) $id : null,
            'payer' => $payer, 'amount' => $amount, 'effective_from' => $from ?? $this->daysAgo(30),
        ]);
    }

    /** One sale: lines = [[product_id, qty, line_total, combo_id?, line_kind?], …] */
    private function sale(array $lines, array $attrs = []): array
    {
        $id = $this->makeSale($this->branchId, $attrs + ['order_type' => 'dine_in']);
        $lineIds = [];
        foreach ($lines as $l) {
            $lineIds[] = $this->makeSaleLine($id, $l[0], [
                'quantity' => $l[1], 'unit_price' => $l[1] ? $l[2] / $l[1] : 0, 'line_total' => $l[2],
                'combo_id' => $l[3] ?? null, 'line_kind' => $l[4] ?? 'standard',
            ]);
        }

        return [$id, $lineIds];
    }

    private function share(?string $from = null, ?string $to = null, array $extra = []): array
    {
        $engine = app(SalesReportEngine::class);

        return $engine->partnerShare($engine->normalizeFilters(['date_from' => $from ?? $this->daysAgo(30), 'date_to' => $to ?? $this->day] + $extra));
    }

    private function block(array $share, string $name = 'Partner A'): array
    {
        return collect($share)->firstWhere('partner', $name) ?? $this->fail("no block for {$name}");
    }

    private function rows(array $side): array
    {
        return collect($side)->map(fn ($r) => [$r['item'], $r['rate'], $r['net_qty'], $r['amount']])->sortBy(fn ($r) => $r[0] . $r[1])->values()->all();
    }

    private function owner(array $permissions): User
    {
        $user = User::on('tenant')->find($this->makeUser());
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'tenant');
        }
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::on('tenant')->find($user->id);
        $this->actingAs($user, 'tenant');
        Auth::shouldUse('tenant');

        return $user;
    }

    // ── the money rules ────────────────────────────────────────────────────────────────────────

    public function test_a_deal_is_rated_as_the_deal_and_never_again_through_its_parts(): void
    {
        $this->rate('c:' . $this->platter, 'partner', 1000);
        $this->rate('p:' . $this->sandwich, 'us', 400);
        // 2 platters: header carries the money, the sandwich inside is a 0.00 component.
        $this->sale([[$this->sandwich, 2, 11000, $this->platter, 'combo_header'], [$this->sandwich, 2, 0, $this->platter, 'component']]);
        $this->sale([[$this->sandwich, 3, 2400]]);

        $b = $this->block($this->share());

        $this->assertSame([['Grill Platter', 1000.0, 2.0, 2000.0]], $this->rows($b['partner_pays']));
        $this->assertSame([['BBQ Sandwich', 400.0, 3.0, 1200.0]], $this->rows($b['we_pay']),
            'the sandwich inside the platter is not rated a second time');
        $this->assertSame(2000.0, $b['partner_pays_total']);
        $this->assertSame(1200.0, $b['we_pay_total']);
        $this->assertSame(-800.0, $b['net'], 'negative = the partner owes us');

        // A component is a 0.00 line, so the free-line rule alone would hide it. Counting free lines
        // must still never rate the sandwich inside the platter — that is the deal rule, on its own.
        SharePartner::whereKey($this->partnerId)->update(['count_free_lines' => true]);
        $this->assertSame([['BBQ Sandwich', 400.0, 3.0, 1200.0]], $this->rows($this->block($this->share())['we_pay']),
            'even counting free lines, a deal part is never a sale of its own');
    }

    public function test_the_rate_is_the_one_in_force_on_the_orders_day(): void
    {
        $this->rate('p:' . $this->sandwich, 'us', 400, $this->daysAgo(10));
        $this->rate('p:' . $this->sandwich, 'us', 500, $this->daysAgo(2));
        $this->sale([[$this->sandwich, 1, 800]], ['business_date' => $this->daysAgo(5)]);
        $this->sale([[$this->sandwich, 2, 1600]]);
        $this->sale([[$this->sandwich, 4, 3200]], ['business_date' => $this->daysAgo(20)]);   // before any rate

        $b = $this->block($this->share());

        $this->assertSame([['BBQ Sandwich', 400.0, 1.0, 400.0], ['BBQ Sandwich', 500.0, 2.0, 1000.0]], $this->rows($b['we_pay']),
            'a later rate never rewrites a day already sold; a sale before the first rate owes nothing');
        $this->assertSame(1400.0, $b['we_pay_total']);
    }

    public function test_a_return_comes_off_at_the_rate_of_the_sale_it_reverses(): void
    {
        $this->rate('p:' . $this->sandwich, 'us', 400, $this->daysAgo(10));
        $this->rate('p:' . $this->sandwich, 'us', 500, $this->daysAgo(2));
        [$saleId, [$lineId]] = $this->sale([[$this->sandwich, 3, 2400]], ['business_date' => $this->daysAgo(5)]);
        $returnId = DB::connection('tenant')->table('sales_returns')->insertGetId([
            'return_no' => 'RT-' . Str::upper(Str::random(8)), 'sales_order_id' => $saleId, 'branch_id' => $this->branchId,
            'return_date' => now(), 'business_date' => $this->day, 'subtotal' => 800, 'grand_total' => 800,
            'refund_amount' => 800, 'refund_method' => 'cash', 'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('sales_return_lines')->insert([
            'sales_return_id' => $returnId, 'sales_order_line_id' => $lineId, 'product_id' => $this->sandwich,
            'quantity' => 1, 'unit_price' => 800, 'line_total' => 800, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = $this->block($this->share())['we_pay'][0];

        $this->assertSame([3.0, 1.0, 2.0, 400.0, 800.0], [$row['sold_qty'], $row['returned_qty'], $row['net_qty'], $row['rate'], $row['amount']]);
    }

    public function test_a_dish_given_away_owes_nothing_unless_the_partner_counts_free_lines(): void
    {
        $this->rate('p:' . $this->roll, 'partner', 200);
        $this->sale([[$this->roll, 1, 500]]);
        $this->sale([[$this->roll, 1, 0]]);   // given free

        $this->assertSame(200.0, $this->block($this->share())['partner_pays_total']);

        SharePartner::whereKey($this->partnerId)->update(['count_free_lines' => true]);
        $this->assertSame(400.0, $this->block($this->share())['partner_pays_total']);
    }

    public function test_only_the_sales_the_report_counts(): void
    {
        $this->rate('p:' . $this->roll, 'partner', 200);
        $other = $this->makeBranch(['name' => 'Second']);
        $this->sale([[$this->roll, 1, 500]]);
        $this->sale([[$this->roll, 5, 2500]], ['status' => 'held']);                     // still running
        $this->makeSaleLine($this->makeSale($other, ['order_type' => 'dine_in']), $this->roll, ['quantity' => 2, 'line_total' => 1000]);

        $this->assertSame(600.0, $this->block($this->share())['partner_pays_total'], 'held is out, both branches in');
        $this->assertSame(200.0, $this->block($this->share(null, null, ['branch_ids' => [$this->branchId]]))['partner_pays_total']);
        $this->assertSame(0.0, $this->block($this->share($this->daysAgo(30), $this->daysAgo(1)))['partner_pays_total']);
    }

    public function test_each_active_partner_gets_its_own_block(): void
    {
        $b = SharePartner::create(['name' => 'Partner B', 'is_active' => true])->id;
        $off = SharePartner::create(['name' => 'Partner Off', 'is_active' => false])->id;
        $this->rate('p:' . $this->roll, 'partner', 200);
        $this->rate('p:' . $this->sandwich, 'us', 300, null, $b);
        $this->rate('p:' . $this->sandwich, 'us', 999, null, $off);
        $this->sale([[$this->roll, 1, 500], [$this->sandwich, 2, 1600]]);

        $share = $this->share();

        $this->assertSame(['Partner A', 'Partner B'], array_column($share, 'partner'), 'an inactive partner is left out');
        $this->assertSame(200.0, $this->block($share, 'Partner A')['partner_pays_total']);
        $this->assertSame(600.0, $this->block($share, 'Partner B')['we_pay_total']);
    }

    // ── Report Center ──────────────────────────────────────────────────────────────────────────

    public function test_the_section_appears_only_with_its_permission_and_a_rate_list(): void
    {
        $section = SalesReportCenterController::sectionPermission('partner_share');
        $this->assertSame('tenant.reports.center.sections.partner-share', $section);

        $this->owner([$section, 'tenant.reports.center.sections.overview']);
        $data = app(SalesReportCenterController::class)->index(Request::create('/reports/center', 'GET', ['tab' => 'partner_share']))->getData();
        $this->assertNotContains('partner_share', $data['allowedSections'], 'no rate list yet — no section');

        $this->rate('p:' . $this->roll, 'partner', 200);
        $this->sale([[$this->roll, 2, 1000]]);
        $data = app(SalesReportCenterController::class)->index(Request::create('/reports/center', 'GET', ['tab' => 'partner_share']))->getData();
        $this->assertContains('partner_share', $data['allowedSections']);
        $this->assertSame(400.0, $data['data']['partner_share'][0]['partner_pays_total']);

        $this->owner(['tenant.reports.center.sections.overview']);
        $data = app(SalesReportCenterController::class)->index(Request::create('/reports/center', 'GET', ['tab' => 'partner_share']))->getData();
        $this->assertNotContains('partner_share', $data['allowedSections'], 'without the permission the section is not offered');
        $this->assertArrayNotHasKey('partner_share', $data['data']);
    }

    public function test_the_screen_export_and_thermal_all_carry_it(): void
    {
        $this->rate('c:' . $this->platter, 'partner', 1000);
        $this->rate('p:' . $this->sandwich, 'us', 400);
        $this->sale([[$this->sandwich, 1, 5500, $this->platter, 'combo_header']]);
        $this->sale([[$this->sandwich, 3, 2400]]);
        $this->bindTenant();
        $this->owner([SalesReportCenterController::sectionPermission('partner_share'), 'tenant.reports.center.index', 'tenant.reports.partner-share.index']);

        $html = app(SalesReportCenterController::class)->index(Request::create('/reports/center', 'GET', ['tab' => 'partner_share']))->render();
        $this->assertStringContainsString('id="partner-share-' . $this->partnerId . '"', $html);
        $this->assertMatchesRegularExpression('/id="partner-share-net-' . $this->partnerId . '">\s*200\.00/', $html);
        $this->assertStringContainsString('We pay Partner A', $html);

        $engine = app(SalesReportEngine::class);
        $f = $engine->normalizeFilters(['date_from' => $this->daysAgo(30), 'date_to' => $this->day]);
        $csv = app(SalesReportExporter::class)->sections($f, ['partner_share'])['partner_share'] ?? '';
        $this->assertStringContainsString('Grill Platter', $csv);
        $this->assertStringContainsString('NET: we pay Partner A', $csv);

        $thermal = app(EscPosPayloadService::class)->buildReport(['sections' => ['partner_share'], 'partnerShare' => $engine->partnerShare($f), 'meta' => []]);
        $this->assertStringContainsString('PARTNER SHARE', $thermal);
        $this->assertStringContainsString('NET: WE PAY', $thermal);
    }

    // ── the rate list screen ───────────────────────────────────────────────────────────────────

    public function test_partners_and_rates_are_kept_through_the_screen(): void
    {
        $this->bindTenant();
        $this->owner(['tenant.reports.partner-share.index']);
        $ctl = app(PartnerShareController::class);

        $ctl->storePartner(Request::create('/x', 'POST', ['name' => 'Partner B']));
        $b = SharePartner::where('name', 'Partner B')->firstOrFail();

        $ctl->storeRate(Request::create('/x', 'POST', ['share_partner_id' => $b->id, 'item' => 'c:' . $this->platter,
            'payer' => 'partner', 'amount' => 1000, 'effective_from' => $this->daysAgo(3)]));
        $this->assertSame(1, ShareRate::where('share_partner_id', $b->id)->count());

        try {
            $ctl->storeRate(Request::create('/x', 'POST', ['share_partner_id' => $b->id, 'item' => 'c:' . $this->platter,
                'payer' => 'partner', 'amount' => 900, 'effective_from' => $this->daysAgo(3)]));
            $this->fail('a second version on the same day must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('effective_from', $e->errors());
        }
        try {
            $ctl->storeRate(Request::create('/x', 'POST', ['share_partner_id' => $b->id, 'item' => 'p:999999',
                'payer' => 'us', 'amount' => 10, 'effective_from' => $this->day]));
            $this->fail('an unknown dish must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('item', $e->errors());
        }

        $html = $ctl->index(Request::create('/reports/partner-share', 'GET', ['partner_id' => $b->id]))->render();
        $this->assertStringContainsString('Grill Platter', $html);
        $this->assertMatchesRegularExpression('/Partner B pays us.*?1,000.*?4,500.*?1,000/s', $html,
            'price 5,500: Partner B keeps 4,500 and we get the 1,000 it pays');

        $ctl->destroyRate(ShareRate::where('share_partner_id', $b->id)->firstOrFail());
        $this->assertSame(0, ShareRate::where('share_partner_id', $b->id)->count());
    }

    public function test_the_section_permission_goes_to_the_owner_role_only(): void
    {
        $conn = DB::connection('tenant');
        $name = 'tenant.reports.center.sections.partner-share';
        $ownerRole = \Spatie\Permission\Models\Role::findOrCreate('Owner', 'tenant');
        $cashierRole = \Spatie\Permission\Models\Role::findOrCreate('Cashier', 'tenant');
        $conn->table('role_has_permissions')->where('permission_id', $conn->table('permissions')->where('name', $name)->value('id'))->delete();

        (require base_path('database/migrations/tenant/2026_10_14_000003_add_partner_share_report_section_permission.php'))->up();

        $holders = $conn->table('role_has_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('p.name', $name)->pluck('rp.role_id')->all();
        $this->assertSame([$ownerRole->id], $holders);
        $this->assertNotContains($cashierRole->id, $holders);
    }

    // ── import ─────────────────────────────────────────────────────────────────────────────────

    public function test_import_is_a_dry_run_until_yes_and_refuses_a_half_list(): void
    {
        $code = $this->tenantCode();
        $csv = tempnam(sys_get_temp_dir(), 'ps') . '.csv';
        file_put_contents($csv, "type,name,payer,amount,effective_from,notes\ndeal,Grill Platter,partner,1000,{$this->day},sheet\nproduct,BBQ Sandwich,us,400,{$this->day},\n");

        Artisan::call('share-rates:import', ['tenant_code' => $code, '--file' => $csv, '--partner' => 'Partner Z']);
        DB::setDefaultConnection('tenant');
        $this->assertFalse(SharePartner::where('name', 'Partner Z')->exists(), 'dry run writes nothing');

        file_put_contents($csv, file_get_contents($csv) . "product,No Such Dish,us,1,{$this->day},\n");
        $exit = Artisan::call('share-rates:import', ['tenant_code' => $code, '--file' => $csv, '--partner' => 'Partner Z', '--yes' => true]);
        DB::setDefaultConnection('tenant');
        $this->assertSame(1, $exit);
        $this->assertFalse(SharePartner::where('name', 'Partner Z')->exists(), 'one bad row = nothing written');

        file_put_contents($csv, "type,name,payer,amount,effective_from,notes\ndeal,Grill Platter,partner,1000,{$this->day},sheet\nproduct,BBQ Sandwich,us,400,{$this->day},\n");
        $this->assertSame(0, Artisan::call('share-rates:import', ['tenant_code' => $code, '--file' => $csv, '--partner' => 'Partner Z', '--yes' => true]));
        $this->assertSame(0, Artisan::call('share-rates:import', ['tenant_code' => $code, '--file' => $csv, '--partner' => 'Partner Z', '--yes' => true]));
        DB::setDefaultConnection('tenant');
        $z = SharePartner::where('name', 'Partner Z')->firstOrFail();
        $this->assertSame(2, ShareRate::where('share_partner_id', $z->id)->count(), 'a re-run writes nothing twice');
        @unlink($csv);
    }

    // ── tenant plumbing ────────────────────────────────────────────────────────────────────────

    /** The page layout reads the bound tenant's plan, as IdentifyTenant binds it. Cleaned in tearDown. */
    private function bindTenant(): void
    {
        $plan = \App\Models\Master\Plan::create(['code' => 'pstest-' . uniqid(), 'name' => 'Partner Share Test', 'price' => 0, 'is_active' => true]);
        foreach (['pos', 'restaurant', 'reports'] as $key) {
            $module = \App\Models\Master\Module::firstOrCreate(['key' => $key], [
                'name' => ucfirst($key), 'category' => 'Test', 'description' => $key,
                'route_module_keys' => ['tenant.' . $key], 'sort_order' => 900, 'is_core' => false, 'is_active' => true,
            ]);
            \App\Models\Master\PlanModule::create(['plan_id' => $plan->id, 'module_id' => $module->id, 'is_enabled' => true]);
        }
        $tenant = \App\Models\Master\Tenant::create(['tenant_code' => 'pstest' . Str::lower(Str::random(6)), 'business_name' => 'Partner Share Test', 'status' => 'active']);
        \App\Models\Master\Subscription::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);
        app()->instance('tenant', $tenant->fresh());
    }

    /** Register this test's DB as a tenant so the command can activate it. */
    private function tenantCode(): string
    {
        $master = DB::connection('master');
        $code = 'pstest' . substr(md5($this->tenantDb), 0, 6);
        $master->table('tenants')->updateOrInsert(['tenant_code' => $code], ['business_name' => 'Partner Share Import Test', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $tenantId = $master->table('tenants')->where('tenant_code', $code)->value('id');
        $cfg = config('database.connections.tenant');
        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenant_databases')->insert([
            'tenant_id' => $tenantId, 'db_connection' => 'tenant', 'db_host' => $cfg['host'] ?? '127.0.0.1', 'db_port' => $cfg['port'] ?? 3306,
            'db_database' => $this->tenantDb, 'db_username' => $cfg['username'] ?? 'root',
            'db_password' => Crypt::encryptString((string) ($cfg['password'] ?? '')),
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $code;
    }
}
