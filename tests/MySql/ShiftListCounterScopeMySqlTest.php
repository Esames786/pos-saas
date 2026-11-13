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
 * SHIFT-LIST-COUNTER-1 — a counter cashier sees only his own counter's shifts.
 *
 * Two counters side by side (Takeaway, Quick Sale), each with its own cashier bound to it. The Shifts
 * list, a shift's page and its close screen used to show every counter's shift to anyone holding the
 * shift permissions, opening cash and expected cash included — so the two cashiers read each other's
 * drawers. The terminal binding that already limits the dashboard, reports and the close itself now
 * limits these three screens too. Owner (bound to every counter) and an unbound manager see all.
 *
 * Every check runs over real HTTP, through the route's middleware and the Blade.
 */
class ShiftListCounterScopeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    /** Distinct opening cash per counter: the list prints it, so finding it in a page is proof. */
    private const TAKEAWAY_OPENING = 11111.00;
    private const QUICK_OPENING    = 22222.00;

    private string $host;
    private int $tenantId;
    private int $branchId;
    private int $takeawayTerminal;
    private int $quickTerminal;
    private int $ownerId;
    private int $managerId;
    private int $takeawayCashier;
    private int $quickCashier;
    private int $takeawayShift;
    private int $quickShift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'shiftscope.' . config('tenancy.tenant_base_domain');
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
            $m->table('tenants')->where('tenant_code', 'shiftscope')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    /* ── 1. the list ─────────────────────────────────────────────────────── */

    public function test_a_counter_cashier_sees_only_his_own_counters_shifts(): void
    {
        $html = $this->get200($this->takeawayCashier, '/shifts');

        $this->assertStringContainsString('/shifts/' . $this->takeawayShift . '"', $html, 'his own shift is listed');
        $this->assertStringContainsString('11,111.00', $html, 'with its opening cash');
        $this->assertStringContainsString('1 open', $html, 'the open count is his counter only');

        $this->assertStringNotContainsString('/shifts/' . $this->quickShift . '"', $html, 'the Quick Sale shift must not be listed');
        $this->assertStringNotContainsString('22,222.00', $html, "the Quick Sale drawer's cash must not reach the page");
        $this->assertStringNotContainsString('2 open', $html, 'the other counter must not be counted either');

        // …and the other way round.
        $html = $this->get200($this->quickCashier, '/shifts');
        $this->assertStringContainsString('/shifts/' . $this->quickShift . '"', $html);
        $this->assertStringNotContainsString('/shifts/' . $this->takeawayShift . '"', $html, 'the Takeaway shift must not be listed');
        $this->assertStringNotContainsString('11,111.00', $html, "the Takeaway drawer's cash must not reach the page");
    }

    /** Owner is bound to both counters, the manager to none: both keep the whole list. */
    public function test_owner_and_an_unbound_manager_still_see_every_counter(): void
    {
        foreach (['owner' => $this->ownerId, 'manager' => $this->managerId] as $who => $userId) {
            $html = $this->get200($userId, '/shifts');
            $this->assertStringContainsString('/shifts/' . $this->takeawayShift . '"', $html, "$who sees the Takeaway shift");
            $this->assertStringContainsString('/shifts/' . $this->quickShift . '"', $html, "$who sees the Quick Sale shift");
            $this->assertStringContainsString('2 open', $html, "$who counts both open drawers");

            $this->get200($userId, '/shifts/' . $this->quickShift);
            $this->get200($userId, '/shifts/' . $this->quickShift . '/close');
        }
    }

    /* ── 2. by id: the page and the close screen ─────────────────────────── */

    public function test_a_counter_cashier_cannot_open_another_counters_shift_by_id(): void
    {
        foreach (['/shifts/' . $this->quickShift, '/shifts/' . $this->quickShift . '/close'] as $url) {
            $res = $this->as($this->takeawayCashier)->get('http://' . $this->host . $url);
            $this->assertSame(403, $res->getStatusCode(), "{$url} must be refused to the Takeaway cashier");
            $this->assertStringNotContainsString('22,222.00', $res->getContent(), "{$url} must not leak the drawer");
        }

        // The rule, not a broken route: his own shift opens on both screens.
        $this->assertStringContainsString('11,111.00', $this->get200($this->takeawayCashier, '/shifts/' . $this->takeawayShift));
        $this->get200($this->takeawayCashier, '/shifts/' . $this->takeawayShift . '/close');
    }

    /** Closing his own shift redirects to its page — that page must still open for him. */
    public function test_closing_his_own_shift_still_lands_on_its_page(): void
    {
        $res = $this->as($this->takeawayCashier)
            ->post('http://' . $this->host . '/shifts/' . $this->takeawayShift . '/close', ['counted_cash' => 11111]);

        $this->assertSame(302, $res->getStatusCode(), 'his own drawer closes');
        $this->assertStringEndsWith('/shifts/' . $this->takeawayShift, (string) $res->headers->get('Location'));
        $this->assertSame('closed', DB::connection('tenant')->table('shifts')->where('id', $this->takeawayShift)->value('status'));

        $this->get200($this->takeawayCashier, '/shifts/' . $this->takeawayShift);
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    private function as(int $userId)
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->find($userId), 'tenant');
    }

    private function get200(int $userId, string $url): string
    {
        $res = $this->as($userId)->get('http://' . $this->host . $url);

        $this->assertSame(200, $res->getStatusCode(),
            "{$url} must load; got " . $res->getStatusCode() . ' — '
            . Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($res->getContent()))), 300));

        return $res->getContent();
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenants')->where('tenant_code', 'shiftscope')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'shiftscope', 'business_name' => 'Shift Scope',
            'owner_name' => 'Owner', 'owner_email' => 'owner@shiftscope.test',
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

        $planId = $m->table('plans')->where('code', 'shiftscope-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'shiftscope-plan', 'name' => 'Shift Scope', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();

        foreach (['tenant.pos.index', 'tenant.dashboard', 'tenant.shifts.index'] as $routeName) {
            $key = $m->table('route_catalogs')->where('route_name', $routeName)->value('module_key');
            $module = $key ? Module::forRouteModuleKey($key)->first() : null;
            if ($module) {
                $m->table('plan_modules')->updateOrInsert(
                    ['plan_id' => $planId, 'module_id' => $module->id], ['is_enabled' => 1]
                );
            }
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
            'sales_order_lines', 'sales_orders', 'shifts', 'terminal_user', 'branch_user',
            'model_has_roles', 'users', 'terminals', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $this->branchId = $this->makeBranch(['name' => 'Main Branch']);
        $this->takeawayTerminal = $this->makeTerminal($this->branchId, ['code' => 'T-TA', 'name' => 'Takeaway Desk']);
        $this->quickTerminal    = $this->makeTerminal($this->branchId, ['code' => 'T-QS', 'name' => 'Quick Sale Desk']);

        // Owner and Counter roles, both holding every shift permission — view-amounts included, as live.
        $roleId = fn (string $name) => $c->table('roles')->where('name', $name)->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId(['name' => $name, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        $ownerRole   = $roleId('Owner');
        $counterRole = $roleId('Counter');

        foreach (['tenant.dashboard', 'tenant.shifts.index', 'tenant.shifts.show', 'tenant.shifts.close',
                  'tenant.shifts.close-form', 'tenant.shifts.close-branch', 'tenant.shifts.close-branch-form',
                  'tenant.shifts.view-amounts'] as $name) {
            $c->table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'tenant'], ['created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            foreach ([$ownerRole, $counterRole] as $role) {
                $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $role], []);
            }
        }

        $this->ownerId         = $this->makeStaff($c, 'owner', 'Owner', $ownerRole, $this->takeawayTerminal, [$this->takeawayTerminal, $this->quickTerminal]);
        $this->managerId       = $this->makeStaff($c, 'manager', 'Manager', $ownerRole, null, []);
        $this->takeawayCashier = $this->makeStaff($c, 'takeaway', 'Takeaway Cashier', $counterRole, $this->takeawayTerminal, [$this->takeawayTerminal]);
        $this->quickCashier    = $this->makeStaff($c, 'quick', 'Quick Sale Cashier', $counterRole, $this->quickTerminal, [$this->quickTerminal]);

        $this->takeawayShift = $this->openShift($c, $this->takeawayTerminal, $this->takeawayCashier, self::TAKEAWAY_OPENING);
        $this->quickShift    = $this->openShift($c, $this->quickTerminal, $this->quickCashier, self::QUICK_OPENING);

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeStaff($c, string $key, string $name, int $roleId, ?int $defaultTerminal, array $terminals): int
    {
        $id = (int) $c->table('users')->insertGetId([
            'name' => $name, 'email' => $key . '@shiftscope.test', 'password' => bcrypt('x'),
            'employee_code' => strtoupper(Str::random(6)), 'status' => 'active', 'locale' => 'en',
            'default_branch_id' => $this->branchId, 'default_terminal_id' => $defaultTerminal,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $id]);
        $c->table('branch_user')->insert([
            'branch_id' => $this->branchId, 'user_id' => $id, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($terminals as $terminalId) {
            $c->table('terminal_user')->insert(['terminal_id' => $terminalId, 'user_id' => $id]);
        }

        return $id;
    }

    private function openShift($c, int $terminalId, int $userId, float $opening): int
    {
        return (int) $c->table('shifts')->insertGetId([
            'branch_id' => $this->branchId, 'terminal_id' => $terminalId, 'opened_by_user_id' => $userId,
            'opened_at' => now()->subHours(4), 'business_date' => now()->toDateString(), 'status' => 'open',
            'opening_cash' => $opening, 'total_sales' => 0, 'total_cash' => 0, 'total_card' => 0,
            'total_bank_transfer' => 0, 'total_cheque' => 0, 'total_refunds' => 0,
            'total_discount' => 0, 'total_tax' => 0, 'expected_cash' => $opening,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
