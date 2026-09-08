<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-CASHIER-UI-3 — the Reservation workflow the browser Table Board drives, over REAL HTTP on a
 * branch_server-booted app: Reserve a free table (walk-in or named customer, time, note) → the board
 * shows it reserved with the customer → View details → Open reserved table → the customer is carried
 * onto the check (the customer chip reads it from the held detail) → Cancel reservation frees the table.
 * Reservations are the Edge-owned authority (survive config refresh + recovery; fenced on Cloud).
 */
class EdgeCashierReservationHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $tableId;
    private int $table2Id;
    private int $productId;

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
            'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations',
            'kot_batch_lines', 'kot_batches', 'print_jobs', 'category_printer_mappings', 'terminal_printer_settings', 'printers',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories',
            'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'RSV' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'available', 'capacity' => 4]);
        $this->table2Id = $this->makeTable($this->branchId, ['table_no' => 'T2', 'status' => 'available', 'capacity' => 2]);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 20]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
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

    private function tableOnBoard(int $tableId): array
    {
        $board = $this->getJson('/edge/local/pos/restaurant/board')->assertOk()->json('floors');
        foreach ($board as $floor) {
            foreach ($floor['tables'] as $t) {
                if ((int) $t['id'] === $tableId) {
                    return $t;
                }
            }
        }
        $this->fail("table {$tableId} missing from the board");
    }

    public function test_reserve_view_open_carries_customer_onto_the_check(): void
    {
        // The page renders (the board is opened from it).
        $this->get('/edge/local/pos')->assertOk()->assertSee('View Tables');

        // RESERVE a free table — named customer, time, note (the Table Board's Reserve form payload).
        $when = now()->addHours(3)->toIso8601String();
        $r = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/reserve", [
            'customer_name' => 'Mrs Ahmed', 'customer_phone' => '0300-1234567', 'reserved_for' => $when, 'note' => 'birthday, window seat',
        ]);
        $r->assertStatus(201)->assertJsonPath('customer_name', 'Mrs Ahmed')->assertJsonPath('status', 'active');

        // The BOARD reads it: status reserved + the reservation the board shows/acts on.
        $t = $this->tableOnBoard($this->tableId);
        $this->assertSame('reserved', $t['status']);
        $this->assertSame('Mrs Ahmed', $t['reservation']['customer_name']);
        $this->assertSame('birthday, window seat', $t['reservation']['note']);
        // View details.
        $this->getJson("/edge/local/pos/restaurant/tables/{$this->tableId}/reservation")->assertOk()
            ->assertJsonPath('reservation.customer_phone', '0300-1234567');

        // A second reservation on the same table is refused; an occupied table cannot be reserved.
        $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/reserve", ['customer_name' => 'Someone Else'])->assertStatus(422);

        // OPEN the reserved table → the reservation is seated and the customer carries onto the check.
        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 4])->assertStatus(201)->json('session_id');
        $this->assertSame('seated', DB::connection('tenant')->table('edge_local_table_reservations')->where('restaurant_table_id', $this->tableId)->value('status'));
        $t = $this->tableOnBoard($this->tableId);
        $this->assertSame('occupied', $t['status']);
        $this->assertNull($t['reservation'], 'a seated reservation is no longer shown as reserved');

        $hold = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
        ])->assertStatus(201);
        // The customer chip reads customer_name off the held detail — this is what the cashier sees.
        $this->getJson('/edge/local/pos/held-sales/' . $hold->json('sale_id'))->assertOk()
            ->assertJsonPath('held_sale.customer_name', 'Mrs Ahmed')
            ->assertJsonPath('held_sale.customer_phone', '0300-1234567');
        $this->getJson('/edge/local/pos/held-sales')->assertOk()->assertJsonPath('held_sales.0.customer_name', 'Mrs Ahmed');
    }

    public function test_walk_in_reservation_then_cancel_frees_the_table(): void
    {
        // Walk-in (no customer given) reservation, no time — allowed; the board still shows it reserved.
        $this->postJson("/edge/local/pos/restaurant/tables/{$this->table2Id}/reserve", ['note' => 'phone booking'])->assertStatus(201);
        $this->assertSame('reserved', $this->tableOnBoard($this->table2Id)['status']);

        // CANCEL the reservation → the table is free again, the details read empty.
        $this->postJson("/edge/local/pos/restaurant/tables/{$this->table2Id}/unreserve", [])->assertOk();
        $this->assertSame('available', $this->tableOnBoard($this->table2Id)['status']);
        $this->getJson("/edge/local/pos/restaurant/tables/{$this->table2Id}/reservation")->assertOk()->assertJsonPath('reservation', null);
        $this->assertSame('cancelled', DB::connection('tenant')->table('edge_local_table_reservations')->where('restaurant_table_id', $this->table2Id)->value('status'));

        // Cancelling again is a controlled refusal, not a 500.
        $this->postJson("/edge/local/pos/restaurant/tables/{$this->table2Id}/unreserve", [])->assertStatus(422);
    }
}
