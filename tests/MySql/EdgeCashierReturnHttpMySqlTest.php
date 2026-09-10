<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Edge\EdgeReturnEnvelopeBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * F1 — the ACTUAL cashier browser return: the real Branch Server page carries the Return entry point and the Online
 * return UX (unit-aware stepper, partial/full, remaining quantity, refund breakdown, approval prompt); the real routes
 * search a paid sale, return part of it for cash out of the till, refuse a card refund offline with a business message,
 * and refuse a cashier without the return permission.
 */
class EdgeCashierReturnHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
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
        config(['database.connections.edge_local' => array_merge(config('database.connections.edge_local', []), ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'), 'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'), 'password' => config('database.connections.tenant.password')])]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_returnable_sale_lines', 'edge_returnable_sales', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'model_has_permissions', 'permissions', 'manager_approvals',
            'sales_return_lines', 'sales_returns', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CR' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 20]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
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

    private function cashSale(float $qty = 3): array
    {
        return $this->postJson('/edge/local/pos/sales', ['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(), 'lines' => [['product_id' => $this->productId, 'quantity' => $qty]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100 * $qty]]])->assertStatus(201)->json();
    }

    public function test_the_page_carries_the_return_entry_point_and_the_online_return_ux(): void
    {
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        $this->assertStringContainsString('id="returns-btn"', $html);
        foreach (['/returns/search', '/returns/sales/', "'/returns'", 'rt-step', 'qty_step', 'outstanding_delivery', 'needs_manager_approval', 'sales_return', 'needs the Online POS'] as $needle) {
            $this->assertStringContainsString($needle, $html, "the real cashier page must carry {$needle}");
        }
    }

    public function test_a_cashier_returns_part_of_a_sale_for_cash_and_the_till_reflects_it(): void
    {
        $this->grantEdgePermission($this->userId, 'tenant.sales-returns.store');
        $sale = $this->cashSale(3);
        $shiftBefore = (float) DB::table('shifts')->where('status', 'open')->value('expected_cash');

        $found = $this->getJson('/edge/local/pos/returns/search?q=' . urlencode(substr((string) $sale['sale_no'], 0, 8)))->assertOk()->json('sales');
        $this->assertNotEmpty($found);
        $saleId = (int) $found[0]['id'];
        $view = $this->getJson('/edge/local/pos/returns/sales/' . $saleId)->assertOk()->json();
        $this->assertSame(3.0, (float) $view['lines'][0]['returnable']);
        $this->assertSame(1, $view['lines'][0]['qty_step']);
        $this->assertSame('cash', $view['default_refund_method']);
        $this->assertContains('cash', $view['offline_refund_methods']);
        $this->assertTrue($view['can_return']);

        // Card refund offline → business message (ONLINE_REQUIRED), nothing posted.
        $this->postJson('/edge/local/pos/returns', ['sales_order_id' => $saleId, 'refund_method' => 'card', 'refund_amount' => 100, 'lines' => [['sales_order_line_id' => $view['lines'][0]['sales_order_line_id'], 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'needs the Online POS'));
        $this->assertSame(0, DB::table('sales_returns')->count());

        $r = $this->postJson('/edge/local/pos/returns', ['sales_order_id' => $saleId, 'refund_method' => 'cash', 'refund_amount' => 100, 'reason' => 'cold', 'lines' => [['sales_order_line_id' => $view['lines'][0]['sales_order_line_id'], 'quantity' => 1]]])
            ->assertStatus(201)->json('return');
        $this->assertSame(100.0, (float) $r['refund_amount']);
        $this->assertSame('pending', $r['sync']);
        $this->assertSame($shiftBefore - 100.0, (float) DB::table('shifts')->where('status', 'open')->value('expected_cash'));
        $this->assertSame(1, DB::table('edge_sync_outbox')->where('envelope_schema_version', EdgeReturnEnvelopeBuilder::SCHEMA)->count());
        $this->getJson('/edge/local/pos/returns/' . $r['id'])->assertOk()->assertJsonPath('return.return_no', $r['return_no']);
        // Remaining returnable is down at once; the shift summary's expected cash includes the refund.
        $this->assertSame(2.0, (float) $this->getJson('/edge/local/pos/returns/sales/' . $saleId)->assertOk()->json('lines.0.returnable'));
        $this->assertSame(500.0 + 300.0 - 100.0, (float) $this->getJson('/edge/local/pos/shift/summary')->assertOk()->json('breakup.expected_cash'));
    }

    public function test_a_cashier_without_the_return_permission_is_refused(): void
    {
        $sale = $this->cashSale(1);
        $this->getJson('/edge/local/pos/returns/search?q=' . urlencode((string) $sale['sale_no']))->assertStatus(403);
        $this->postJson('/edge/local/pos/returns', ['sales_order_id' => 1, 'refund_method' => 'cash', 'lines' => [['sales_order_line_id' => 1, 'quantity' => 1]]])->assertStatus(403);
        $this->assertSame(0, DB::table('sales_returns')->count());
    }
}
