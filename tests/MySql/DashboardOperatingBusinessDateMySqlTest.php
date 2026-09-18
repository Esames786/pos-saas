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
 * OPERATING-DATE-1 — dashboard ka "aaj" wo din hai jis par floor kaam kar raha hai.
 *
 * Raat 12:16 baje (Karachi) Kashif Food ka dashboard saari tiles 0.00 dikha raha tha, jabke
 * chaar shiftein khuli thin aur usi safhe ki table 5 September par 866,730 bata rahi thi. Wajah
 * ye ke bill apni tareekh SHIFT se leta hai (frozen business_date) aur dashboard GHADI se poochta
 * tha — ghadi 6 September par ja chuki thi, kaam 5 September par hi tha.
 *
 * Ye teen test wohi teen soorat hain: khuli shift ho, na ho, aur ek bhooli hui purani shift bhi
 * khuli reh gayi ho.
 */
class DashboardOperatingBusinessDateMySqlTest extends MySqlTenantTestCase
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
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'opdate.' . config('tenancy.tenant_base_domain');
        $this->seedMaster();
        $this->seedSubscription();
        $this->seedTenant();

        // Fixture shifts ko saaf nahi karta, aur ye poori test file KHULI shifton par chalti hai —
        // ek test ki khuli shift agle test me reh jaye to "koi shift khuli nahi" wala test apna
        // hi sawal jhoota kar deta hai. Har test khali daftar se shuru ho.
        DB::connection("tenant")->table("shifts")->delete();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('tenant_code', 'opdate')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    private function dashboard()
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->get('http://' . $this->host . '/dashboard');
    }

    private function sale(string $businessDate, float $total = 1000): int
    {
        $id = $this->makeSale($this->branchId, [
            "status" => "paid", "order_type" => "takeaway",
            "grand_total" => $total, "business_date" => $businessDate,
        ]);
        $this->makeSaleLine($id, $this->productId, ["quantity" => 1]);

        return $id;
    }

    /** Ek khuli shift, us din par jam gayi — bilkul jaise POS khudi karta hai. */
    private function openShiftOn(string $businessDate): int
    {
        return (int) DB::connection("tenant")->table("shifts")->insertGetId([
            "shift_uuid" => strtoupper(bin2hex(random_bytes(13))),
            "branch_id" => $this->branchId,
            "terminal_id" => $this->makeTerminal($this->branchId, ["name" => "T" . Str::random(4)]),
            "opened_by_user_id" => $this->ownerId,
            "opening_cash" => 0, "expected_cash" => 0,
            "status" => "open",
            "business_date" => $businessDate,
            "timezone_name" => "Asia/Karachi",
            "opened_at" => $businessDate . " 06:00:00",
            "created_at" => now(), "updated_at" => now(),
        ]);
    }

    /** Sirf "Net Sales Today" tile ka hissa — history wali table is se bahar reh jaati hai. */
    private function netSalesTile(): string
    {
        $html = $this->dashboard()->assertOk()->getContent();
        $at   = strpos($html, "Net Sales Today");
        $this->assertNotFalse($at, "Net Sales Today tile safhe par honi chahiye");

        return substr($html, $at, 400);
    }

    /**
     * Sirf "Last N Days — Net Sales" wali table — unwaan se us ke `</table>` tak.
     *
     * Poore safhe par assert karna be-maani hota: wohi tareekhein doosri tiles aur open-bills
     * wale hisse me bhi chhapti hain, to "mil gaya" ka matlab kuch na hota.
     */
    private function historyCard(): string
    {
        $html = $this->dashboard()->assertOk()->getContent();
        $at   = strpos($html, "Days — Net Sales");
        $this->assertNotFalse($at, "net sales history card safhe par honi chahiye");

        // Unwaan me ginti "Days" se PEHLE aati hai ("Last 8 Days"), is liye us <h6> tak peeche
        // jaana zaroori hai — warna wo ginti is hisse se bahar reh jaati hai jise hum parhte hain.
        $head = strrpos(substr($html, 0, $at), "<h6");
        $this->assertNotFalse($head, "card ka unwaan <h6> me hona chahiye");

        $end = strpos($html, "</table>", $at);
        $this->assertNotFalse($end, "card ki table band honi chahiye");

        return substr($html, $head, $end - $head);
    }

    private function bizToday(): string
    {
        return app(\App\Support\TenantClock::class)
            ->currentBusinessDate(\App\Models\Tenant\Branch::on("tenant")->find($this->branchId));
    }

    private function bizYesterday(): string
    {
        return \Illuminate\Support\Carbon::parse($this->bizToday())->subDay()->toDateString();
    }

    /**
     * Asal shikayat: shift kal khuli, abhi tak band nahi hui, aur bill uska hai. Dashboard ko wohi
     * din dikhana chahiye — ghadi chahe agla din keh chuki ho.
     */
    public function test_the_dashboard_follows_the_open_shifts_business_date(): void
    {
        $yday = $this->bizYesterday();
        $this->openShiftOn($yday);
        $this->sale($yday, 1000);

        $this->assertStringContainsString("1,000.00", $this->netSalesTile());
        // Header aur hindse ek hi din ke hon — warna dono me se ek jhoot bolta hai.
        $this->dashboard()->assertSee(\Illuminate\Support\Carbon::parse($yday)->format("l, d M Y"));
    }

    /** Koi shift khuli na ho to purana usool: us branch ke timezone ka aaj. */
    public function test_with_no_open_shift_it_falls_back_to_the_branch_timezone_today(): void
    {
        $this->sale($this->bizYesterday(), 1000);

        $this->assertStringNotContainsString("1,000.00", $this->netSalesTile());
        $this->dashboard()->assertSee(\Illuminate\Support\Carbon::parse($this->bizToday())->format("l, d M Y"));
    }

    /**
     * Kashif par ek shift teen din khuli reh gayi thi. Sab se purani khuli shift lete to dashboard
     * teen din peeche chala jata — is liye MAX, MIN nahi.
     */
    public function test_a_forgotten_old_shift_does_not_drag_the_day_backwards(): void
    {
        $stale = \Illuminate\Support\Carbon::parse($this->bizToday())->subDays(3)->toDateString();
        $this->openShiftOn($stale);
        $this->openShiftOn($this->bizYesterday());

        $this->sale($stale, 7777);
        $this->sale($this->bizYesterday(), 1000);

        $tile = $this->netSalesTile();
        $this->assertStringContainsString("1,000.00", $tile);
        $this->assertStringNotContainsString("7,777.00", $tile);
    }

    /* ── DASHBOARD-8DAY-1 — history ka window AATH din ka ─────────────────────
     *
     * Maalik: "yaha last 7 days nhi, last 8 days ana chahye".
     *
     * Wajah amli hai: aaj ka din ADHOORA hota hai (17 Sep par 106 order jab baaqi dinon par
     * ~400), is liye 7 ka window sirf 6 MUKAMMAL din deta tha — aur hafte ka wohi din, jis se
     * maalik moqabla karta hai, window se bahar gir jata tha.
     * ─────────────────────────────────────────────────────────────────────── */

    /**
     * Aaj se saat din peeche ka din window ke ANDAR ho, aur aathwan din bahar.
     *
     * Ye asal guard hai: purane 7-din wale window par -7 wala din bahar tha, is liye ye test
     * us par RED hota hai. Tareekh ke sath us din ka PAISA bhi check hota hai — sirf label mil
     * jana kaafi nahi, qatar ko data bhi milna chahiye (aur wohi purani kharabi thi, jahan
     * label ka window data ke window se alag ho kar "—" chhapta tha).
     */
    public function test_saat_din_peeche_ka_din_bhi_window_me_hai(): void
    {
        $today = $this->bizToday();
        $this->openShiftOn($today);

        $inside  = \Illuminate\Support\Carbon::parse($today)->subDays(7)->toDateString();
        $outside = \Illuminate\Support\Carbon::parse($today)->subDays(8)->toDateString();

        $this->sale($inside, 4321);
        $this->sale($outside, 9876);

        $card = $this->historyCard();

        $this->assertStringContainsString("Last 8 Days", $card,
            "card ka unwaan bhi aath kehna chahiye");
        $this->assertStringContainsString(
            \Illuminate\Support\Carbon::parse($inside)->format("D, d M"),
            $card,
            "aaj se 7 din peeche ka din window me hona chahiye"
        );
        $this->assertStringContainsString("4,321.00", $card,
            "us din ki qatar ko DATA bhi milna chahiye, sirf tareekh nahi");
        $this->assertStringNotContainsString("9,876.00", $card,
            "aaj se 8 din peeche ka din window se BAHAR rehna chahiye — warna window barhta hi jayega");
    }

    /**
     * Theek AATH qatarein — na kam, na zyada.
     *
     * Har din ka apna label maujood ho. Ye us soorat ko pakarta hai jahan start date badal di
     * jaye magar labels ki ginti purani reh jaye (ya ulta) — dono alag jagah se banti thin,
     * aur isi wajah se card ek din par "—" chhapta tha.
     */
    public function test_window_me_theek_aath_din_ke_labels_hote_hain(): void
    {
        $today = $this->bizToday();
        $this->openShiftOn($today);

        $card = $this->historyCard();

        for ($back = 7; $back >= 0; $back--) {
            $label = \Illuminate\Support\Carbon::parse($today)->subDays($back)->format("D, d M");
            $this->assertStringContainsString($label, $card, "din -{$back} ki qatar honi chahiye");
        }

        $ninth = \Illuminate\Support\Carbon::parse($today)->subDays(8)->format("D, d M");
        $this->assertStringNotContainsString($ninth, $card, "nauwan din window se bahar");
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenants')->where('tenant_code', 'opdate')->delete();

        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'opdate', 'business_name' => 'Operating Date',
            'owner_name' => 'Owner', 'owner_email' => 'owner@opdate.test',
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

    private function seedSubscription(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $planId = $m->table('plans')->where('code', 'opdate-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'opdate-plan', 'name' => 'Operating Date', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();

        // Unmapped dashboard routes are fail-open, so a missing module is not a failure here.
        $key = $m->table('route_catalogs')->where('route_name', 'tenant.dashboard')->value('module_key');
        $module = $key ? Module::forRouteModuleKey($key)->first() : null;
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
            'sale_payments', 'sales_order_lines', 'sales_orders', 'model_has_roles', 'users',
            'products', 'categories', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        foreach (['tenant.dashboard', 'tenant.dashboard.details'] as $name) {
            $c->table('permissions')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'tenant'], ['created_at' => now(), 'updated_at' => now()]
            );
        }
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $pid) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $pid, 'role_id' => $ownerRole], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'BillsOwner', 'email' => 'billsowner@opdate.test', 'password' => bcrypt('x'),
            'employee_code' => 'BLLOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        $this->branchId = $this->makeBranch();
        $this->productId = $this->makeProduct(
            $this->makeCategory(['name' => 'Food', 'slug' => 'food-' . Str::random(4)])
        );

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
