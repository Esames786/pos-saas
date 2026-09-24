<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W4 (Team 4) — RETURNS + MANAGER APPROVAL parity over the REAL branch_server routes:
 *  - R3.1 the page hides the Return entry without `tenant.sales-returns.store` AND the server refuses every return route;
 *  - R3.2 UserDataScope also fences the return screen + post (a hand-typed sale id outside the scope is refused);
 *  - R3.5 RETURN-MANAGER-APPROVAL over HTTP: branch `manager_required` → refused without approval, the manager approves
 *    with THEIR OWN Edge credential bound to (sale, branch, method, amount), the return posts, the approval is single-use,
 *    and an approval for another amount does not authorise the return;
 *  - R4.1 manager-approval payload validation = Online ManagerApprovalController@verify rules (422 before any credential);
 *  - R3.6 Sales Returns list + detail screens: Online route permissions, UserDataScope, sync state.
 */
class EdgeCashierReturnParityHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalA;
    private int $terminalB;
    private int $userId;
    private int $managerId;
    private string $managerCode;
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
        config(['database.connections.edge_local' => array_merge(config('database.connections.edge_local', []), [
            'host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
            'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'), 'password' => config('database.connections.tenant.password'),
        ])]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_returnable_sale_lines', 'edge_returnable_sales', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'model_has_permissions', 'permissions', 'manager_approvals', 'terminal_user', 'branch_user',
            'sales_return_lines', 'sales_returns', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'sales_return_approval_mode' => 'manager_required']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'RP' . Str::random(4)]);
        $this->managerId = $this->makeUser(['name' => 'Manager Nadia', 'default_branch_id' => $this->branchId, 'employee_code' => 'MG' . Str::random(4)]);
        $this->terminalA = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->terminalB = $this->makeTerminal($this->branchId, ['name' => 'Counter B']);
        $this->productId = $this->makeProduct($this->makeCategory(), ['name' => 'Zinger', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 30]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->seedEdgeCredential($this->managerId, $this->branchId, 1, 'MgrPass1');
        $this->grantEdgePermission($this->managerId, 'tenant.pos.void-kot-item');
        $this->managerCode = (string) User::on('tenant')->find($this->managerId)->employee_code;
        foreach (['tenant.sales-returns.store', 'tenant.sales-returns.index', 'tenant.sales-returns.show'] as $p) {
            $this->grantEdgePermission($this->userId, $p);
        }
        $this->login();
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalA])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 500])->assertStatus(201);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        $this->resetRuntimeRole();
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
        $id = (int) DB::table('permissions')->where('name', $permission)->value('id');
        DB::table('model_has_permissions')->where('model_id', $this->userId)->where('permission_id', $id)->delete();
        $this->login();
    }

    private function cashSale(float $qty = 3): array
    {
        return $this->postJson('/edge/local/pos/sales', ['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(), 'lines' => [['product_id' => $this->productId, 'quantity' => $qty]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100 * $qty]]])->assertStatus(201)->json();
    }

    private function approve(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/edge/local/pos/manager-approvals/verify', ['manager_employee_code' => $this->managerCode, 'manager_credential' => 'MgrPass1', 'action_type' => 'sales_return', 'payload' => $payload]);
    }

    public function test_return_with_manager_approval_over_http_is_bound_and_single_use(): void
    {
        $sale = $this->cashSale(3);
        $saleId = (int) $sale['sale_id'];
        $view = $this->getJson('/edge/local/pos/returns/sales/' . $saleId)->assertOk()->json();
        $this->assertTrue($view['needs_manager_approval']);
        $lineId = (int) $view['lines'][0]['sales_order_line_id'];
        $post = ['sales_order_id' => $saleId, 'refund_method' => 'cash', 'refund_amount' => 100, 'lines' => [['sales_order_line_id' => $lineId, 'quantity' => 1]]];

        // manager_required: refused without an approval (Online message).
        $this->postJson('/edge/local/pos/returns', $post)->assertStatus(422)->assertJsonPath('message', 'Manager approval is required to post a return at this branch.');

        // An approval for ANOTHER amount does not authorise this return (binding).
        $wrong = $this->approve(['sales_order_id' => $saleId, 'branch_id' => $this->branchId, 'refund_method' => 'cash', 'refund_amount' => 50])->assertStatus(201)->json('approval_id');
        $this->postJson('/edge/local/pos/returns', $post + ['manager_approval_id' => $wrong])->assertStatus(422)->assertJsonPath('message', 'Manager approval does not match this action.');
        $this->assertSame(0, DB::table('sales_returns')->count());

        // The bound approval posts the return, once.
        $ok = $this->approve(['sales_order_id' => $saleId, 'branch_id' => $this->branchId, 'refund_method' => 'cash', 'refund_amount' => 100])->assertStatus(201)->json('approval_id');
        $r = $this->postJson('/edge/local/pos/returns', $post + ['manager_approval_id' => $ok])->assertStatus(201)->json('return');
        $this->assertSame(100.0, (float) $r['refund_amount']);
        $this->assertStringEndsWith('/edge/local/pos/sales-returns/' . $r['id'], (string) $r['detail_url']);
        $this->assertNotNull(DB::table('manager_approvals')->where('id', $ok)->value('consumed_at'));
        $envelope = json_decode((string) DB::table('edge_sync_outbox')->where('sale_uuid', $r['return_uuid'])->value('envelope'), true);
        $this->assertSame($this->managerId, (int) data_get($envelope, 'approval.approved_by_user_id'), 'the approval audit rides the return event');
        // single use
        $this->postJson('/edge/local/pos/returns', $post + ['manager_approval_id' => $ok])->assertStatus(422)->assertJsonPath('message', 'This manager approval has already been used.');
        $this->assertSame(1, DB::table('sales_returns')->count());
    }

    public function test_manager_approval_payload_is_validated_like_online_before_any_credential_check(): void
    {
        $bad = [
            ['discount_type' => 'bogus'],
            ['discount_value' => 0],
            ['refund_amount' => -1],
            ['branch_id' => 999999],
            ['cancellations' => [['line_id' => 'x', 'quantity' => 1]]],
            ['quantity' => 0],
        ];
        foreach ($bad as $payload) {
            $this->postJson('/edge/local/pos/manager-approvals/verify', ['manager_employee_code' => $this->managerCode, 'manager_credential' => 'MgrPass1', 'action_type' => 'sales_return', 'payload' => $payload])
                ->assertStatus(422);
        }
        $this->assertSame(0, DB::table('manager_approvals')->count(), 'a malformed binding never mints an approval');
        // a well-formed payload still works (identity model unchanged: the manager's own Edge credential)
        $this->approve(['sales_order_id' => 0, 'branch_id' => $this->branchId, 'refund_method' => 'cash', 'refund_amount' => 10])->assertStatus(201);
    }

    public function test_return_routes_refuse_without_the_permission_and_the_page_hides_the_entry(): void
    {
        $sale = $this->cashSale(1);
        $this->assertStringContainsString('"canSalesReturn":true', $this->get('/edge/local/pos')->assertOk()->getContent());
        $this->revoke('tenant.sales-returns.store');
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        $this->assertStringContainsString('"canSalesReturn":false', $html, 'the page flag hides the Return entry (Online @can)');
        $this->getJson('/edge/local/pos/returns/search?q=')->assertForbidden();
        $this->getJson('/edge/local/pos/returns/sales/' . $sale['sale_id'])->assertForbidden();
        $this->postJson('/edge/local/pos/returns', ['sales_order_id' => $sale['sale_id'], 'refund_method' => 'cash', 'lines' => [['sales_order_line_id' => 1, 'quantity' => 1]]])->assertForbidden();
        $this->assertSame(0, DB::table('sales_returns')->count());
    }

    public function test_user_data_scope_fences_the_return_screen_and_post_and_the_returns_list(): void
    {
        DB::table('branches')->where('id', $this->branchId)->update(['sales_return_approval_mode' => 'auto_approve']);
        $sale = $this->cashSale(2);
        $saleId = (int) $sale['sale_id'];
        $lineId = (int) $this->getJson('/edge/local/pos/returns/sales/' . $saleId)->assertOk()->json('lines.0.sales_order_line_id');
        $r = $this->postJson('/edge/local/pos/returns', ['sales_order_id' => $saleId, 'refund_method' => 'cash', 'refund_amount' => 100, 'reason' => 'late', 'lines' => [['sales_order_line_id' => $lineId, 'quantity' => 1]]])->assertStatus(201)->json('return');

        // Returns list + detail (Online index/show): columns, sync state, the posted return.
        $list = $this->get('/edge/local/pos/sales-returns')->assertOk()->getContent();
        foreach (['id="sales-return-table"', 'Return No', 'Refund Method', $r['return_no'], (string) $sale['sale_no'], 'Pending sync', 'id="sales-return-view-' . $r['id'] . '"', 'id="sales-return-today"'] as $n) {
            $this->assertStringContainsString($n, $list, "the returns list must carry {$n}");
        }
        $this->assertStringContainsString($r['return_no'], $this->get('/edge/local/pos/sales-returns?range=today')->assertOk()->getContent());
        $this->assertStringNotContainsString($r['return_no'], $this->get('/edge/local/pos/sales-returns?date_from=2001-01-01&date_to=2001-01-02')->assertOk()->getContent());
        $detail = $this->get('/edge/local/pos/sales-returns/' . $r['id'])->assertOk()->getContent();
        foreach (['Return Details', 'Original Sale', 'Refund Amount', '100.00', 'late', 'Return Lines', 'Zinger'] as $n) {
            $this->assertStringContainsString($n, $detail, "the return detail must carry {$n}");
        }

        // Scoped to the OTHER counter: the sale is outside the operator's scope — screen, post, list, detail all fenced.
        DB::table('terminal_user')->insert(['user_id' => $this->userId, 'terminal_id' => $this->terminalB, 'is_default' => 1]);
        $this->login();
        $this->getJson('/edge/local/pos/returns/sales/' . $saleId)->assertStatus(422);
        $this->postJson('/edge/local/pos/returns', ['sales_order_id' => $saleId, 'refund_method' => 'cash', 'refund_amount' => 100, 'lines' => [['sales_order_line_id' => $lineId, 'quantity' => 1]]])->assertStatus(422);
        $this->assertSame(1, DB::table('sales_returns')->count());
        $this->assertStringNotContainsString($r['return_no'], $this->get('/edge/local/pos/sales-returns')->assertOk()->getContent());
        $this->get('/edge/local/pos/sales-returns/' . $r['id'])->assertNotFound();

        // Online route permissions on the screens.
        DB::table('terminal_user')->delete();
        $this->revoke('tenant.sales-returns.show');
        $this->get('/edge/local/pos/sales-returns/' . $r['id'])->assertForbidden();
        $this->assertStringNotContainsString('id="sales-return-view-', $this->get('/edge/local/pos/sales-returns')->assertOk()->getContent());
        $this->revoke('tenant.sales-returns.index');
        $this->get('/edge/local/pos/sales-returns')->assertForbidden();
        $this->assertFalse($this->getJson('/edge/local/pos/returns/search?q=')->assertOk()->json('can_view_list'));
    }
}
