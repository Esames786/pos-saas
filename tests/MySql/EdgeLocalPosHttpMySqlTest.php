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
        $this->cleanTenant(['edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'HTTP' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->baselineId = (int) $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 10]])->id;
        // Slice 1.1: edge.auth now enforces session FRESHNESS — an actingAs session without a genuine
        // ACTIVE local credential (matching bound branch + epoch) is logged out. Seed what enrollment produces.
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

    // ── slice 1.1: session FRESHNESS — a stale principal is logged out and fails closed ──────────

    /** A/E: user disabled mid-session → refused + session dead; restored user must RE-LOGIN. */
    public function test_freshness_disabled_user_is_logged_out(): void
    {
        $this->getJson('/edge/local/pos/terminals')->assertOk(); // valid session works (E)

        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['status' => 'inactive']);
        $this->getJson('/edge/local/pos/terminals')->assertStatus(401);

        // the middleware LOGGED OUT the stale session — restoring the row does not resurrect it.
        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['status' => 'active']);
        $this->getJson('/edge/local/pos/terminals')->assertStatus(401);

        // a fresh authentication works again.
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        $this->getJson('/edge/local/pos/terminals')->assertOk();
    }

    /** B: branch authorization revoked mid-session (default branch moved, no active assignment) → refused. */
    public function test_freshness_branch_revoked_user_is_logged_out(): void
    {
        $this->getJson('/edge/local/pos/terminals')->assertOk();

        $otherBranch = $this->makeBranch();
        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['default_branch_id' => $otherBranch]);
        $this->getJson('/edge/local/pos/terminals')->assertStatus(401);

        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['default_branch_id' => $this->branchId]);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        $this->getJson('/edge/local/pos/terminals')->assertOk();
    }

    /** C: local credential disabled mid-session → refused even though the Cloud user row is fine. */
    public function test_freshness_disabled_credential_is_logged_out(): void
    {
        $this->getJson('/edge/local/pos/terminals')->assertOk();

        DB::connection('tenant')->table('edge_local_user_credentials')->where('user_id', $this->userId)->update(['status' => 'disabled']);
        $this->getJson('/edge/local/pos/terminals')->assertStatus(401);

        DB::connection('tenant')->table('edge_local_user_credentials')->where('user_id', $this->userId)->update(['status' => 'active']);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        $this->getJson('/edge/local/pos/terminals')->assertOk();
    }

    /** D: activation epoch superseded (re-activation) → every pre-epoch session/credential is stale. */
    public function test_freshness_superseded_activation_epoch_is_logged_out(): void
    {
        $this->getJson('/edge/local/pos/terminals')->assertOk();

        $this->bindEdgeLocalMeta($this->branchId, 2); // appliance re-activated at epoch 2; credential is epoch 1
        $this->getJson('/edge/local/pos/terminals')->assertStatus(401);

        // even re-authenticating does not help until an epoch-2 credential exists.
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        $this->getJson('/edge/local/pos/terminals')->assertStatus(401);
        // re-enrollment REPLACES the user's single credential row at the new epoch (edge_cred_user_unique).
        DB::connection('tenant')->table('edge_local_user_credentials')->where('user_id', $this->userId)
            ->update(['activation_epoch' => 2, 'credential_version' => 2, 'updated_at' => now()]);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        $this->getJson('/edge/local/pos/terminals')->assertOk();
    }

    // ── slice 1.1: complete shift HTTP lifecycle ─────────────────────────────────────────────────

    public function test_shift_close_http_lifecycle_with_variance_and_post_close_refusal(): void
    {
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();

        // close with no open shift → controlled refusal.
        $this->postJson('/edge/local/pos/shift/close', ['counted_cash' => 0])->assertStatus(422);

        $open = $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0]);
        $open->assertStatus(201);
        $shiftId = $open->json('shift_id');

        // one cash sale: APPLIED 100, tendered 500 → expected_cash grows by the APPLIED amount only.
        $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'quick_sale', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100, 'tendered_amount' => 500]],
        ])->assertStatus(201)->assertJsonPath('change_amount', 400);

        $this->assertSame(100.0, (float) DB::connection('tenant')->table('shifts')->where('id', $shiftId)->value('expected_cash'));

        // counted == expected → closed, variance 0 (the SHARED ShiftService::closeShift semantics).
        $close = $this->postJson('/edge/local/pos/shift/close', ['counted_cash' => 100, 'closing_notes' => 'edge close']);
        $close->assertOk()
            ->assertJsonPath('shift_id', $shiftId)
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('expected_cash', 100)
            ->assertJsonPath('counted_cash', 100)
            ->assertJsonPath('cash_variance', 0);
        $row = DB::connection('tenant')->table('shifts')->where('id', $shiftId)->first();
        $this->assertSame('closed', $row->status);
        $this->assertSame($this->userId, (int) $row->closed_by_user_id);
        $this->assertNotNull($row->closed_at);

        // after close: no open shift → a new sale is refused (mandatory-open-shift), and re-close refused.
        $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'quick_sale', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]],
        ])->assertStatus(422);
        $this->postJson('/edge/local/pos/shift/close', ['counted_cash' => 100])->assertStatus(422);
    }

    // ── slice 1.1: takeaway through HTTP + the user's order-type policy is enforced ──────────────

    public function test_takeaway_sale_over_http_and_order_type_restriction_refusal(): void
    {
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);

        $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]],
        ])->assertStatus(201)->assertJsonPath('status', 'paid');
        $this->assertSame(9.0, $this->edgeOnHand($this->baselineId, $this->productId));

        // restrict the cashier to quick_sale only → takeaway is refused THROUGH HTTP (allowsOrderType).
        DB::connection('tenant')->table('users')->where('id', $this->userId)
            ->update(['allowed_order_types' => json_encode(['quick_sale'])]);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]],
        ])->assertStatus(422);
        $this->assertSame(9.0, $this->edgeOnHand($this->baselineId, $this->productId), 'refused sale must not move stock');
    }

    // ── slice 1.1: the whole local flow works with the MASTER database unreachable ───────────────

    public function test_full_flow_works_with_master_database_unavailable(): void
    {
        // point the master connection at a nonexistent database and PROVE it is dead.
        config(['database.connections.master.database' => 'nonexistent_master_slice11']);
        DB::purge('master');
        try {
            DB::connection('master')->select('select 1');
            $this->fail('master must be unreachable in this proof');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }

        // full REAL HTTP flow: terminals → select → shift open → cash sale — no master dependency.
        $this->getJson('/edge/local/pos/terminals')->assertOk()->assertJsonPath('branch_id', $this->branchId);
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
        $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'quick_sale', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 200, 'tendered_amount' => 200]],
        ])->assertStatus(201)->assertJsonPath('status', 'paid');

        // Edge operational stock moved; official Cloud authorities untouched.
        $this->assertSame(8.0, $this->edgeOnHand($this->baselineId, $this->productId));
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count());
        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());

        // close the shift too — the COMPLETE lifecycle holds offline.
        $this->postJson('/edge/local/pos/shift/close', ['counted_cash' => 200])
            ->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('cash_variance', 0);
    }

    // ── slice 1.1: terminals endpoint never echoes a stale selection ─────────────────────────────

    public function test_terminals_endpoint_clears_stale_terminal_selection(): void
    {
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
        $this->getJson('/edge/local/pos/terminals')->assertOk()->assertJsonPath('selected_terminal_id', $this->terminalId);

        // terminal deactivated (e.g. re-provisioned) → the stored selection must be cleared, not echoed.
        DB::connection('tenant')->table('terminals')->where('id', $this->terminalId)->update(['status' => 'inactive']);
        $this->getJson('/edge/local/pos/terminals')->assertOk()
            ->assertJsonPath('selected_terminal_id', null)
            ->assertJsonPath('terminals', []);

        // and shift/sale endpoints refuse instead of operating on the vanished terminal.
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(422);
    }

    // ── slice 1.1: readiness reports a TRUTHFUL local_pos state (never a selling claim) ──────────

    public function test_readiness_reports_truthful_local_pos_state(): void
    {
        // local_auth 'ready' requires the enrollment crypto surface — provide a public key for the report.
        config(['edge.enrollment.public_key' => base64_encode(random_bytes(32))]);

        $ready = $this->getJson('/edge/local/ready');
        $this->assertSame('basic_runtime_ready', $ready->json('local_pos'));
        $this->assertSame('ready', $ready->json('local_auth'));
        // the truthful POS state NEVER flips the global stock/selling verdicts.
        $this->assertSame('not_ready', $ready->json('operational_stock'));
        $this->assertFalse($ready->json('activation_ready'));

        // no accepted baseline → needs_operational_baseline (authority, not schema, is the gate).
        DB::connection('tenant')->table('edge_operational_stock_movements')->delete();
        DB::connection('tenant')->table('edge_operational_stock_balances')->delete();
        DB::connection('tenant')->table('edge_operational_stock_baselines')->delete();
        $this->assertSame('needs_operational_baseline', $this->getJson('/edge/local/ready')->json('local_pos'));

        // credential disabled → local_auth degrades and local_pos fails closed with it.
        DB::connection('tenant')->table('edge_local_user_credentials')->where('user_id', $this->userId)->update(['status' => 'disabled']);
        $degraded = $this->getJson('/edge/local/ready');
        $this->assertSame('needs_enrollment', $degraded->json('local_auth'));
        $this->assertSame('not_ready', $degraded->json('local_pos'));
    }
}
