<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W0b — SAFETY-CRITICAL PARITY GATES (owner directive 20 Sep 2026; audit E-05 / E-06 / R1.1 / R1.4 / R3.2 / D-23).
 *
 * Online gates every operator route by its route-name permission (EnsureRoutePermission) and scopes sales data by
 * UserDataScope. The audit found the Edge endpoints for the same actions were reachable with only `edge.auth` +
 * `edge.branch`, the terminal pin was page-only, and `GET /shift` echoed amounts a blind-count operator must not see.
 * These tests prove, over the REAL branch_server routes, that the Edge endpoints now refuse exactly what Online refuses
 * — and still allow exactly what Online allows once the permission / assignment is present.
 */
class EdgeCashierRouteGatesHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalA;
    private int $terminalB;
    private int $userId;
    private int $tableId;
    private int $productId;
    private int $cashMethodId;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();

        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
                'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
                'password' => config('database.connections.tenant.password')]
        )]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');

        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta',
            'kot_batch_lines', 'kot_batches', 'print_jobs', 'manager_approvals', 'model_has_permissions', 'permissions',
            'terminal_user', 'branch_user',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories',
            'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->terminalA = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->terminalB = $this->makeTerminal($this->branchId, ['name' => 'Counter B']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'GATE' . Str::random(4)]);
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'available']);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1); // holds the full Online cashier set
        $this->login();
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    private function login(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    private function revoke(string $permission): void
    {
        $permId = (int) DB::connection('tenant')->table('permissions')->where('name', $permission)->where('guard_name', 'tenant')->value('id');
        DB::connection('tenant')->table('model_has_permissions')->where('model_id', $this->userId)->where('permission_id', $permId)->delete();
        $this->login();
    }

    private function grant(string $permission): void
    {
        $this->grantEdgePermission($this->userId, $permission);
        $this->login();
    }

    /** Every Online route permission gates the matching Edge endpoint — refused without it, allowed with it. */
    public function test_online_route_permissions_gate_the_edge_endpoints(): void
    {
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalA])->assertOk();

        // the POS page itself (tenant.pos.index)
        $this->revoke('tenant.pos.index');
        $this->get('/edge/local/pos')->assertStatus(403);
        $this->grant('tenant.pos.index');
        $this->get('/edge/local/pos')->assertOk();

        // open shift (tenant.shifts.store)
        $this->revoke('tenant.shifts.store');
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(403)->assertJsonPath('permission', 'tenant.shifts.store');
        $this->grant('tenant.shifts.store');
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);

        // open table (tenant.restaurant.table-sessions.open)
        $this->revoke('tenant.restaurant.table-sessions.open');
        $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->assertStatus(403)->assertJsonPath('permission', 'tenant.restaurant.table-sessions.open');
        $this->grant('tenant.restaurant.table-sessions.open');
        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->assertStatus(201)->json('session_id');

        // hold (tenant.held-sales.store)
        $hold = ['order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId, 'lines' => [['product_id' => $this->productId, 'quantity' => 2]]];
        $this->revoke('tenant.held-sales.store');
        $this->postJson('/edge/local/pos/held-sales', $hold)->assertStatus(403)->assertJsonPath('permission', 'tenant.held-sales.store');
        $this->grant('tenant.held-sales.store');
        $held = $this->postJson('/edge/local/pos/held-sales', $hold)->assertStatus(201);
        $heldId = (int) $held->json('sale_id');
        $lineId = (int) $held->json('lines.0.id');

        // split (tenant.sales-orders.split-bill.store)
        $this->revoke('tenant.sales-orders.split-bill.store');
        $this->postJson("/edge/local/pos/held-sales/{$heldId}/split", ['lines' => [['sales_order_line_id' => $lineId, 'quantity' => 1]]])->assertStatus(403)->assertJsonPath('permission', 'tenant.sales-orders.split-bill.store');
        $this->grant('tenant.sales-orders.split-bill.store');

        // manager approval request (tenant.api.manager-approvals.verify) — refused BEFORE any credential is checked
        $this->revoke('tenant.api.manager-approvals.verify');
        $this->postJson('/edge/local/pos/manager-approvals/verify', ['manager_employee_code' => 'X', 'manager_credential' => 'y', 'action_type' => 'manual_discount'])->assertStatus(403)->assertJsonPath('permission', 'tenant.api.manager-approvals.verify');
        $this->grant('tenant.api.manager-approvals.verify');
        $this->postJson('/edge/local/pos/manager-approvals/verify', ['manager_employee_code' => 'X', 'manager_credential' => 'y', 'action_type' => 'manual_discount'])->assertStatus(422);

        // cancel order (tenant.held-sales.cancel)
        $this->revoke('tenant.held-sales.cancel');
        $this->postJson("/edge/local/pos/held-sales/{$heldId}/cancel", ['reason_id' => 1])->assertStatus(403)->assertJsonPath('permission', 'tenant.held-sales.cancel');
        $this->grant('tenant.held-sales.cancel');

        // close table session (tenant.restaurant.table-sessions.close)
        $this->revoke('tenant.restaurant.table-sessions.close');
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/close", ['status' => 'closed'])->assertStatus(403)->assertJsonPath('permission', 'tenant.restaurant.table-sessions.close');
        $this->grant('tenant.restaurant.table-sessions.close');

        // close shift (tenant.shifts.close)
        $this->revoke('tenant.shifts.close');
        $this->postJson('/edge/local/pos/shift/close', ['counted_cash' => 0])->assertStatus(403)->assertJsonPath('permission', 'tenant.shifts.close');
        $this->grant('tenant.shifts.close');

        // the held order is untouched by every refusal
        $this->assertSame('held', DB::connection('tenant')->table('sales_orders')->where('id', $heldId)->value('status'));
        $this->assertSame(0, DB::connection('tenant')->table('manager_approvals')->count());
    }

    /** A pinned operator cannot switch terminal on the server; terminal assignments bind even a switch-permitted operator. */
    public function test_terminal_pin_and_assignments_are_enforced_server_side(): void
    {
        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['default_terminal_id' => $this->terminalA]);
        $this->login();

        // pinned (no change-terminal permission): own terminal yes, the other no
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalA])->assertOk();
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalB])->assertStatus(403)->assertJsonPath('permission', 'tenant.pos.change-terminal');

        // with the switch permission the other counter opens
        $this->grant('tenant.pos.change-terminal');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalB])->assertOk();

        // an explicit terminal assignment (Online UserDataScope) narrows even a switch-permitted operator
        DB::connection('tenant')->table('terminal_user')->insert(['user_id' => $this->userId, 'terminal_id' => $this->terminalA, 'is_default' => 1]);
        $this->login();
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalB])->assertStatus(403);
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalA])->assertOk();

        // …and the selection is re-validated on use: assign the operator elsewhere → the stored terminal is refused
        DB::connection('tenant')->table('terminal_user')->where('user_id', $this->userId)->update(['terminal_id' => $this->terminalB]);
        $this->login();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(403);
    }

    /** HIDE-AMOUNTS: `GET /shift` strips the figures for an operator the branch hides them from — same rule as the summary. */
    public function test_shift_status_strips_amounts_for_a_blind_count_operator(): void
    {
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalA])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 500])->assertStatus(201);

        $open = $this->getJson('/edge/local/pos/shift')->assertOk();
        $this->assertTrue($open->json('may_see_amounts'));
        $this->assertSame(500.0, (float) $open->json('shift.expected_cash'));

        DB::connection('tenant')->table('branches')->where('id', $this->branchId)->update(['hide_amounts_from_operators' => 1]);
        $hidden = $this->getJson('/edge/local/pos/shift')->assertOk();
        $this->assertFalse($hidden->json('may_see_amounts'));
        $this->assertNull($hidden->json('shift.expected_cash'));
        $this->assertNull($hidden->json('shift.total_sales'));
        $this->assertNotNull($hidden->json('shift.id'), 'the shift itself is still reported — only the figures are stripped');
        $this->assertStringNotContainsString('500', json_encode($hidden->json('shift')));

        $this->grant('tenant.shifts.view-amounts');
        $this->assertSame(500.0, (float) $this->getJson('/edge/local/pos/shift')->assertOk()->json('shift.expected_cash'));
    }

    /** USER DATA SCOPE: returns search and Recent Prints follow the operator's terminal assignments like Online. */
    public function test_returns_search_and_print_jobs_follow_the_operator_data_scope(): void
    {
        $this->grant('tenant.sales-returns.store');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalA])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
        $sale = $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100, 'tendered_amount' => 100]],
        ])->assertStatus(201);
        $saleId = (int) $sale->json('sale_id');
        $this->postJson("/edge/local/pos/sales/{$saleId}/receipt", [])->assertStatus(201);

        // unscoped operator: finds the sale and its print job
        $this->assertCount(1, $this->getJson('/edge/local/pos/returns/search?q=')->assertOk()->json('sales'));
        $this->assertGreaterThan(0, count($this->getJson('/edge/local/pos/print-jobs')->assertOk()->json('jobs')));

        // scoped to the OTHER counter: nothing from Counter A is visible (search, print jobs)
        DB::connection('tenant')->table('terminal_user')->insert(['user_id' => $this->userId, 'terminal_id' => $this->terminalB, 'is_default' => 1]);
        $this->login();
        $this->assertSame([], $this->getJson('/edge/local/pos/returns/search?q=')->assertOk()->json('sales'));
        $this->assertSame([], $this->getJson('/edge/local/pos/print-jobs')->assertOk()->json('jobs'));

        // scoped to Counter A: visible again
        DB::connection('tenant')->table('terminal_user')->where('user_id', $this->userId)->update(['terminal_id' => $this->terminalA]);
        $this->login();
        $this->assertCount(1, $this->getJson('/edge/local/pos/returns/search?q=')->assertOk()->json('sales'));
        $this->assertGreaterThan(0, count($this->getJson('/edge/local/pos/print-jobs')->assertOk()->json('jobs')));
    }
}
