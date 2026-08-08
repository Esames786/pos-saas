<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-LOCAL-POS-1 — the branch-local POS surface through REAL HTTP requests on a branch_server-BOOTED app
 * (APP_ROLE set before boot, so routes/web.php genuinely loads edge_runtime.php; IdentifyTenant remaps the
 * tenant connection via EdgeLocalDatabase exactly as on an appliance — pointed at the MySQL test DB).
 * Proves the terminal-session UX, shift endpoint and the quick_sale/takeaway HTTP flow riding
 * EdgeLocalPosService, with the same refusals as the service layer.
 */
class EdgeLocalPosHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $productId;
    private int $cashMethodId;
    private int $baselineId;

    protected function setUp(): void
    {
        // branch_server must be set BEFORE boot so edge_runtime.php routes are registered.
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();

        // On every request IdentifyTenant copies the edge_local connection onto `tenant` — point it at the
        // MySQL test DB (the runtime name-guard applies to destructive db-init, not the runtime mapping).
        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
             'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
             'password' => config('database.connections.tenant.password')]
        )]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');

        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_meta', 'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'HTTP' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->baselineId = (int) $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 10]])->id;
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

    public function test_full_http_flow_terminals_select_shift_open_and_cash_sale(): void
    {
        // 1. terminal-selection data.
        $terminals = $this->getJson('/edge/local/pos/terminals');
        $terminals->assertOk()
            ->assertJsonPath('branch_id', $this->branchId)
            ->assertJsonPath('operational_stock_ready', true)
            ->assertJsonPath('terminals.0.id', $this->terminalId)
            ->assertJsonPath('terminals.0.open_shift_id', null);

        // 2. shift endpoints refuse until a terminal is selected.
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(422);

        // 3. select the terminal, open the shift.
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])
            ->assertOk()->assertJsonPath('selected_terminal_id', $this->terminalId);
        $open = $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0]);
        $open->assertStatus(201);
        $this->assertTrue(Str::isUlid($open->json('shift_uuid')));
        $this->getJson('/edge/local/pos/shift')->assertOk()->assertJsonPath('shift.id', $open->json('shift_id'));

        // 4. the REAL HTTP cash sale.
        $clientUuid = (string) Str::uuid();
        $payload = [
            'order_type' => 'quick_sale', 'client_uuid' => $clientUuid,
            'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 200, 'tendered_amount' => 500]],
        ];
        $sale = $this->postJson('/edge/local/pos/sales', $payload);
        $sale->assertStatus(201)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('edge_sync_state', 'pending')
            ->assertJsonPath('grand_total', 200)
            ->assertJsonPath('change_amount', 300);
        $saleId = $sale->json('sale_id');
        $this->assertTrue(Str::isUlid($sale->json('sale_uuid')));
        $this->assertStringStartsWith('SO-' . $this->branchId . '-' . $this->terminalId . '-', $sale->json('sale_no'));

        // Edge stock decremented; official tables untouched; attributed to the authenticated cashier.
        $this->assertSame(8.0, $this->edgeOnHand($this->baselineId, $this->productId));
        $this->assertSame(0, DB::connection('tenant')->table('stock_balances')->count());
        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());
        $this->assertSame($this->userId, (int) SalesOrder::on('tenant')->find($saleId)->created_by_user_id);

        // 5. HTTP replay: same client_uuid + intent → same sale, no duplicates, no second decrement.
        $replay = $this->postJson('/edge/local/pos/sales', $payload);
        $replay->assertStatus(201)->assertJsonPath('sale_id', $saleId);
        $this->assertSame(1, SalesOrder::on('tenant')->count());
        $this->assertSame(8.0, $this->edgeOnHand($this->baselineId, $this->productId));

        // 6. refusals through HTTP: dine_in not yet available; non-cash refused; conflict on changed intent.
        $this->postJson('/edge/local/pos/sales', array_merge($payload, ['client_uuid' => (string) Str::uuid(), 'order_type' => 'dine_in']))->assertStatus(422);
        $card = $this->makePaymentMethod(['method_type' => 'card']);
        $this->postJson('/edge/local/pos/sales', array_merge($payload, ['client_uuid' => (string) Str::uuid(), 'payments' => [['payment_method_id' => $card, 'amount' => 200]]]))->assertStatus(422);
        $this->postJson('/edge/local/pos/sales', array_merge($payload, ['lines' => [['product_id' => $this->productId, 'quantity' => 3]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 300]]]))->assertStatus(409);
    }

    public function test_cross_branch_terminal_cannot_be_selected_over_http(): void
    {
        $foreign = $this->makeTerminal($this->makeBranch());
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $foreign])->assertStatus(422);
    }

    public function test_unauthenticated_pos_request_redirects_to_local_login(): void
    {
        auth('tenant')->logout();
        $this->flushSession();
        $this->get('/edge/local/pos/terminals')->assertStatus(302)->assertRedirect('/edge/local/login');
    }
}
