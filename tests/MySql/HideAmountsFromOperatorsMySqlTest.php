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
 * HIDE-AMOUNTS-1 — a branch can make its counter count blind.
 *
 * The point is NOT that the figures are visually hidden. It is that they never reach the page.
 * The Close Branch screen pre-fills the Counted box with the expected cash and puts the same
 * number in `data-expected` for the live difference — so a Blade-level `@if` would leave the
 * amount sitting in the very box the operator types into, and one View Source away. Test 3 is
 * the one that matters: it reads the raw response body.
 *
 * Two switches, and BOTH must move: the branch flag AND the loss of `tenant.shifts.view-amounts`.
 * Test 1 exists because the flag ships off and the permission ships granted to every role — so a
 * tenant that changed nothing must render exactly what it rendered yesterday. That is what
 * protects Khatri Biryani and Kashif Food on the day this deploys.
 *
 * Everything runs over real HTTP. A test that re-derives the figures cannot fail when the page
 * leaks them.
 */
class HideAmountsFromOperatorsMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const EXPECTED_CASH = 224305.00;
    private const TOTAL_SALES   = 270740.00;
    private const TOTAL_CARD    = 46435.00;

    private string $host;
    private int $tenantId;
    private int $branchId;
    private int $otherBranchId;
    private int $terminalId;
    private int $ownerId;
    private int $operatorId;
    private int $shiftId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'hideamt.' . config('tenancy.tenant_base_domain');
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
            $m->table('tenants')->where('tenant_code', 'hideamt')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    private function hideOn(?int $branchId = null): void
    {
        DB::connection('tenant')->table('branches')
            ->where('id', $branchId ?? $this->branchId)
            ->update(['hide_amounts_from_operators' => 1]);
    }

    /** Take the permission away from the operator's role — the owner's second, deliberate step. */
    private function revokeFromOperator(): void
    {
        $c = DB::connection('tenant');
        $permId = $c->table('permissions')->where('name', 'tenant.shifts.view-amounts')
            ->where('guard_name', 'tenant')->value('id');
        $roleId = $c->table('roles')->where('name', 'Counter')->where('guard_name', 'tenant')->value('id');
        $c->table('role_has_permissions')->where('permission_id', $permId)->where('role_id', $roleId)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function visit(int $userId, string $url)
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $res = $this->actingAs(User::on('tenant')->find($userId), 'tenant')
            ->get('http://' . $this->host . $url);

        $this->assertSame(200, $res->getStatusCode(),
            "{$url} must load; got " . $res->getStatusCode() . ' — '
            . Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($res->getContent()))), 300));

        return $res;
    }

    /** Every way the expected cash could appear in a page — formatted, raw, or in an attribute. */
    private function assertBodyHasNoExpectedCash(string $html, string $why): void
    {
        foreach ([
            '224,305.00' => 'the formatted figure',
            '224305.00'  => 'the raw value (data-expected / a pre-filled input)',
            '270,740.00' => 'total sales',
            '46,435.00'  => 'the card breakup',
        ] as $needle => $what) {
            $this->assertStringNotContainsString($needle, $html, "{$why}: {$what} is still in the page");
        }

        $this->assertStringNotContainsString('data-expected="', $html,
            "{$why}: data-expected still hands the amount to the browser");
    }

    /* ── 1. the live tenants ─────────────────────────────────────────────── */

    /**
     * Nothing changed on this tenant: the flag is off and every role still holds the permission.
     * Both screens must show the figures exactly as they did before this feature existed.
     */
    public function test_a_tenant_that_changed_nothing_still_sees_every_figure(): void
    {
        foreach ([$this->ownerId, $this->operatorId] as $userId) {
            $html = $this->visit($userId, '/shifts-close-branch?branch_id=' . $this->branchId)->getContent();
            $this->assertStringContainsString('224,305.00', $html,
                'with the flag off, the expected cash must still be shown to everyone');
            $this->assertStringContainsString('data-expected="', $html,
                'with the flag off, the live difference must still work');
        }
    }

    /** The permission alone changes nothing while the branch flag is off. */
    public function test_revoking_the_permission_alone_hides_nothing(): void
    {
        $this->revokeFromOperator();

        $html = $this->visit($this->operatorId, '/shifts-close-branch?branch_id=' . $this->branchId)->getContent();

        $this->assertStringContainsString('224,305.00', $html,
            'the flag is what hides the figures — the permission on its own must not');
    }

    /* ── 2-3. the feature ────────────────────────────────────────────────── */

    /** THE test. The number must not be anywhere in the response — not even in an attribute. */
    public function test_close_branch_gives_the_operator_nothing_to_read(): void
    {
        $this->hideOn();
        $this->revokeFromOperator();

        $html = $this->visit($this->operatorId, '/shifts-close-branch?branch_id=' . $this->branchId)->getContent();

        $this->assertBodyHasNoExpectedCash($html, 'Close Branch, hidden');
        $this->assertStringContainsString('*****', $html, 'the operator should see the mask');
    }

    /** Same guarantee on the single-terminal close screen. */
    public function test_close_shift_gives_the_operator_nothing_to_read(): void
    {
        $this->hideOn();
        $this->revokeFromOperator();

        $html = $this->visit($this->operatorId, '/shifts/' . $this->shiftId . '/close')->getContent();

        $this->assertBodyHasNoExpectedCash($html, 'Close Shift, hidden');
        $this->assertStringContainsString('*****', $html);
    }

    /** The dashboard tiles follow the same flag. */
    public function test_the_dashboard_tiles_are_masked(): void
    {
        $this->hideOn();
        $this->revokeFromOperator();

        $html = $this->visit($this->operatorId, '/dashboard')->getContent();

        $this->assertStringContainsString('*****', $html, 'the tiles must be masked');
        $this->assertStringNotContainsString('270,740.00', $html, 'a sales figure is still on the dashboard');
    }

    /* ── 4. the admin ────────────────────────────────────────────────────── */

    /** The Owner keeps the permission, so the flag does not touch them. */
    public function test_the_owner_still_sees_everything(): void
    {
        $this->hideOn();
        $this->revokeFromOperator();

        $html = $this->visit($this->ownerId, '/shifts-close-branch?branch_id=' . $this->branchId)->getContent();

        $this->assertStringContainsString('224,305.00', $html, 'the Owner must still see the expected cash');
        $this->assertStringContainsString('data-expected="', $html, 'the Owner keeps the live difference');
        $this->assertStringNotContainsString('*****', $html);
    }

    /* ── 5. blast radius ─────────────────────────────────────────────────── */

    /** One branch restricted must not restrict the other. */
    public function test_the_other_branch_is_unaffected(): void
    {
        $this->hideOn($this->otherBranchId);
        $this->revokeFromOperator();

        $html = $this->visit($this->operatorId, '/shifts-close-branch?branch_id=' . $this->branchId)->getContent();

        $this->assertStringContainsString('224,305.00', $html,
            'hiding branch B must not hide branch A');
    }

    /* ── 6. the system must still know ───────────────────────────────────── */

    /**
     * Hiding the figure from the screen must not blind the system. The shift still closes, the
     * expected cash is still computed server-side, and the variance is still recorded — otherwise
     * counting blind would also mean counting for nothing.
     */
    public function test_closing_still_records_the_shortage(): void
    {
        $this->hideOn();
        $this->revokeFromOperator();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $res = $this->actingAs(User::on('tenant')->find($this->operatorId), 'tenant')
            ->post('http://' . $this->host . '/shifts/' . $this->shiftId . '/close', [
                'counted_cash' => 200000,
            ]);

        $this->assertContains($res->getStatusCode(), [200, 302],
            'a masked operator must still be able to close the shift; got ' . $res->getStatusCode());

        $shift = DB::connection('tenant')->table('shifts')->where('id', $this->shiftId)->first();

        $this->assertSame('closed', $shift->status, 'the shift must actually close');
        $this->assertEquals(200000, (float) $shift->counted_cash, 'the counted figure must be recorded');
        $this->assertEquals(self::EXPECTED_CASH, (float) $shift->expected_cash,
            'the system must still know the expected cash even though the operator never saw it');
    }

    /* ── seeding ─────────────────────────────────────────────────────────── */

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenants')->where('tenant_code', 'hideamt')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'hideamt', 'business_name' => 'Hide Amounts',
            'owner_name' => 'Owner', 'owner_email' => 'owner@hideamt.test',
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

        $planId = $m->table('plans')->where('code', 'hideamt-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'hideamt-plan', 'name' => 'Hide Amounts', 'price' => 0,
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

        $this->branchId      = $this->makeBranch(['name' => 'Main Branch']);
        $this->otherBranchId = $this->makeBranch(['name' => 'Second Branch']);

        $this->terminalId = (int) $c->table('terminals')->insertGetId([
            'branch_id' => $this->branchId, 'code' => 'T1', 'name' => 'Counter 1',
            'requires_shift' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Roles: an Owner who may read money, and a Counter who — after the owner's second step —
        // may not. Both start holding the permission, exactly as the migration leaves them.
        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        $counterRole = $c->table('roles')->where('name', 'Counter')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Counter', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);

        foreach (['tenant.dashboard', 'tenant.shifts.index', 'tenant.shifts.show', 'tenant.shifts.close',
                  'tenant.shifts.close-form', 'tenant.shifts.close-branch', 'tenant.shifts.close-branch-form',
                  'tenant.shifts.view-amounts'] as $name) {
            $c->table('permissions')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'tenant'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            foreach ([$ownerRole, $counterRole] as $roleId) {
                $c->table('role_has_permissions')->updateOrInsert(
                    ['permission_id' => $permId, 'role_id' => $roleId], []
                );
            }
        }

        $this->ownerId    = $this->makeUser($c, 'owner@hideamt.test', 'Owner', $ownerRole);
        $this->operatorId = $this->makeUser($c, 'counter@hideamt.test', 'Counter One', $counterRole);

        foreach ([$this->ownerId, $this->operatorId] as $uid) {
            $c->table('branch_user')->updateOrInsert(
                ['branch_id' => $this->branchId, 'user_id' => $uid],
                ['is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
            $c->table('terminal_user')->updateOrInsert(
                ['terminal_id' => $this->terminalId, 'user_id' => $uid], []
            );
        }

        // One open shift carrying figures distinctive enough that finding them in a page is proof.
        $this->shiftId = (int) $c->table('shifts')->insertGetId([
            'branch_id' => $this->branchId, 'terminal_id' => $this->terminalId,
            'opened_by_user_id' => $this->operatorId,
            'opened_at' => now()->subHours(6), 'status' => 'open',
            'opening_cash' => 0,
            'total_sales' => self::TOTAL_SALES,
            'total_cash' => self::EXPECTED_CASH,
            'total_card' => self::TOTAL_CARD,
            'total_bank_transfer' => 0, 'total_cheque' => 0, 'total_refunds' => 0,
            'total_discount' => 0, 'total_tax' => 0,
            'expected_cash' => self::EXPECTED_CASH,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeUser($c, string $email, string $name, int $roleId): int
    {
        $id = (int) $c->table('users')->insertGetId([
            'name' => $name, 'email' => $email, 'password' => bcrypt('x'),
            'employee_code' => strtoupper(Str::random(6)), 'status' => 'active', 'locale' => 'en',
            'default_branch_id' => $this->branchId, 'default_terminal_id' => $this->terminalId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $roleId, 'model_type' => User::class, 'model_id' => $id,
        ]);

        return $id;
    }
}
