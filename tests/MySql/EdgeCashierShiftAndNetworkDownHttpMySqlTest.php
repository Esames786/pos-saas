<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Edge\EdgeSyncSender;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-CASHIER-UI — shift parity and the network-down cashier proof, over REAL HTTP on a branch_server-booted app.
 *
 * SHIFT (the Online shift screens' truth on the appliance): operating business date = the open shift's
 * business_date (OPERATING-DATE-1); tender breakup + cancellations (SHIFT-CANCELLATIONS-1); HIDE-AMOUNTS
 * blind count decided by the shared AmountVisibility rule with figures STRIPPED server-side; ZERO-DRAWER-1
 * (an empty drawer closes without a count, a drawer holding cash demands a typed count); terminal lock.
 *
 * NETWORK DOWN: with the master database unreachable AND the Cloud sync endpoint unreachable, a cash sale
 * completes locally, moves operational stock, creates its outbox row and the cashier sees a business-friendly
 * "Pending sync" — never leases, hashes, activation epochs or baseline UUIDs.
 */
class EdgeCashierShiftAndNetworkDownHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalA;
    private int $terminalB;
    private int $userId;
    private int $productId;
    private int $cashMethodId;
    private int $baselineId;

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
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'printers', 'terminal_printer_settings',
            'model_has_permissions', 'permissions',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'SHF' . Str::random(4)]);
        $this->terminalA = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->terminalB = $this->makeTerminal($this->branchId, ['name' => 'Floor (no drawer)']);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->baselineId = (int) $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 20]])->id;
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalA])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 500])->assertStatus(201);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    private function cashSale(float $qty = 1): void
    {
        $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => $qty]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100 * $qty, 'tendered_amount' => 100 * $qty]],
        ])->assertStatus(201);
    }

    public function test_shift_summary_breakup_operating_date_terminal_lock_and_zero_drawer_close(): void
    {
        $this->cashSale(1);

        // Summary = the Online shift screen's figures for this counter.
        $s = $this->getJson('/edge/local/pos/shift/summary')->assertOk();
        $shiftDate = DB::connection('tenant')->table('shifts')->where('terminal_id', $this->terminalA)->where('status', 'open')->value('business_date');
        $this->assertSame((string) $shiftDate, $s->json('operating_business_date'), 'OPERATING-DATE: the open shift business date, not the wall clock');
        $this->assertTrue($s->json('may_see_amounts'));
        $this->assertSame(500.0, (float) $s->json('breakup.opening_cash'));
        $this->assertSame(100.0, (float) $s->json('breakup.cash'));
        $this->assertSame(0.0, (float) $s->json('breakup.card'));
        $this->assertSame(600.0, (float) $s->json('breakup.expected_cash'));
        $this->assertSame(0, (int) $s->json('breakup.cancelled_bills'));
        $this->assertFalse($s->json('shift.zero_drawer'));
        $this->assertTrue(collect($s->json('branch_open_shifts'))->contains(fn ($x) => (int) $x['terminal_id'] === $this->terminalA && $x['is_current'] === true));

        // Terminal lock: a terminal with an open shift cannot be opened again.
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(422);

        // A drawer holding cash demands a typed count — an omitted count is refused, 0 must be deliberate.
        $this->postJson('/edge/local/pos/shift/close', [])->assertStatus(422);
        $this->assertSame('open', DB::connection('tenant')->table('shifts')->where('terminal_id', $this->terminalA)->where('status', 'open')->value('status'));

        // ZERO-DRAWER-1: the floor terminal takes orders but never money — its empty drawer closes without a count.
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalB])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
        $this->assertTrue($this->getJson('/edge/local/pos/shift/summary')->assertOk()->json('shift.zero_drawer'));
        $this->postJson('/edge/local/pos/shift/close', [])->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('cash_variance', 0);

        // Back on Counter A: the typed count closes it with the variance from expected.
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalA])->assertOk();
        $this->postJson('/edge/local/pos/shift/close', ['counted_cash' => 590])->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('cash_variance', -10);
    }

    public function test_blind_count_strips_amounts_for_an_operator_without_the_view_amounts_permission(): void
    {
        $this->cashSale(1);
        DB::connection('tenant')->table('branches')->where('id', $this->branchId)->update(['hide_amounts_from_operators' => 1]);

        $s = $this->getJson('/edge/local/pos/shift/summary')->assertOk();
        $this->assertFalse($s->json('may_see_amounts'));
        foreach (['opening_cash', 'total_sales', 'cash', 'card', 'bank', 'expected_cash', 'cancelled_amount'] as $k) {
            $this->assertNull($s->json("breakup.{$k}"), "{$k} must be STRIPPED for a blind count, not merely hidden");
        }
        // Counts stay (an operator should know a bill was thrown away); the raw JSON carries no money figure.
        $this->assertSame(0, (int) $s->json('breakup.cancelled_bills'));
        $this->assertStringNotContainsString('600', $s->getContent());

        // The synced Online permission lifts the mask — same rule as the Cloud shift screens.
        $permId = (int) DB::connection('tenant')->table('permissions')->insertGetId(['name' => 'tenant.shifts.view-amounts', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('model_has_permissions')->insert(['permission_id' => $permId, 'model_type' => User::class, 'model_id' => $this->userId]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        $this->assertSame(600.0, (float) $this->getJson('/edge/local/pos/shift/summary')->assertOk()->json('breakup.expected_cash'));
    }

    public function test_network_down_cash_sale_completes_locally_and_the_cashier_sees_pending_sync_only(): void
    {
        // Master database unreachable — and the Cloud sync endpoint unreachable (closed port).
        config(['database.connections.master.database' => 'nonexistent_master_cashier_netdown']);
        DB::purge('master');
        try {
            DB::connection('master')->select('select 1');
            $this->fail('master must be unreachable in this proof');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
        config(['edge.sync.url' => 'http://127.0.0.1:9/api/edge/sync/sales', 'edge.sync.device_id' => 'dev-1', 'edge.sync.device_secret' => 'secret', 'edge.sync.connect_timeout' => 1, 'edge.sync.timeout' => 2]);

        // The REAL cashier page loads, and a cash sale completes through the page's endpoints.
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        $this->cashSale(2);
        $this->assertSame(18.0, $this->edgeOnHand($this->baselineId, $this->productId), 'operational stock moved locally');
        $this->assertSame(1, DB::connection('tenant')->table('edge_sync_outbox')->count(), 'the outbox row exists');
        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count(), 'no Cloud finance was touched');

        // The cashier's view of it: business-friendly Pending sync.
        $sync = $this->getJson('/edge/local/pos/sync/summary')->assertOk();
        $this->assertSame(1, (int) $sync->json('pending_sales'));
        $this->assertSame('pending', $sync->json('state'));
        $this->assertStringContainsString('waiting to sync', $sync->json('message'));

        // A send attempt against the dead Cloud is a controlled outcome, never an exception — and the sale stays safe.
        try {
            $outcome = app(EdgeSyncSender::class)->sendNext('cashier-netdown-proof');
            $this->assertIsString($outcome);
        } catch (\Throwable $e) {
            $this->fail('the sync sender must fail closed offline, not throw: ' . get_class($e) . ' ' . $e->getMessage());
        }
        $this->assertSame(1, DB::connection('tenant')->table('sales_orders')->where('status', 'paid')->count());
        $this->assertContains($this->getJson('/edge/local/pos/sync/summary')->json('state'), ['pending', 'attention']);

        // No engineering internals reach the till — neither the page nor the sync summary.
        foreach (['lease_token', 'activation_epoch', 'baseline_uuid', 'payload_hash', 'device_secret'] as $internal) {
            $this->assertStringNotContainsString($internal, $html, "the cashier page must not expose {$internal}");
            $this->assertStringNotContainsString($internal, $sync->getContent(), "the sync summary must not expose {$internal}");
        }
    }
}
