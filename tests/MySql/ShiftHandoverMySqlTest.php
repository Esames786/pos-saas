<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Sales\ShiftService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * SHIFT-HANDOVER-1 — the counter changes hands while orders are still open.
 *
 * A live restaurant's Delivery counter changes cashier every evening; the outgoing cashier had to wait for
 * every order still out with a rider, because a shift owning open work cannot close and the next
 * cashier cannot open the same counter until it does. Now, with the tenant's switch on, the outgoing
 * cashier hands in his own cash and opens the next cashier's shift on the same counter — the next
 * cashier types his own password — and the open orders and tables move into it.
 *
 * Both close screens do it (Close Shift and Close Branch), through the real routes, middleware and
 * permissions. With the switch off both screens behave exactly as before.
 */
class ShiftHandoverMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;
    private int $tenantId;
    private int $branchId;
    private int $deliveryTerminal;
    private int $dineInTerminal;
    private int $morningId;      // Delivery Desk — leaving
    private int $eveningId;      // the next cashier on the same counter
    private int $dineInCashierId; // works another counter only
    private int $shiftId;        // the morning shift, Delivery counter
    private int $heldId;
    private int $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'handover.' . config('tenancy.tenant_base_domain');
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
            $m->table('tenants')->where('tenant_code', 'handover')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    /* ── Close Shift ─────────────────────────────────────────────────────── */

    public function test_with_the_switch_off_both_screens_and_the_close_are_unchanged(): void
    {
        $this->setSwitch(false);

        $page = $this->visit($this->morningId, '/shifts/' . $this->shiftId . '/close');
        $this->assertStringNotContainsString('data-handover', $page, 'no new panel for a tenant that did not switch it on');
        $branch = $this->visit($this->morningId, '/shifts-close-branch?branch_id=' . $this->branchId);
        $this->assertStringNotContainsString('data-handover', $branch);

        $this->postClose(['handover' => ['user_id' => $this->eveningId, 'password' => 'evening', 'opening_cash' => '0']]);

        $this->assertSame('open', $this->shift($this->shiftId)->status, 'a hand-made handover is ignored: the open order still blocks');
        $this->assertSame(1, $this->shiftsOnDelivery(), 'and no shift was opened for anyone');
        $this->assertSame($this->shiftId, (int) $this->tenantDb()->table('sales_orders')->where('id', $this->heldId)->value('shift_id'));
    }

    public function test_the_close_screen_lists_the_open_orders_and_who_can_take_the_counter(): void
    {
        $page = $this->visit($this->morningId, '/shifts/' . $this->shiftId . '/close');

        $this->assertStringContainsString('data-handover', $page);
        $this->assertStringContainsString('HS-HANDOVER-1', $page, 'the open order is listed');
        $this->assertStringContainsString('Evening Cashier', $page, 'a user of this counter is offered');
        $this->assertStringNotContainsString('Dine In Cashier', $page, 'a user of another counter is not');
        $this->assertStringNotContainsString('>Delivery Desk</option>', $page, 'nor the cashier who is leaving');
        $this->assertMatchesRegularExpression('/<option value="' . $this->eveningId . '" data-hidden="0"/', $page,
            'the evening cashier sees every one of these orders');
    }

    public function test_the_screen_warns_when_the_next_cashier_would_not_see_an_order(): void
    {
        $this->makeSale($this->branchId, [
            'sale_no' => 'HS-HANDOVER-DINE', 'status' => 'held', 'order_type' => 'dine_in', 'grand_total' => 900,
            'terminal_id' => $this->deliveryTerminal, 'shift_id' => $this->shiftId,
            'business_date' => $this->shift($this->shiftId)->business_date->toDateString(),
        ]);

        $page = $this->visit($this->morningId, '/shifts/' . $this->shiftId . '/close');
        $this->assertMatchesRegularExpression('/<option value="' . $this->eveningId . '" data-hidden="1"/', $page,
            'his order types are delivery and takeaway, so the dine-in order would be invisible to him');
    }

    public function test_the_close_hands_the_counter_and_its_open_orders_to_the_next_cashier(): void
    {
        $before = $this->shift($this->shiftId);

        $res = $this->postClose([
            'counted_cash' => (string) $before->expected_cash,
            'closing_notes' => 'Evening change',
            'handover' => ['user_id' => $this->eveningId, 'password' => 'evening', 'opening_cash' => '500'],
        ]);
        $res->assertRedirect('/shifts/' . $this->shiftId);
        $res->assertSessionHas('status', fn ($s) => str_contains($s, 'Evening Cashier'));

        $old = $this->shift($this->shiftId);
        $this->assertSame('closed', $old->status);
        $this->assertEqualsWithDelta((float) $before->expected_cash, (float) $old->counted_cash, 0.001, 'closed on his own count');
        $this->assertEqualsWithDelta(0.0, (float) $old->cash_variance, 0.001, 'and his own cash only — the open order has none');
        $this->assertStringContainsString('Handed over to Evening Cashier', (string) $old->closing_notes);
        $this->assertStringContainsString('Evening change', (string) $old->closing_notes);

        $new = Shift::on('tenant')->where('terminal_id', $this->deliveryTerminal)->where('status', 'open')->sole();
        $this->assertSame($this->eveningId, (int) $new->opened_by_user_id, 'the new shift is the next cashier\'s');
        $this->assertEqualsWithDelta(500.0, (float) $new->opening_cash, 0.001);
        $this->assertSame($old->business_date->toDateString(), $new->business_date->toDateString(), 'the same business day');

        $order = $this->tenantDb()->table('sales_orders')->where('id', $this->heldId)->first();
        $this->assertSame($new->id, (int) $order->shift_id, 'the open order moved into the new shift');
        $this->assertSame($this->deliveryTerminal, (int) $order->terminal_id, 'its counter did not change');
        $this->assertSame($old->business_date->toDateString(), (string) $order->business_date, 'nor its date');
        $this->assertSame('held', $order->status);
        $this->assertSame($new->id, (int) $this->tenantDb()->table('restaurant_table_sessions')->where('id', $this->sessionId)->value('opened_shift_id'),
            'the open table moved too');

        $svc = app(ShiftService::class);
        $this->assertFalse($svc->canClose($new), 'the new shift owns the open work now, so it cannot close past it');
        $this->assertTrue($svc->canClose($old->fresh()), 'and the old one owns nothing');
        $this->assertSame($new->id, DB::connection('tenant')->transaction(
            fn () => $svc->lockOpenShiftForTerminal(Terminal::on('tenant')->find($this->deliveryTerminal))->id
        ), 'paying the order at this counter now lands in the new cashier\'s drawer');
    }

    public function test_a_wrong_password_changes_nothing(): void
    {
        $res = $this->postClose([
            'counted_cash' => '0',
            'handover' => ['user_id' => $this->eveningId, 'password' => 'guess', 'opening_cash' => '0'],
        ]);
        $res->assertSessionHasErrors();
        $this->assertStringContainsString('not Evening Cashier\'s password', session('errors')->first());

        $this->assertSame('open', $this->shift($this->shiftId)->status);
        $this->assertSame(1, $this->shiftsOnDelivery());
        $this->assertSame($this->shiftId, (int) $this->tenantDb()->table('sales_orders')->where('id', $this->heldId)->value('shift_id'));
    }

    public function test_the_counter_cannot_be_handed_to_a_user_of_another_counter(): void
    {
        $res = $this->postClose([
            'counted_cash' => '0',
            'handover' => ['user_id' => $this->dineInCashierId, 'password' => 'dinein', 'opening_cash' => '0'],
        ]);
        $res->assertSessionHasErrors();
        $this->assertStringContainsString('works on this counter', session('errors')->first());
        $this->assertSame('open', $this->shift($this->shiftId)->status);
        $this->assertSame(0, Shift::on('tenant')->where('opened_by_user_id', $this->dineInCashierId)->count());
    }

    public function test_after_midnight_the_open_orders_must_be_settled_first(): void
    {
        $yesterday = now('Asia/Karachi')->subDay()->toDateString();
        $this->tenantDb()->table('shifts')->where('id', $this->shiftId)->update(['business_date' => $yesterday]);

        $page = $this->visit($this->morningId, '/shifts/' . $this->shiftId . '/close');
        $this->assertStringContainsString('past midnight', $page);
        $this->assertStringNotContainsString('name="handover[user_id]"', $page, 'no next cashier to choose');

        $res = $this->postClose([
            'counted_cash' => '0',
            'handover' => ['user_id' => $this->eveningId, 'password' => 'evening', 'opening_cash' => '0'],
        ]);
        $res->assertSessionHasErrors();
        $this->assertStringContainsString('next day', session('errors')->first());
        $this->assertSame('open', $this->shift($this->shiftId)->status);
        $this->assertSame(1, $this->shiftsOnDelivery());
    }

    public function test_without_a_next_cashier_the_close_is_still_refused(): void
    {
        $res = $this->postClose(['counted_cash' => '0']);
        $res->assertSessionHasErrors();
        $this->assertStringContainsString('Settle all open work', session('errors')->first());
        $this->assertSame('open', $this->shift($this->shiftId)->status);
    }

    /* ── Close Branch ────────────────────────────────────────────────────── */

    public function test_close_branch_hands_over_the_busy_counter_and_closes_the_rest(): void
    {
        $dineInShift = $this->openShift($this->dineInTerminal, $this->dineInCashierId);
        $morning = $this->shift($this->shiftId);

        $page = $this->visit($this->ownerId, '/shifts-close-branch?branch_id=' . $this->branchId);
        $this->assertStringContainsString('handover[' . $this->shiftId . '][user_id]', $page, 'the busy counter has its own handover row');
        $this->assertStringContainsString('name="counted[' . $dineInShift . ']"', $page, 'the free counter is listed as usual');
        $this->assertStringNotContainsString('handover[' . $dineInShift . '][user_id]', $page, 'the free counter has none');

        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->post('http://' . $this->host . '/shifts-close-branch', [
                'branch_id' => $this->branchId, 'mode' => 'per_terminal',
                'counted' => [$this->shiftId => (string) $morning->expected_cash, $dineInShift => '0'],
                'handover' => [$this->shiftId => ['user_id' => $this->eveningId, 'password' => 'evening', 'opening_cash' => '0']],
            ]);
        $res->assertRedirect('/shifts');
        $res->assertSessionHas('status', fn ($s) => str_contains($s, '2 shift(s) closed') && str_contains($s, 'Evening Cashier'));

        $this->assertSame('closed', $this->shift($this->shiftId)->status);
        $this->assertSame('closed', $this->shift($dineInShift)->status);
        $new = Shift::on('tenant')->where('terminal_id', $this->deliveryTerminal)->where('status', 'open')->sole();
        $this->assertSame($this->eveningId, (int) $new->opened_by_user_id);
        $this->assertSame($new->id, (int) $this->tenantDb()->table('sales_orders')->where('id', $this->heldId)->value('shift_id'));
        $this->assertSame(0, Shift::on('tenant')->where('terminal_id', $this->dineInTerminal)->where('status', 'open')->count());
    }

    public function test_close_branch_without_a_handover_still_closes_nothing(): void
    {
        $dineInShift = $this->openShift($this->dineInTerminal, $this->dineInCashierId);

        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->post('http://' . $this->host . '/shifts-close-branch', [
                'branch_id' => $this->branchId, 'mode' => 'per_terminal',
                'counted' => [$this->shiftId => '0', $dineInShift => '0'],
            ]);
        $res->assertSessionHasErrors();

        $this->assertSame('open', $this->shift($this->shiftId)->status, 'all or nothing, as before');
        $this->assertSame('open', $this->shift($dineInShift)->status);
    }

    public function test_close_branch_with_one_total_records_the_closed_drawers_only(): void
    {
        $this->tenantDb()->table('shifts')->where('id', $this->shiftId)->update(['expected_cash' => 1200, 'total_cash' => 1200, 'total_sales' => 1200]);
        $dineInShift = $this->openShift($this->dineInTerminal, $this->dineInCashierId);
        $this->tenantDb()->table('shifts')->where('id', $dineInShift)->update(['expected_cash' => 800, 'total_cash' => 800, 'total_sales' => 800]);

        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->post('http://' . $this->host . '/shifts-close-branch', [
                'branch_id' => $this->branchId, 'mode' => 'branch_total', 'branch_counted_cash' => '2000',
                'handover' => [$this->shiftId => ['user_id' => $this->eveningId, 'password' => 'evening', 'opening_cash' => '0']],
            ]);
        $res->assertRedirect('/shifts');

        $this->assertSame('closed', $this->shift($this->shiftId)->status);
        $this->assertEqualsWithDelta(1200.0, (float) $this->shift($this->shiftId)->counted_cash, 0.001, 'closed at its expected, like every terminal in this mode');
        $daily = $this->tenantDb()->table('daily_closings')->where('branch_id', $this->branchId)->whereNull('terminal_id')->first();
        $this->assertNotNull($daily);
        $this->assertEqualsWithDelta(2000.0, (float) $daily->expected_cash, 0.001, 'both closed drawers, nothing from the new shift');
        $this->assertEqualsWithDelta(0.0, (float) $daily->cash_variance, 0.001);
        $this->assertSame(1, Shift::on('tenant')->where('terminal_id', $this->deliveryTerminal)->where('status', 'open')->count());
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    private function visit(int $userId, string $url): string
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $res = $this->actingAs(User::on('tenant')->find($userId), 'tenant')->get('http://' . $this->host . $url);
        $this->assertSame(200, $res->getStatusCode(), "{$url} must load; got " . $res->getStatusCode() . ' — '
            . Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($res->getContent()))), 300));

        return $res->getContent();
    }

    private function postClose(array $body)
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->find($this->morningId), 'tenant')
            ->from('http://' . $this->host . '/shifts/' . $this->shiftId . '/close')
            ->post('http://' . $this->host . '/shifts/' . $this->shiftId . '/close', $body);
    }

    private function shift(int $id): Shift
    {
        return Shift::on('tenant')->findOrFail($id);
    }

    private function shiftsOnDelivery(): int
    {
        return Shift::on('tenant')->where('terminal_id', $this->deliveryTerminal)->count();
    }

    private function tenantDb()
    {
        return DB::connection('tenant');
    }

    private function setSwitch(bool $on): void
    {
        DB::connection('master')->table('tenants')->where('id', $this->tenantId)
            ->update(['features' => json_encode(['shift_handover' => $on])]);
        if (app()->bound('tenant')) {
            app('tenant')->features = ['shift_handover' => $on];
        }
    }

    private function openShift(int $terminalId, int $userId): int
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('tenant');
        try {
            return app(ShiftService::class)->open(
                Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($terminalId), $userId, 0.0
            )->id;
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenants')->where('tenant_code', 'handover')->delete();

        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'handover', 'business_name' => 'Handover', 'owner_name' => 'Owner', 'owner_email' => 'owner@handover.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0, 'features' => json_encode(['shift_handover' => true]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant', 'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'), 'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert(['tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seedSubscription(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');
        $planId = $m->table('plans')->where('code', 'handover-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'handover-plan', 'name' => 'Handover', 'price' => 0, 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach (['tenant.pos.index', 'tenant.dashboard', 'tenant.shifts.index'] as $routeName) {
            $key = $m->table('route_catalogs')->where('route_name', $routeName)->value('module_key');
            $module = $key ? Module::forRouteModuleKey($key)->first() : null;
            if ($module) {
                $m->table('plan_modules')->updateOrInsert(['plan_id' => $planId, 'module_id' => $module->id], ['is_enabled' => 1]);
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
        $this->cleanTenant([
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'daily_closings',
            'sales_order_lines', 'sales_orders', 'shifts', 'terminal_user', 'branch_user',
            'model_has_roles', 'users', 'terminals', 'branches',
        ]);
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $this->branchId = $this->makeBranch(['name' => 'Main Branch']);
        $this->deliveryTerminal = $this->makeTerminal($this->branchId, ['code' => 'T1', 'name' => 'Delivery']);
        $this->dineInTerminal = $this->makeTerminal($this->branchId, ['code' => 'T2', 'name' => 'Dine In']);

        $role = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId(['name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['tenant.dashboard', 'tenant.shifts.index', 'tenant.shifts.show', 'tenant.shifts.close',
                  'tenant.shifts.close-form', 'tenant.shifts.close-branch', 'tenant.shifts.close-branch-form',
                  'tenant.shifts.view-amounts'] as $name) {
            $c->table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'tenant'], ['created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $role], []);
        }

        $this->ownerId = $this->user($c, 'Owner', 'owner', $role, []);
        $this->morningId = $this->user($c, 'Delivery Desk', 'morning', $role, [$this->deliveryTerminal], ['delivery', 'takeaway']);
        $this->eveningId = $this->user($c, 'Evening Cashier', 'evening', $role, [$this->deliveryTerminal], ['delivery', 'takeaway']);
        $this->dineInCashierId = $this->user($c, 'Dine In Cashier', 'dinein', $role, [$this->dineInTerminal], ['dine_in']);

        $this->shiftId = $this->openShift($this->deliveryTerminal, $this->morningId);
        DB::setDefaultConnection('tenant');
        $shift = Shift::on('tenant')->find($this->shiftId);

        // An order still out with a rider, and a table still seated.
        $this->heldId = $this->makeSale($this->branchId, [
            'sale_no' => 'HS-HANDOVER-1', 'status' => 'held', 'order_type' => 'delivery', 'grand_total' => 1770,
            'terminal_id' => $this->deliveryTerminal, 'shift_id' => $this->shiftId,
            'business_date' => $shift->business_date->toDateString(),
        ]);
        $tableId = $this->makeTable($this->branchId, ['table_no' => '7']);
        $this->sessionId = (int) $c->table('restaurant_table_sessions')->insertGetId([
            'session_no' => 'TS-' . Str::upper(Str::random(10)), 'branch_id' => $this->branchId, 'restaurant_table_id' => $tableId,
            'status' => 'open', 'guest_count' => 2, 'opened_by_user_id' => $this->morningId, 'opened_at' => now(),
            'opened_shift_id' => $this->shiftId, 'business_date' => $shift->business_date->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function user($c, string $name, string $password, int $roleId, array $terminals, array $types = []): int
    {
        $id = (int) $c->table('users')->insertGetId([
            'name' => $name, 'email' => Str::slug($name) . '@handover.test', 'password' => bcrypt($password),
            'employee_code' => strtoupper(Str::random(6)), 'status' => 'active', 'locale' => 'en',
            'default_branch_id' => $this->branchId, 'allowed_order_types' => $types ? json_encode($types) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $id]);
        $c->table('branch_user')->insert(['branch_id' => $this->branchId, 'user_id' => $id, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($terminals as $t) {
            $c->table('terminal_user')->insert(['terminal_id' => $t, 'user_id' => $id]);
        }

        return $id;
    }
}
