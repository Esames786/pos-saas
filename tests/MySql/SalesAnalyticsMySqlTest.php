<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Tenant\User;
use App\Services\Reports\SalesReportEngine;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * SALES-ANALYTICS-1 — graphs wala safha (Owner-only).
 *
 * Sab se ahem guard pehla hai: **safhe ka jama usi daur ke `overview()['net_sales']` se barabar ho**.
 *
 * Wajah tareekhi hai. Dashboard ka "Last 7 Days" card kabhi apni ALAG query chalata tha (`status =
 * paid` sirf, returns ghataye baghair) aur 1 Sep ko upar tile "Orders Today 295", neeche row "291"
 * dikha rahi thi — do din ka Rs 1,400 + 2,490 asli revenue bhi bahar reh gaya tha
 * (DASHBOARD-7DAY-POPULATION-1). Agar ye safha apna hisaab likhta to wohi bimari dobara hoti:
 * owner ko graph par kuch aur, Report Center par kuch aur.
 *
 * Doc: docs/plans/sales-analytics-dashboard-2026-09-13.md
 */
class SalesAnalyticsMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;
    private int $tenantId;
    private int $ownerId;
    private int $cashierId;
    private int $branchId;
    private int $otherBranchId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'analytics.' . config('tenancy.tenant_base_domain');
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
            $m->table('tenants')->where('tenant_code', 'analytics')->delete();
        } catch (\Throwable) {
            // best effort
        }
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. HISAAB — sab se ahem
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Safha jo jama dikhata hai wo `SalesReportEngine::overview()` se barabar ho.
     *
     * Ye guard us din girega jis din koi safhe me apni query likh de — aur wohi wo ghalti hai jis ne
     * dashboard par pehle do alag jawab paida kiye thay.
     */
    public function test_safhe_ka_jama_report_engine_se_barabar_hai(): void
    {
        $this->sale(0, 1000);
        $this->sale(1, 1500);
        $this->sale(2, 2500);

        $res = $this->page('preset=30d');
        $res->assertOk();

        $totals = $res->viewData('totals');

        $engineNet = (float) app(SalesReportEngine::class)->overview(
            app(SalesReportEngine::class)->normalizeFilters([
                'date_from'  => $res->viewData('from'),
                'date_to'    => $res->viewData('to'),
                'branch_ids' => [],
            ])
        )['net_sales'];

        $this->assertSame(
            number_format($engineNet, 2, '.', ''),
            number_format((float) $totals['net_sales'], 2, '.', ''),
            'safhe ka net sales report engine se barabar hona chahiye — warna ek hi din ke do jawab'
        );
    }

    /** Returns ghataye jayen — wohi population jo baqi reports istemal karte hain. */
    public function test_returns_ghataye_jate_hain(): void
    {
        $saleId = $this->sale(0, 5000);
        $this->postedReturn($saleId, 1200);

        $totals = $this->page('preset=30d')->viewData('totals');

        $this->assertSame('3800.00', number_format((float) $totals['net_sales'], 2, '.', ''),
            '5000 me se 1200 ka return ghata kar 3800 aana chahiye');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. GROWTH — batta sifar wali soorat
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Pichle daur me kuch bika hi na ho to growth NULL ho, `0%` ya `∞%` NAHI.
     *
     * Abhi koi tenant 6 mahine purana nahi (sab se purana 34 din), is liye ye soorat rozmarra hai —
     * ittefaqi nahi. Sifar se kisi bhi raqam tak jane ka koi bhi faisd jhoot hota hai.
     */
    public function test_pichla_daur_khali_ho_to_growth_ka_dawa_nahi_kiya_jata(): void
    {
        $this->sale(0, 4000);

        $growth = $this->page('preset=7d')->viewData('growth');

        $this->assertFalse($growth['comparable'],
            'pichle daur ka data nahi to moqabala mumkin nahi kehna chahiye');
        $this->assertNull($growth['net_sales'],
            'growth null ho — 0% ya Infinity nahi, dono jhoot hain');
    }

    /** Aur jab pichla daur mojood ho to faisd theek nikle. */
    public function test_pichla_daur_mojood_ho_to_growth_theek_nikalta_hai(): void
    {
        // Pichle 7 din (din 7-13 peeche) me 1000, is 7 din me 1500 -> +50%
        $this->sale(10, 1000);
        $this->sale(2, 1500);

        $growth = $this->page('preset=7d')->viewData('growth');

        $this->assertTrue($growth['comparable']);
        $this->assertSame(50.0, $growth['net_sales'], '1000 se 1500 = +50%');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. IJAZAT — "sirf admin"
    // ══════════════════════════════════════════════════════════════════════════

    /** Owner ko safha mile. */
    public function test_owner_safha_dekh_sakta_hai(): void
    {
        $this->page('preset=30d')->assertOk();
    }

    /**
     * Cashier ko NA mile.
     *
     * ⚠️ Ye us cheez par pehra hai jo deploy ke waqt aasani se toot sakti hai: `deploy.sh` naye
     * route ki permission sirf Owner ko deta hai, magar agar koi ghalti se additive grant chala de
     * to har cashier ko owner ka poora sales aur growth nazar aane lagega.
     */
    public function test_cashier_ko_safha_nahi_milta(): void
    {
        $res = $this->actingAs(User::on('tenant')->find($this->cashierId), 'tenant')
            ->get('http://' . $this->host . '/reports/analytics');

        $this->assertContains($res->getStatusCode(), [403, 302],
            'cashier ko analytics nahi milni chahiye; mila ' . $res->getStatusCode());
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 4. KHALI DAUR aur RANGE
    // ══════════════════════════════════════════════════════════════════════════

    /** Koi sale na ho to safha 500 na de — saaf jawab de. */
    public function test_khali_daur_par_safha_nahi_toot_ta(): void
    {
        $res = $this->page('preset=30d');

        $res->assertOk();
        $this->assertSame(0.0, (float) $res->viewData('totals')['net_sales']);
        $res->assertSee('koi sale nahi', false);
    }

    /** Lamba daur ho to chart mahine par chala jaye — 180 nuqte parhe nahi jaate. */
    public function test_lambe_daur_par_chart_mahine_par_chala_jata_hai(): void
    {
        $this->assertSame('day',   $this->page('preset=30d')->viewData('granularity'));
        $this->assertSame('month', $this->page('preset=6m')->viewData('granularity'));
    }

    /**
     * Har din qatar me ho — jin dinon sale nahi hui wo bhi 0 par.
     *
     * Warna chart ka waqt ka paimana jhoot bolta hai: do door ke din barabar faasle par nazar aate
     * hain aur rujhan ghalat dikhta hai.
     */
    public function test_jin_dinon_sale_nahi_hui_wo_bhi_qatar_me_hain(): void
    {
        $this->sale(0, 1000);
        $this->sale(6, 1000);

        $daily = $this->page('preset=7d')->viewData('daily');

        $this->assertCount(7, $daily, '7 din ke liye 7 qatarein honi chahiye');
        $this->assertSame(2, collect($daily)->where('net_sales', '>', 0)->count(),
            'sirf do din par sale, baqi 0 par mojood');
    }

    /** Ulti tareekhen di jayen to safha khud theek kar le, girna nahi chahiye. */
    public function test_ulti_tareekhen_khud_theek_ho_jati_hain(): void
    {
        $res = $this->page('preset=custom&from=' . now()->toDateString() . '&to=' . now()->subDays(5)->toDateString());

        $res->assertOk();
        $this->assertLessThanOrEqual($res->viewData('to'), $res->viewData('from'),
            'from hamesha to se pehle hona chahiye');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 5. ORDER TYPE ka filter
    // ══════════════════════════════════════════════════════════════════════════

    /** Filter lagne par sirf usi type ka hisaab. */
    public function test_order_type_ka_filter_sirf_usi_type_ka_hisaab_deta_hai(): void
    {
        $this->sale(0, 1000, null, 'delivery');
        $this->sale(0, 3000, null, 'dine_in');

        $all      = $this->page('preset=30d')->viewData('totals');
        $delivery = $this->page('preset=30d&order_type=delivery')->viewData('totals');

        $this->assertSame('4000.00', number_format((float) $all['net_sales'], 2, '.', ''),
            'bina filter ke dono shamil');
        $this->assertSame('1000.00', number_format((float) $delivery['net_sales'], 2, '.', ''),
            'delivery chunne par sirf delivery ka hisaab');
    }

    /**
     * ⚠️ RETURNS bhi usi type ke ghatein.
     *
     * `sales_returns` par apna `order_type` khaana hota hi nahi — wo us ke ASAL bill par hai. Agar
     * filter sirf sale par lagta aur return par nahi, to "sirf Delivery" chunne par delivery ki sale
     * to theek aati magar HAR type ke returns ghata diye jate, aur net sales kam dikhta. Ye guard
     * theek us soorat par girta hai.
     */
    public function test_filter_lagne_par_doosre_type_ke_returns_nahi_ghatte(): void
    {
        $delivery = $this->sale(0, 1000, null, 'delivery');
        $dineIn   = $this->sale(0, 3000, null, 'dine_in');

        $this->postedReturn($dineIn, 500);      // sirf DINE IN ka return

        $onlyDelivery = $this->page('preset=30d&order_type=delivery')->viewData('totals');

        $this->assertSame('1000.00', number_format((float) $onlyDelivery['net_sales'], 2, '.', ''),
            'dine-in ka return delivery ke hisaab se nahi ghatna chahiye');

        $all = $this->page('preset=30d')->viewData('totals');
        $this->assertSame('3500.00', number_format((float) $all['net_sales'], 2, '.', ''),
            'bina filter ke wo return ghatna chahiye: 4000 - 500');
    }

    /** Doosri branch ka data is branch ke chart me na aaye. */
    public function test_doosri_branch_ka_data_nahi_milta(): void
    {
        $this->sale(0, 1000);                          // branch 1
        $this->sale(0, 7777, $this->otherBranchId);    // branch 2

        $totals = $this->page('preset=30d&branch_id=' . $this->branchId)->viewData('totals');

        $this->assertSame('1000.00', number_format((float) $totals['net_sales'], 2, '.', ''),
            'branch filter lagne par sirf usi branch ka hisaab');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Madadgar
    // ══════════════════════════════════════════════════════════════════════════

    private function page(string $query)
    {
        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->get('http://' . $this->host . '/reports/analytics?' . $query);
    }

    /** Ek paid sale, `$daysAgo` din pehle ki business date par. */
    private function sale(int $daysAgo, float $total, ?int $branchId = null, string $orderType = 'takeaway'): int
    {
        $date = now()->subDays($daysAgo)->toDateString();

        $id = $this->makeSale($branchId ?? $this->branchId, [
            'status'        => 'paid',
            'order_type'    => $orderType,
            'grand_total'   => $total,
            'subtotal'      => $total,
            'business_date' => $date,
            'sale_date'     => $date . ' 12:00:00',
        ]);
        $this->makeSaleLine($id, $this->productId, [
            'quantity' => 1, 'unit_price' => $total, 'line_total' => $total,
        ]);

        return $id;
    }

    private function postedReturn(int $saleId, float $amount): void
    {
        $sale = DB::connection('tenant')->table('sales_orders')->find($saleId);

        DB::connection('tenant')->table('sales_orders')->where('id', $saleId)
            ->update(['status' => 'partially_returned']);

        DB::connection('tenant')->table('sales_returns')->insert([
            'return_no'      => 'SR-' . Str::upper(Str::random(8)),
            'sales_order_id' => $saleId,
            'branch_id'      => $sale->branch_id,
            'status'         => 'posted',
            'grand_total'    => $amount,
            'subtotal'       => $amount,
            'return_date'    => $sale->business_date . ' 13:00:00',
            'business_date'  => $sale->business_date,
            'created_at'     => now(), 'updated_at' => now(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Seed
    // ══════════════════════════════════════════════════════════════════════════

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenants')->where('tenant_code', 'analytics')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'analytics', 'business_name' => 'Analytics',
            'owner_name' => 'Owner', 'owner_email' => 'owner@analytics.test',
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

    private function seedSubscription(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $planId = $m->table('plans')->where('code', 'analytics-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'analytics-plan', 'name' => 'Analytics', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();

        // ⚠️ MODULE MOJOOD HO TO PLAN ME DAAL DO — MAGAR BANAO KABHI NAHI.
        //
        // Master DB SANJHI hai aur us ka module-landscape har run me badalta hai: kabhi koi doosra
        // test `tenant.reports` ka dawedar module bana chuka hota hai, kabhi nahi. Dono soorton me
        // ye test chalna chahiye.
        //
        //   - dawedar MOJOOD  -> gate fail-CLOSED, is liye plan me shamil karna lazmi
        //   - dawedar NAHI    -> gate fail-OPEN, kuch karna hi nahi
        //
        // Pehli koshish me maine module KHUD BANA diya tha. Us se poora suite toot gaya:
        // `CateringViewRenderMySqlTest` ka "tenant.reports.center.index must remain allowed for a
        // POS tenant" gir gaya — mera naya module dawedar ban gaya, gate fail-CLOSED ho gaya, aur
        // jis tenant ke plan me wo module nahi tha us se Report Center chhin gaya.
        //
        // Sabaq: sanjhi DB me kuch BANANA doosre tests ka bartaao badal deta hai. Mojooda haalat ke
        // mutabiq DHALNA mehfooz hai, usay badalna nahi.
        foreach (['tenant.reports.analytics'] as $routeName) {
            $key = $m->table('route_catalogs')->where('route_name', $routeName)->value('module_key');
            if (! $key) {
                continue;
            }

            $module = Module::forRouteModuleKey($key)->first();
            if (! $module) {
                continue;   // koi dawedar nahi -> fail-open -> kuch karna nahi
            }

            $m->table('plan_modules')->updateOrInsert(
                ['plan_id' => $planId, 'module_id' => $module->id], ['is_enabled' => 1]
            );
        }

        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert([
            'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedTenant(): void
    {
        $this->cleanTenant([
            'sales_return_lines', 'sales_returns', 'sale_payments',
            'sales_order_lines', 'sales_orders',
            'model_has_roles', 'role_has_permissions', 'users',
            'products', 'categories', 'terminals', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        $cashierRole = $c->table('roles')->where('name', 'Cashier')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Cashier', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);

        $c->table('permissions')->updateOrInsert(
            ['name' => 'tenant.reports.analytics', 'guard_name' => 'tenant'],
            ['created_at' => now(), 'updated_at' => now()]
        );

        // Owner ko sab kuch — asli tenant me bhi Owner har tenant.* permission rakhta hai.
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }

        // ⚠️ Cashier ko analytics ki permission JAAN-BOOJH KAR nahi di — yehi prod ki haalat hai
        // (deploy.sh sirf Owner ko deta hai). Us par guard neeche hai.
        $c->table('role_has_permissions')
            ->where('role_id', $cashierRole)
            ->whereIn('permission_id', $c->table('permissions')->where('name', 'tenant.reports.analytics')->pluck('id'))
            ->delete();

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'AnOwner', 'email' => 'owner@analytics.test', 'password' => bcrypt('x'),
            'employee_code' => 'ANOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        $this->cashierId = $c->table('users')->insertGetId([
            'name' => 'AnCashier', 'email' => 'cashier@analytics.test', 'password' => bcrypt('x'),
            'employee_code' => 'ANCASH', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $cashierRole, 'model_type' => User::class, 'model_id' => $this->cashierId,
        ]);

        $this->branchId      = $this->makeBranch();
        $this->otherBranchId = $this->makeBranch(['name' => 'Doosri Branch']);
        $this->productId     = $this->makeProduct(
            $this->makeCategory(['name' => 'Food', 'slug' => 'food-' . Str::random(4)]),
            ['product_type' => 'service', 'product_kind' => 'service', 'is_stock_tracked' => 0]
        );

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
