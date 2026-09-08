<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-CASHIER-UI — COMPLETE SALE PERMISSION parity (canonical f12f1fc): taking payment is gated on
 * `tenant.pos.store`, separately from any discount/approval permission. A restricted operator (Kashif's floor
 * terminal) can build the order, Preview Bill and HOLD it; only a counter holding the permission can close
 * it — and the server enforces the same on every payment endpoint, so the hidden button is never the only guard.
 *
 * On the appliance `User::can()` resolves from the synced per-user effective permission set
 * (EDGE_OFFLINE_PERMISSION_AUTHORITY) — no offline privilege escalation, no Edge-specific permission.
 */
class EdgeCashierPermissionHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
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
            'kot_batch_lines', 'kot_batches', 'print_jobs', 'model_has_permissions', 'permissions',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories',
            'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'FLR' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Floor terminal']);
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'available']);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 20]]);
        // The shared fixture grants tenant.pos.store to every seeded cashier — this restricted operator has it REVOKED.
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        DB::connection('tenant')->table('model_has_permissions')->where('model_id', $this->userId)->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    private function grantCompleteSale(): void
    {
        $permId = (int) (DB::connection('tenant')->table('permissions')->where('name', 'tenant.pos.store')->where('guard_name', 'tenant')->value('id')
            ?: DB::connection('tenant')->table('permissions')->insertGetId(['name' => 'tenant.pos.store', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]));
        DB::connection('tenant')->table('model_has_permissions')->insertOrIgnore(['permission_id' => $permId, 'model_type' => User::class, 'model_id' => $this->userId]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
    }

    public function test_restricted_operator_can_build_preview_and_hold_but_not_take_payment(): void
    {
        // The page tells the button what Online tells it: no Complete Sale for this operator.
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        $this->assertStringContainsString('"canCompleteSale":false', $html);
        $this->assertStringContainsString('a counter will close the bill', $html, 'the Online hint is on the page');

        // Preview Bill and Hold work — the operator can build the order and park it.
        $this->postJson('/edge/local/pos/preview-bill', ['order_type' => 'takeaway', 'lines' => [['product_id' => $this->productId, 'quantity' => 1]]])->assertOk();
        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->assertStatus(201)->json('session_id');
        $held = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
        ])->assertStatus(201)->json('sale_id');

        // Taking payment is refused SERVER-SIDE on both payment endpoints — the hidden button is not the guard.
        $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]],
        ])->assertStatus(403);
        $this->postJson("/edge/local/pos/held-sales/{$held}/settle", [
            'client_uuid' => (string) Str::uuid(),
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]],
        ])->assertStatus(403);
        $this->assertSame(0, DB::connection('tenant')->table('sales_orders')->where('status', 'paid')->count());
        $this->assertSame(0, DB::connection('tenant')->table('edge_sync_outbox')->count());

        // A counter holding the synced permission closes the same held check.
        $this->grantCompleteSale();
        $this->assertStringContainsString('"canCompleteSale":true', $this->get('/edge/local/pos')->assertOk()->getContent());
        $this->postJson("/edge/local/pos/held-sales/{$held}/settle", [
            'client_uuid' => (string) Str::uuid(),
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]],
        ])->assertOk()->assertJsonPath('status', 'paid');
    }
}
