<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * CANONICAL REHEARSAL Phase E, owner item R-4 (30 Oct 2026) — MOVE-TABLE-CART-1 (canonical a4eec6f8) on the Edge hold
 * twin. Online's HeldSaleController::store refuses a held bill saved onto ANOTHER table session (the 22 Oct Kashif Food
 * stale cart: after Move Table the cart still held a car's quick sale and the next Hold re-homed it onto table 32). The
 * same SERVER guard now lives in EdgeLocalPosService::reviseHeldSale, with the same key (`held_sale_id`) and the same
 * words, so the ONE shared cashier view shows the cashier the identical message on both runtimes. The Edge-only safety
 * checks stay: an omitted session is refused (lock-order guard), a closed session is never inferred, a second new check
 * on an occupied table answers Online's TABLE_HAS_OPEN_ORDERS, and the explicit R21 `change_order_details` revision is the
 * only sanctioned way to re-home a check. A refusal saves nothing and queues nothing (authority / replay untouched).
 */
class EdgeMoveTableHoldGuardMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $table30;
    private int $table32;
    private int $table40;
    private int $waiterId;
    private int $productId;

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
            'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations', 'edge_sync_outbox',
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'manager_approvals', 'void_reasons',
            'model_has_permissions', 'permissions', 'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'MT' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->table30 = $this->makeTable($this->branchId, ['table_no' => '30', 'status' => 'available', 'capacity' => 4]);
        $this->table32 = $this->makeTable($this->branchId, ['table_no' => '32', 'status' => 'available', 'capacity' => 4]);
        $this->table40 = $this->makeTable($this->branchId, ['table_no' => '40', 'status' => 'available', 'capacity' => 2]);
        $this->waiterId = $this->makeWaiter($this->branchId, ['name' => 'Waiter Ali']);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'Rice']), ['name' => 'Rice', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 170]);
        $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        foreach (['tenant.held-sales.store', 'tenant.restaurant.table-sessions.open', 'tenant.restaurant.table-sessions.close', 'tenant.restaurant.table-sessions.move'] as $p) {
            $this->grantEdgePermission($this->userId, $p);
        }
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
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    /* ── helpers (the shapes the shared cashier view posts through POS.route) ─────────────────────────────── */

    private function openTable(int $tableId): int
    {
        return (int) $this->postJson("/edge/local/pos/restaurant/tables/{$tableId}/open", ['restaurant_waiter_id' => $this->waiterId, 'guest_count' => 2])
            ->assertStatus(201)->json('session_id');
    }

    private function line(): array
    {
        return ['product_id' => $this->productId, 'quantity' => 1];
    }

    private function hold(array $extra = [])
    {
        return $this->postJson('/edge/local/pos/held-sales', array_merge(['order_type' => 'dine_in', 'lines' => [$this->line()]], $extra));
    }

    private function holdOk(array $extra): int
    {
        $res = $this->hold($extra);
        $this->assertSame(201, $res->getStatusCode(), 'setup hold failed — ' . $res->getContent());

        return (int) $res->json('sale_id');
    }

    /** A held quick sale (a car): Online parity requires the vehicle and the waiter. */
    private function quickSale(): int
    {
        return $this->holdOk(['order_type' => 'quick_sale', 'vehicle_number' => 'h1', 'restaurant_waiter_id' => $this->waiterId]);
    }

    private function move(int $sessionId, int $targetTableId)
    {
        return $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/move", ['target_table_id' => $targetTableId]);
    }

    private function order(int $id): object
    {
        return DB::connection('tenant')->table('sales_orders')->where('id', $id)->first();
    }

    private function billsOn(int $sessionId): array
    {
        return DB::connection('tenant')->table('sales_orders')->where('restaurant_table_session_id', $sessionId)->where('status', 'held')->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** The bill exactly as it is — plus the outbox, which a refused hold must never grow (authority / replay untouched). */
    private function snapshot(int $id): array
    {
        $o = $this->order($id);
        $lines = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $id)->orderBy('id')->get(['product_id', 'quantity'])
            ->map(fn ($l) => [(int) $l->product_id, (float) $l->quantity])->all();

        return [
            'order_type' => (string) $o->order_type, 'vehicle_number' => $o->vehicle_number, 'session' => (int) $o->restaurant_table_session_id,
            'table' => (int) $o->restaurant_table_id, 'status' => (string) $o->status, 'grand_total' => (float) $o->grand_total, 'lines' => $lines,
            'outbox' => DB::connection('tenant')->table('edge_sync_outbox')->count(),
        ];
    }

    private function assertRefusedLikeOnline($res, int $saleId, string $there, string $here): void
    {
        $this->assertSame(422, $res->getStatusCode(), $res->getContent());
        $saleNo = (string) $this->order($saleId)->sale_no;
        $expected = "Bill {$saleNo} {$there}, not {$here}. Nothing was saved. Press Add Round, then add the items for {$here} again.";
        $this->assertSame($expected, (string) $res->json('message'), 'the SAME words as Online HeldSaleController::store (MOVE-TABLE-CART-1)');
        $this->assertSame([$expected], $res->json('errors.held_sale_id'), 'keyed held_sale_id like Online (ValidationException shape → the page toasts data.message)');
    }

    /* ── the 22 Oct case ────────────────────────────────────────────────────────────────────────────────────── */

    public function test_after_a_move_a_held_quick_sale_cannot_be_saved_onto_the_table(): void
    {
        $s30 = $this->openTable($this->table30);
        $tableBill = $this->holdOk(['restaurant_table_session_id' => $s30]);
        $car = $this->quickSale();

        $move = $this->move($s30, $this->table32);
        $this->assertSame(200, $move->getStatusCode(), 'the move itself is legitimate and must work — ' . $move->getContent());
        $this->assertSame($this->table32, (int) $this->order($tableBill)->restaurant_table_id, 'the table\'s own bill follows the move');
        $before = $this->snapshot($car);

        // The stale cart: the car's bill id, table 32's session, the extra rice.
        $res = $this->hold(['held_sale_id' => $car, 'restaurant_table_session_id' => $s30, 'lines' => [$this->line(), $this->line()]]);

        $this->assertRefusedLikeOnline($res, $car, 'is a Quick Sale bill', 'Table 32');
        $this->assertSame($before, $this->snapshot($car), 'the car\'s bill is exactly as it was: type, vehicle, table, lines — and no sync event');
        $this->assertSame([$tableBill], $this->billsOn($s30), 'table 32 keeps only its own bill');
    }

    public function test_another_tables_bill_cannot_be_saved_onto_this_table(): void
    {
        $s30 = $this->openTable($this->table30);
        $s32 = $this->openTable($this->table32);
        $bill30 = $this->holdOk(['restaurant_table_session_id' => $s30]);
        $bill32 = $this->holdOk(['restaurant_table_session_id' => $s32]);
        $before = $this->snapshot($bill30);

        $res = $this->hold(['held_sale_id' => $bill30, 'restaurant_table_session_id' => $s32]);

        $this->assertRefusedLikeOnline($res, $bill30, 'belongs to Table 30', 'Table 32');
        $this->assertSame($before, $this->snapshot($bill30));
        $this->assertSame([$bill30], $this->billsOn($s30));
        $this->assertSame([$bill32], $this->billsOn($s32));
    }

    /** The bill followed its move to table 32; a cart still pointing at table 30 (now a fresh session) is refused. */
    public function test_an_already_moved_bill_cannot_be_saved_back_onto_its_old_table(): void
    {
        $s30 = $this->openTable($this->table30);
        $bill = $this->holdOk(['restaurant_table_session_id' => $s30]);
        $this->move($s30, $this->table32)->assertOk();
        $s30b = $this->openTable($this->table30);   // table 30 is free again: a new party sits down
        $before = $this->snapshot($bill);

        $res = $this->hold(['held_sale_id' => $bill, 'restaurant_table_session_id' => $s30b]);

        $this->assertRefusedLikeOnline($res, $bill, 'belongs to Table 32', 'Table 30');
        $this->assertSame($before, $this->snapshot($bill));
        $this->assertSame([], $this->billsOn($s30b), 'the new party\'s table stays clean');
    }

    /* ── controls: the guard must not stop everyday work ───────────────────────────────────────────────────── */

    public function test_the_tables_own_bill_still_saves_before_and_after_a_move(): void
    {
        $s30 = $this->openTable($this->table30);
        $bill = $this->holdOk(['restaurant_table_session_id' => $s30]);

        $round2 = $this->hold(['held_sale_id' => $bill, 'restaurant_table_session_id' => $s30, 'lines' => [$this->line(), $this->line()]]);
        $this->assertSame(200, $round2->getStatusCode(), $round2->getContent()); // a revision answers 200 (a NEW hold 201), as Online
        $this->assertSame($bill, (int) $round2->json('sale_id'));
        $this->assertCount(2, $this->snapshot($bill)['lines']);

        $this->move($s30, $this->table32)->assertOk();
        $round3 = $this->hold(['held_sale_id' => $bill, 'restaurant_table_session_id' => $s30, 'lines' => [$this->line(), $this->line(), $this->line()]]);
        $this->assertSame(200, $round3->getStatusCode(), $round3->getContent());
        $this->assertCount(3, $this->snapshot($bill)['lines']);
        $this->assertSame($this->table32, $this->snapshot($bill)['table']);
    }

    public function test_a_held_quick_sale_still_saves_on_its_own(): void
    {
        $car = $this->quickSale();
        $res = $this->hold(['held_sale_id' => $car, 'order_type' => 'quick_sale', 'vehicle_number' => 'h1', 'restaurant_waiter_id' => $this->waiterId, 'lines' => [$this->line(), $this->line()]]);
        $this->assertSame(200, $res->getStatusCode(), $res->getContent()); // revision
        $this->assertCount(2, $this->snapshot($car)['lines']);
        $this->assertSame('quick_sale', $this->snapshot($car)['order_type']);
    }

    /* ── the Edge safety checks that stay beside the new guard ─────────────────────────────────────────────── */

    public function test_a_second_new_check_on_an_occupied_table_is_answered_like_online(): void
    {
        $s30 = $this->openTable($this->table30);
        $bill = $this->holdOk(['restaurant_table_session_id' => $s30]);

        $dup = $this->hold(['restaurant_table_session_id' => $s30]);   // no held_sale_id: a duplicate hold attempt
        $this->assertSame(409, $dup->getStatusCode(), $dup->getContent());
        $this->assertSame('TABLE_HAS_OPEN_ORDERS', $dup->json('code'));
        $this->assertSame($bill, (int) $dup->json('orders.0.id'));
        $this->assertSame([$bill], $this->billsOn($s30), 'still ONE check on the table');
    }

    public function test_a_closed_or_omitted_session_is_refused_never_inferred(): void
    {
        $s40 = $this->openTable($this->table40);
        $close = $this->postJson("/edge/local/pos/restaurant/table-sessions/{$s40}/close", []);
        $this->assertTrue($close->isSuccessful(), $close->getContent());
        $s30 = $this->openTable($this->table30);
        $bill = $this->holdOk(['restaurant_table_session_id' => $s30]);
        $before = $this->snapshot($bill);

        // stale table state: the cart names a session that is closed
        $stale = $this->hold(['held_sale_id' => $bill, 'restaurant_table_session_id' => $s40]);
        $this->assertSame(422, $stale->getStatusCode(), $stale->getContent());
        $this->assertSame('No open table session found.', (string) $stale->json('message'));

        // omitted session on a dine-in check: the lock-order guard (Edge safety check, kept) refuses instead of inferring
        $omitted = $this->hold(['held_sale_id' => $bill, 'order_type' => 'takeaway']);
        $this->assertSame(422, $omitted->getStatusCode(), $omitted->getContent());
        $this->assertSame('This held sale belongs to a different table session — submit its own session.', (string) $omitted->json('message'));

        $this->assertSame($before, $this->snapshot($bill));
    }

    public function test_change_order_details_remains_the_only_sanctioned_way_to_re_home_a_check(): void
    {
        $s30 = $this->openTable($this->table30);
        $s32 = $this->openTable($this->table32);
        $bill30 = $this->holdOk(['restaurant_table_session_id' => $s30]);

        // Without the explicit R21 flag the stale-cart guard refuses (above); WITH it the waiter's deliberate re-home works…
        $moved = $this->hold(['held_sale_id' => $bill30, 'restaurant_table_session_id' => $s32, 'change_order_details' => 1]);
        $this->assertSame(200, $moved->getStatusCode(), $moved->getContent()); // revision
        $this->assertSame($s32, $this->snapshot($bill30)['session']);
        $this->assertSame([$bill30], $this->billsOn($s32));

        // …but never onto a table that already has its own open check.
        $s40 = $this->openTable($this->table40);
        $bill40 = $this->holdOk(['restaurant_table_session_id' => $s40]);
        $blocked = $this->hold(['held_sale_id' => $bill30, 'restaurant_table_session_id' => $s40, 'change_order_details' => 1]);
        $this->assertSame(422, $blocked->getStatusCode(), $blocked->getContent());
        $this->assertStringContainsString('already has an open order', (string) $blocked->json('message'));
        $this->assertSame([$bill40], $this->billsOn($s40));
        $this->assertSame($s32, $this->snapshot($bill30)['session']);
    }
}
