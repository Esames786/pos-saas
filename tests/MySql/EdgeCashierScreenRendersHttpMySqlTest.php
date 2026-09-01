<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-CASHIER-UI-1 — the Branch-Server browser cashier POS page must actually LOAD, over real HTTP,
 * through the real branch_server route → middleware → controller → view-model → Blade.
 *
 * This follows the canonical PosScreenRendersHttpMySqlTest lesson: hundreds of green service tests did not
 * stop a till outage because the guard rebuilt the query instead of loading the real page. A test that
 * reconstructs the expected payload cannot fail when the controller/Blade is wrong; only one that GETs the
 * page can. So this asks for the cashier page the way a browser does — on a genuinely branch_server-booted
 * app (APP_ROLE set before boot, so routes/web.php loads edge_runtime.php) — and insists on a 200 that
 * carries the real operator surface (terminal, grid product, deal tab) and the bootstrap view-model.
 */
class EdgeCashierScreenRendersHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $productId;

    protected function setUp(): void
    {
        // branch_server must be set BEFORE boot so edge_runtime.php routes are registered.
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
        $this->cleanTenant(['edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'combo_components', 'combos', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter One']);
        // DEFAULT-TERMINAL parity: the cashier's assigned terminal is what the page must land on.
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'default_terminal_id' => $this->terminalId, 'employee_code' => 'CASH' . Str::random(4)]);
        $categoryId = $this->makeCategory(['name' => 'Grills']);
        $this->productId = $this->makeProduct($categoryId, ['name' => 'Chicken Tikka', 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 250]);
        $this->makePaymentMethod(['method_type' => 'cash', 'name' => 'Cash']);
        // A deal so the "Deals" tab renders (display-only tab; sells via header + components).
        $comboId = DB::connection('tenant')->table('combos')->insertGetId([
            'branch_id' => null, 'code' => 'DEAL1', 'name' => 'Family Deal', 'price' => 999, 'sort_order' => 0,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('combo_components')->insert([
            'combo_id' => $comboId, 'product_id' => $this->productId, 'quantity' => 1, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 10]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    /** The cashier page loads over real HTTP and carries the real operator surface + view-model. */
    public function test_cashier_pos_page_renders_over_real_http(): void
    {
        $res = $this->get('/edge/local/pos');
        $res->assertOk();

        $html = $res->getContent();
        // The Online operator surface — not an "offline lite" screen.
        $this->assertStringContainsString('<h1>POS</h1>', $html);
        $this->assertStringContainsString('View Tables', $html);
        $this->assertStringContainsString('Review &amp; Pay', $html);
        $this->assertStringContainsString('Preview Bill', $html);
        // Real data flowed through the view-model into the page.
        $this->assertStringContainsString('Counter One', $html);   // the assigned terminal
        $this->assertStringContainsString('Chicken Tikka', $html);  // a grid product
        $this->assertStringContainsString('Family Deal', $html);    // a deal (Deals tab)
        // The bootstrap JSON the JS reads must be present and default-terminal-aware.
        $this->assertStringContainsString('edge-pos-data', $html);
        $this->assertStringContainsString('"defaultTerminalId":' . $this->terminalId, $html);
        $this->assertStringContainsString('"operationalStockReady":true', $html);
    }

    /** DEFAULT-TERMINAL + TERMINAL-SWITCH-AUTH parity: a pinned operator (no change-terminal permission)
     *  is offered ONLY his assigned terminal, even when the branch has other terminals. */
    public function test_pinned_operator_is_offered_only_his_assigned_terminal(): void
    {
        $other = $this->makeTerminal($this->branchId, ['name' => 'Counter Two']);

        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        // The page only presents the assigned terminal for a user without change-terminal permission.
        $this->assertStringContainsString('Counter One', $html);
        $this->assertStringNotContainsString('Counter Two', $html);
        $this->assertStringContainsString('"canChangeTerminal":false', $html);

        // Sanity: the other terminal really exists on the branch (it is withheld by policy, not absence).
        $this->assertSame($this->branchId, (int) DB::connection('tenant')->table('terminals')->where('id', $other)->value('branch_id'));
    }

    /** The cashier page fails closed for an unauthenticated browser — redirected to the local login. */
    public function test_unauthenticated_cashier_page_redirects_to_local_login(): void
    {
        auth('tenant')->logout();
        $this->flushSession();
        $this->get('/edge/local/pos')->assertStatus(302)->assertRedirect('/edge/local/login');
    }
}
