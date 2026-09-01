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
 * DASHBOARD-OPEN-BILLS-1 — the money still on the tables, shown without corrupting the money earned.
 *
 * Every tile counts PAID work, so a busy service is invisible until the bills settle: on 31 Aug
 * Kashif Food's dashboard read Rs 82,795 while another Rs 47,235 sat on 23 open checks.
 *
 * The whole risk of this feature is ONE mistake: folding that figure into the tile. Net Sales has to
 * keep matching the Report Center and the day's closing, or nobody can tell which number is true.
 * So the first assertion here is that the tile does NOT move.
 */
class DashboardOpenBillsMySqlTest extends MySqlTenantTestCase
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

        $this->host = 'openbills.' . config('tenancy.tenant_base_domain');
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
            $m->table('tenants')->where('tenant_code', 'openbills')->delete();
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

    private function sale(string $status, float $total, float $discount = 0, float $tax = 0): int
    {
        // Stamp the BUSINESS date, exactly as the POS does. The fixture writes sale_date as UTC now
        // and leaves business_date null, so between midnight in the shop's timezone and midnight UTC
        // the dashboard's window (the branch's business day) and DATE(sale_date) fall on different
        // days and this test silently stopped seeing its own sales. Production never has that
        // problem because business_date is always written; neither should the test.
        $id = $this->makeSale($this->branchId, [
            'status' => $status, 'order_type' => 'takeaway',
            'grand_total' => $total, 'discount_amount' => $discount, 'tax_amount' => $tax,
            'business_date' => app(\App\Support\TenantClock::class)
                ->currentBusinessDate(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId)),
        ]);
        $this->makeSaleLine($id, $this->productId, ['quantity' => 1]);

        return $id;
    }

    /** THE assertion this feature exists to protect: the earned figure does not move. */
    public function test_open_bills_never_change_the_paid_total(): void
    {
        $this->sale('paid', 1000);
        $before = $this->dashboard();
        $before->assertOk();
        $before->assertSee('1,000.00');

        $this->sale('held', 5000);
        $this->sale('draft', 2500);

        $after = $this->dashboard();
        $after->assertOk();
        $after->assertSee('1,000.00');                       // Net Sales unmoved
        $after->assertDontSee('>8,500.00<', false);          // never the sum, anywhere as a headline
    }

    /** Held and draft are both "still open", and the expected figure is paid + open. */
    public function test_the_open_line_counts_held_and_draft_and_shows_the_expected_total(): void
    {
        $this->sale('paid', 1000);
        $this->sale('held', 5000);
        $this->sale('draft', 2500);

        $res = $this->dashboard();

        $res->assertSee('7,500.00');      // + still open  (5000 + 2500)
        $res->assertSee('8,500.00');      // expected      (1000 + 7500)
        $res->assertSee('still open', false);
    }

    /** A cancelled bill is finished, not open — it must not inflate the expectation. */
    public function test_a_cancelled_bill_is_not_open(): void
    {
        $this->sale('paid', 1000);
        $this->sale('held', 5000);
        $this->sale('cancelled', 9999);

        $res = $this->dashboard();

        $res->assertSee('5,000.00');
        $res->assertDontSee('14,999.00');
        $res->assertDontSee('9,999.00');
    }

    /** Nothing open — no line at all, rather than a row of zeroes. */
    public function test_nothing_open_means_no_extra_line(): void
    {
        $this->sale('paid', 1000);

        $this->dashboard()->assertDontSee('still open', false);
    }

    /**
     * Cash and Card carry NO open figure. A held bill has no payment row — the method is chosen at
     * payment time — so any split would be invented. This pins that decision down.
     */
    public function test_cash_and_card_carry_no_open_figure(): void
    {
        $this->sale('paid', 1000);
        $this->sale('held', 5000);

        $html = $this->dashboard()->getContent();

        // "still open" may appear under Net Sales / Orders, but never inside the Cash or Card tile.
        foreach (['Cash Today', 'Card/Bank Today'] as $tile) {
            $at = strpos($html, $tile);
            $this->assertNotFalse($at, "the {$tile} tile must still be on the page");
            $slice = substr($html, $at, 400);
            $this->assertStringNotContainsString('still open', $slice,
                "{$tile} must not claim an expected amount — a held bill has no payment method yet");
        }
    }

    /** An open bill from another business date belongs to that day, not this one. */
    public function test_yesterdays_open_bill_is_not_counted_today(): void
    {
        $this->sale('paid', 1000);
        $stale = $this->sale('held', 4321);
        DB::connection('tenant')->table('sales_orders')->where('id', $stale)
            ->update(['business_date' => now()->subDays(3)->toDateString()]);

        $this->dashboard()->assertDontSee('4,321.00');
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenants')->where('tenant_code', 'openbills')->delete();

        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'openbills', 'business_name' => 'Open Bills',
            'owner_name' => 'Owner', 'owner_email' => 'owner@openbills.test',
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

        $planId = $m->table('plans')->where('code', 'openbills-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'openbills-plan', 'name' => 'Open Bills', 'price' => 0,
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
            'name' => 'BillsOwner', 'email' => 'billsowner@openbills.test', 'password' => bcrypt('x'),
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
