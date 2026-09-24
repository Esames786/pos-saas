<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W3 (Team 3) — the Table Workspace operations the Online POS offers (RestaurantTableSessionController::billRequested / move /
 * merge / show / billPreview, HeldSaleController::reattachTable, RestaurantTableController reserve/details), executed over REAL
 * HTTP on a branch_server-booted app through the exact /edge/local/pos/* endpoints the cashier page calls.
 *
 * Invariants proven on every mutation: the Online route permission gates the endpoint (403 without it); order identity is
 * preserved (sale_uuid, line ids/captured prices, KOT-sent quantities survive a move / merge / reattach); the kitchen is not
 * told again (no new kot_batches); held checks stay LOCAL (no outbox row until settle); a paid sale is never re-pointed.
 */
class EdgeCashierTablesWorkspaceHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const W3_PERMISSIONS = [
        'tenant.restaurant.table-sessions.bill-requested',
        'tenant.restaurant.table-sessions.move',
        'tenant.restaurant.table-sessions.merge',
        'tenant.restaurant.table-sessions.show',
        'tenant.restaurant.table-sessions.bill-preview',
        'tenant.held-sales.reattach-table',
    ];

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $t1;
    private int $t2;
    private int $t3;
    private int $waiterA;
    private int $waiterB;
    private int $productP;
    private int $productQ;
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
            'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations', 'edge_sync_outbox',
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs',
            'manager_approvals', 'void_reasons', 'model_has_permissions', 'permissions', 'customers',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active',
            'held_kot_cancellation_approval_mode' => 'manager_required']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'TW' . Str::random(4), 'name' => 'Cashier Tee']);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->t1 = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'available', 'capacity' => 4]);
        $this->t2 = $this->makeTable($this->branchId, ['table_no' => 'T2', 'status' => 'available', 'capacity' => 2]);
        $this->t3 = $this->makeTable($this->branchId, ['table_no' => 'T3', 'status' => 'available', 'capacity' => 6]);
        $this->waiterA = $this->makeWaiter($this->branchId, ['name' => 'Waiter Ali']);
        $this->waiterB = $this->makeWaiter($this->branchId, ['name' => 'Waiter Bilal']);
        $cat = $this->makeCategory(['name' => 'Karahi']);
        $this->productP = $this->makeProduct($cat, ['name' => 'Karahi', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->productQ = $this->makeProduct($cat, ['name' => 'Naan', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([
            ['product_id' => $this->productP, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->productQ, 'product_variant_id' => null, 'quantity' => 50],
        ]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        foreach (self::W3_PERMISSIONS as $perm) {
            $this->grantEdgePermission($this->userId, $perm);
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
        parent::tearDown();
    }

    private function revoke(string $permission): void
    {
        $permId = DB::connection('tenant')->table('permissions')->where('name', $permission)->value('id');
        DB::connection('tenant')->table('model_has_permissions')->where('permission_id', $permId)->where('model_id', $this->userId)->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        auth('tenant')->user()?->unsetRelation('permissions');
        auth('tenant')->user()?->unsetRelation('roles');
    }

    private function tableOnBoard(int $tableId): array
    {
        foreach ($this->getJson('/edge/local/pos/restaurant/board')->assertOk()->json('floors') as $floor) {
            foreach ($floor['tables'] as $t) {
                if ((int) $t['id'] === $tableId) {
                    return $t;
                }
            }
        }
        $this->fail("table {$tableId} missing from the board");
    }

    /** Open a table, hold a check with 2×P, send the KOT. Returns [sessionId, saleId, lineId, saleUuid]. */
    private function openHoldAndKot(int $tableId, int $waiterId, float $qty = 2): array
    {
        $sessionId = (int) $this->postJson("/edge/local/pos/restaurant/tables/{$tableId}/open", ['restaurant_waiter_id' => $waiterId, 'guest_count' => 3, 'notes' => 'window seat'])
            ->assertStatus(201)->json('session_id');
        $hold = $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->productP, 'quantity' => $qty]]])->assertStatus(201);
        $saleId = (int) $hold->json('sale_id');
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk()->assertJsonPath('batch.sequence_no', 1);
        $line = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->first();

        return [$sessionId, $saleId, (int) $line->id, (string) $hold->json('sale_uuid')];
    }

    public function test_open_table_notes_board_tile_data_and_request_bill(): void
    {
        [$sessionId, $saleId] = $this->openHoldAndKot($this->t1, $this->waiterA);

        // R3: the open-table Notes field persists; R1/R2 tile data (seats, session no, waiter, running total, order count).
        $this->assertSame('window seat', DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $sessionId)->value('notes'));
        $tile = $this->tableOnBoard($this->t1);
        $this->assertSame('occupied', $tile['status']);
        $this->assertSame(4, (int) $tile['capacity']);
        $this->assertSame('Waiter Ali', $tile['session']['waiter_name']);
        $this->assertSame(200.0, (float) $tile['session']['open_check']);
        $this->assertSame(1, (int) $tile['session']['order_count']);
        $this->assertSame(1, (int) $tile['session']['held_orders'][0]['items_count']);
        $this->assertNotEmpty($tile['session']['session_no']);

        // R10: the held detail carries the session-bar data (Online sessionPayload).
        $this->getJson("/edge/local/pos/held-sales/{$saleId}")->assertOk()
            ->assertJsonPath('held_sale.table_session.session_no', $tile['session']['session_no'])
            ->assertJsonPath('held_sale.table_session.guest_count', 3)
            ->assertJsonPath('held_sale.table_session.open_check', 200)
            ->assertJsonPath('held_sale.dead_session', null);

        // R11: Request Bill — permission gated like Online.
        $this->revoke('tenant.restaurant.table-sessions.bill-requested');
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/bill-requested")->assertStatus(403)
            ->assertJsonPath('permission', 'tenant.restaurant.table-sessions.bill-requested');
        $this->grantEdgePermission($this->userId, 'tenant.restaurant.table-sessions.bill-requested');
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/bill-requested")->assertOk()
            ->assertJsonPath('status', 'bill_requested')->assertJsonPath('session.status', 'bill_requested');
        $this->assertSame('bill_requested', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->t1)->value('status'));
        $this->assertSame('bill_requested', $this->tableOnBoard($this->t1)['status']);

        // Add Round still works on a bill_requested session (Online: the session is still live).
        $line = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->first();
        $this->postJson('/edge/local/pos/held-sales', ['held_sale_id' => $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['sales_order_line_id' => $line->id, 'product_id' => $this->productP, 'quantity' => 2], ['product_id' => $this->productQ, 'quantity' => 1]]])->assertOk();

        // Settling the last check closes the session and frees the table (shared custody rule) — held stayed local until now.
        $this->assertSame(0, DB::connection('tenant')->table('edge_sync_outbox')->count(), 'held checks never reach the outbox');
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/settle", ['client_uuid' => (string) Str::uuid(),
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 250]]])->assertOk()->assertJsonPath('status', 'paid');
        $this->assertSame('available', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->t1)->value('status'));
        // A closed session cannot request the bill.
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/bill-requested")->assertStatus(422);
    }

    public function test_move_table_preserves_order_identity_and_sent_quantities_and_refuses_unavailable_targets(): void
    {
        [$sessionId, $saleId, $lineId, $saleUuid] = $this->openHoldAndKot($this->t1, $this->waiterA);
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/bill-requested")->assertOk();
        [$busySession] = $this->openHoldAndKot($this->t3, $this->waiterB, 1);
        $this->postJson("/edge/local/pos/restaurant/tables/{$this->t2}/reserve", ['customer_name' => 'Mrs Ahmed'])->assertStatus(201);
        $kotBefore = DB::connection('tenant')->table('kot_batches')->count();

        // 403 without the Online Move permission.
        $this->revoke('tenant.restaurant.table-sessions.move');
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/move", ['target_table_id' => $this->t2])->assertStatus(403);
        $this->grantEdgePermission($this->userId, 'tenant.restaurant.table-sessions.move');

        // Refusals: same table, an occupied table, a reserved table.
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/move", ['target_table_id' => $this->t1])->assertStatus(422);
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/move", ['target_table_id' => $this->t3])->assertStatus(422)
            ->assertJsonPath('message', 'Target table is not available.');
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/move", ['target_table_id' => $this->t2])->assertStatus(422);

        // Free T2 and move there.
        $this->postJson("/edge/local/pos/restaurant/tables/{$this->t2}/unreserve")->assertOk();
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/move", ['target_table_id' => $this->t2])->assertOk()
            ->assertJsonPath('session.table_id', $this->t2)->assertJsonPath('session.status', 'bill_requested');

        $sale = DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->first();
        $this->assertSame($this->t2, (int) $sale->restaurant_table_id);
        $this->assertSame($sessionId, (int) $sale->restaurant_table_session_id, 'same session — only its table changed');
        $this->assertSame($saleUuid, (string) $sale->sale_uuid, 'order identity preserved');
        $line = DB::connection('tenant')->table('sales_order_lines')->where('id', $lineId)->first();
        $this->assertSame(2.0, (float) $line->kot_sent_quantity, 'KOT-sent quantity preserved');
        $this->assertSame($kotBefore, DB::connection('tenant')->table('kot_batches')->count(), 'the kitchen is not told again');
        $this->assertSame('available', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->t1)->value('status'));
        $this->assertSame('bill_requested', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->t2)->value('status'), 'bill_requested survives the move');
        $this->assertSame('occupied', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->t3)->value('status'));

        // The check keeps working on its new table: nothing new to send; Add Round → only the delta.
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk()->assertJsonPath('batch', null);
        $this->postJson('/edge/local/pos/held-sales', ['held_sale_id' => $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['sales_order_line_id' => $lineId, 'product_id' => $this->productP, 'quantity' => 3]]])->assertOk();
        $kot = $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();
        $this->assertSame(1.0, (float) $kot->json('batch.lines.0.quantity'));
        $this->assertNotSame($busySession, $sessionId);
    }

    public function test_merge_moves_open_checks_keeps_paid_history_and_frees_the_source(): void
    {
        [$srcSession, $srcSale, $srcLine, $srcUuid] = $this->openHoldAndKot($this->t1, $this->waiterA, 2);
        [$dstSession, $dstSale] = $this->openHoldAndKot($this->t2, $this->waiterB, 1);
        // Split the source first so it carries a PAID round (fiscal history) + an open check.
        $split = $this->postJson("/edge/local/pos/held-sales/{$srcSale}/split", ['lines' => [['sales_order_line_id' => $srcLine, 'quantity' => 1]]])->assertStatus(201);
        $paidChild = (int) $split->json('child.id');
        $this->postJson("/edge/local/pos/held-sales/{$paidChild}/settle", ['client_uuid' => (string) Str::uuid(),
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]]])->assertOk();
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$srcSession}/bill-requested")->assertOk();
        $kotBefore = DB::connection('tenant')->table('kot_batches')->count();

        $this->revoke('tenant.restaurant.table-sessions.merge');
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$srcSession}/merge", ['target_session_id' => $dstSession])->assertStatus(403);
        $this->grantEdgePermission($this->userId, 'tenant.restaurant.table-sessions.merge');
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$srcSession}/merge", ['target_session_id' => $srcSession])->assertStatus(422);

        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$srcSession}/merge", ['target_session_id' => $dstSession])->assertOk()
            ->assertJsonPath('session.id', $dstSession)->assertJsonPath('session.status', 'bill_requested');

        $src = DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $srcSession)->first();
        $this->assertSame('cancelled', $src->status);
        $this->assertStringContainsString('merged into session', (string) $src->notes);
        $moved = DB::connection('tenant')->table('sales_orders')->where('id', $srcSale)->first();
        $this->assertSame($dstSession, (int) $moved->restaurant_table_session_id);
        $this->assertSame($this->t2, (int) $moved->restaurant_table_id);
        $this->assertSame($this->waiterB, (int) $moved->restaurant_waiter_id, 'the check follows the target session waiter');
        $this->assertSame($srcUuid, (string) $moved->sale_uuid);
        $this->assertSame(1.0, (float) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $srcSale)->value('kot_sent_quantity'));
        $this->assertSame($srcSession, (int) DB::connection('tenant')->table('sales_orders')->where('id', $paidChild)->value('restaurant_table_session_id'), 'paid history stays on the source session');
        $this->assertSame($kotBefore, DB::connection('tenant')->table('kot_batches')->count());
        $this->assertSame('available', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->t1)->value('status'));
        $this->assertSame('bill_requested', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->t2)->value('status'));

        // Two checks now live on the target: each pays on its own; the table frees with the LAST one.
        $tile = $this->tableOnBoard($this->t2);
        $this->assertCount(2, $tile['session']['held_orders']);
        $this->postJson("/edge/local/pos/held-sales/{$srcSale}/settle", ['client_uuid' => (string) Str::uuid(), 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]]])->assertOk();
        $this->assertSame('bill_requested', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->t2)->value('status'));
        $this->postJson("/edge/local/pos/held-sales/{$dstSale}/settle", ['client_uuid' => (string) Str::uuid(), 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]]])->assertOk();
        $this->assertSame('available', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->t2)->value('status'));

        // A source with no open check cannot be merged (Online rule).
        $emptySession = (int) $this->postJson("/edge/local/pos/restaurant/tables/{$this->t1}/open", ['guest_count' => 1])->json('session_id');
        [$otherSession] = $this->openHoldAndKot($this->t3, $this->waiterA, 1);
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$emptySession}/merge", ['target_session_id' => $otherSession])->assertStatus(422)
            ->assertJsonPath('message', 'The source table has no active held order to merge.');
    }

    public function test_session_detail_bill_preview_document_and_table_sessions_picker(): void
    {
        [$sessionId, $saleId, $lineId] = $this->openHoldAndKot($this->t1, $this->waiterA, 2);
        $split = $this->postJson("/edge/local/pos/held-sales/{$saleId}/split", ['lines' => [['sales_order_line_id' => $lineId, 'quantity' => 1]]])->assertStatus(201);
        $child = (int) $split->json('child.id');
        $this->postJson("/edge/local/pos/held-sales/{$child}/settle", ['client_uuid' => (string) Str::uuid(), 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]]])->assertOk();
        $line = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->first();
        $this->postJson('/edge/local/pos/held-sales', ['held_sale_id' => $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['sales_order_line_id' => $line->id, 'product_id' => $this->productP, 'quantity' => 1], ['product_id' => $this->productQ, 'quantity' => 2]]])->assertOk();

        // R20 — session detail: every order, paid included.
        $this->revoke('tenant.restaurant.table-sessions.show');
        $this->getJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}")->assertStatus(403);
        $this->grantEdgePermission($this->userId, 'tenant.restaurant.table-sessions.show');
        $detail = $this->getJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}")->assertOk();
        $this->assertSame('window seat', $detail->json('session.notes'));
        $this->assertSame('Cashier Tee', $detail->json('session.opened_by'));
        $this->assertEqualsCanonicalizing(['held', 'paid'], collect($detail->json('orders'))->pluck('status')->all());

        // R14 — Bill Preview: open rounds are the bill, paid rounds are "previously paid", print target = held ids.
        $this->revoke('tenant.restaurant.table-sessions.bill-preview');
        $this->getJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/bill-preview")->assertStatus(403);
        $this->grantEdgePermission($this->userId, 'tenant.restaurant.table-sessions.bill-preview');
        $bp = $this->getJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/bill-preview")->assertOk();
        $this->assertSame([$saleId], $bp->json('held_sale_ids'));
        $this->assertCount(1, $bp->json('rounds'));
        $this->assertSame(200.0, (float) $bp->json('totals.grand_total'), 'open check = 1×100 + 2×50');
        $this->assertSame(100.0, (float) $bp->json('previously_paid_total'));
        $this->assertSame($child, (int) $bp->json('previously_paid.0.id'));
        $this->assertEqualsCanonicalizing(['Karahi', 'Naan'], collect($bp->json('rounds.0.lines'))->pluck('name')->all());
        $this->assertNotNull($bp->json('html'), 'the receipt document renders');
        $this->assertStringContainsString((string) $detail->json('session.session_no'), (string) $bp->json('html'), 'the table bill is numbered by the check (session) number');
        $this->assertStringContainsString('Naan', (string) $bp->json('html'));
        $this->assertSame(0, DB::connection('tenant')->table('print_jobs')->where('reference_id', $saleId)->where('document_type', 'receipt')->count(), 'a preview queues nothing');

        // R21/A28 — the Change Order table picker (Online /api/pos/table-sessions shape).
        $sessions = collect($this->getJson('/edge/local/pos/restaurant/table-sessions')->assertOk()->json('sessions'))->keyBy('table_id');
        $this->assertTrue($sessions[$this->t1]['has_session']);
        $this->assertSame([$saleId], $sessions[$this->t1]['held_sale_ids']);
        $this->assertFalse($sessions[$this->t2]['has_session']);
        $this->assertStringStartsWith('Table T2', $sessions[$this->t2]['label']);
    }

    public function test_dead_session_recovery_reattaches_a_held_bill_to_a_new_session(): void
    {
        [$sessionId, $saleId, $lineId, $uuid] = $this->openHoldAndKot($this->t1, $this->waiterA, 2);
        // The Online incident shape (HELD-SALE-DEAD-SESSION-1): the session was closed while a held bill still pointed at it.
        DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $sessionId)->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $this->userId]);
        DB::connection('tenant')->table('restaurant_tables')->where('id', $this->t1)->update(['status' => 'available']);

        $detail = $this->getJson("/edge/local/pos/held-sales/{$saleId}")->assertOk();
        $this->assertSame('T1', $detail->json('held_sale.dead_session.table_no'));
        $this->assertTrue($detail->json('held_sale.dead_session.can_reopen'));
        $this->assertSame('Cashier Tee', $detail->json('held_sale.dead_session.closed_by'));
        $this->assertNull($detail->json('held_sale.table_session'));

        // A live table is refused; the permission gates the route.
        [$liveSession] = $this->openHoldAndKot($this->t3, $this->waiterB, 1);
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/reattach-table", ['restaurant_table_id' => $this->t3])->assertStatus(422);
        $this->revoke('tenant.held-sales.reattach-table');
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/reattach-table", ['restaurant_table_id' => $this->t1])->assertStatus(403);
        $this->grantEdgePermission($this->userId, 'tenant.held-sales.reattach-table');

        // Reopen T1 → a NEW session (never the dead one); the bill rides it with its identity and sent state.
        $r = $this->postJson("/edge/local/pos/held-sales/{$saleId}/reattach-table", ['restaurant_table_id' => $this->t1])->assertOk()
            ->assertJsonPath('table_no', 'T1');
        $newSession = (int) $r->json('restaurant_table_session_id');
        $this->assertNotSame($sessionId, $newSession);
        $this->assertSame('closed', DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $sessionId)->value('status'), 'the dead session stays dead');
        $this->assertNotEmpty(DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $newSession)->value('session_uuid'));
        $sale = DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->first();
        $this->assertSame($newSession, (int) $sale->restaurant_table_session_id);
        $this->assertSame($uuid, (string) $sale->sale_uuid);
        $this->assertSame(2.0, (float) DB::connection('tenant')->table('sales_order_lines')->where('id', $lineId)->value('kot_sent_quantity'));
        $this->assertSame('occupied', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->t1)->value('status'));
        $this->getJson("/edge/local/pos/held-sales/{$saleId}")->assertOk()->assertJsonPath('held_sale.dead_session', null);
        // A bill on a live session is refused (use Move instead) and the check is payable again.
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/reattach-table", ['restaurant_table_id' => $this->t2])->assertStatus(422);
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/settle", ['client_uuid' => (string) Str::uuid(), 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 200]]])->assertOk();
        $this->assertSame('available', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->t1)->value('status'));
        $this->assertNotSame($liveSession, $newSession);
    }

    public function test_cancel_empty_session_and_reservation_with_book_customer_and_details(): void
    {
        // R9 — an empty session can be closed as CANCELLED (Online standalone board).
        $sessionId = (int) $this->postJson("/edge/local/pos/restaurant/tables/{$this->t1}/open", ['guest_count' => 2])->json('session_id');
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/close", ['status' => 'cancelled'])->assertOk()
            ->assertJsonPath('status', 'cancelled')->assertJsonPath('message', 'Session cancelled.');
        $this->assertSame('available', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->t1)->value('status'));

        // R15 — reserve with a BOOK customer (typed name wins, else the book's); unknown book id refused.
        $customerId = (int) DB::connection('tenant')->table('customers')->insertGetId(['name' => 'Kashif Rana', 'phone' => '0300-7654321', 'customer_uuid' => (string) Str::ulid(), 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson("/edge/local/pos/restaurant/tables/{$this->t2}/reserve", ['customer_id' => 999999])->assertStatus(422);
        $r = $this->postJson("/edge/local/pos/restaurant/tables/{$this->t2}/reserve", ['customer_id' => $customerId, 'note' => 'anniversary'])->assertStatus(201);
        $r->assertJsonPath('customer_id', $customerId)->assertJsonPath('customer_name', 'Kashif Rana')->assertJsonPath('customer_phone', '0300-7654321');
        // R16 — details: who reserved it and when it was marked.
        $this->getJson("/edge/local/pos/restaurant/tables/{$this->t2}/reservation")->assertOk()
            ->assertJsonPath('reservation.reserved_by', 'Cashier Tee')
            ->assertJsonPath('reservation.note', 'anniversary');
        $this->assertNotNull($this->tableOnBoard($this->t2)['reservation']['reserved_at']);

        // R18 — opening the reserved table carries the book customer (id + name + phone) onto the first check.
        $s2 = (int) $this->postJson("/edge/local/pos/restaurant/tables/{$this->t2}/open", ['guest_count' => 2])->assertStatus(201)->json('session_id');
        $hold = $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'dine_in', 'restaurant_table_session_id' => $s2, 'lines' => [['product_id' => $this->productQ, 'quantity' => 1]]])->assertStatus(201);
        $this->getJson('/edge/local/pos/held-sales/' . $hold->json('sale_id'))->assertOk()
            ->assertJsonPath('held_sale.customer_id', $customerId)->assertJsonPath('held_sale.customer_phone', '0300-7654321');

        // Reservation routes are gated on the Online table-open permission (RestaurantTableController::RESERVE_PERMISSION).
        $this->revoke('tenant.restaurant.table-sessions.open');
        $this->postJson("/edge/local/pos/restaurant/tables/{$this->t3}/reserve", ['customer_name' => 'X'])->assertStatus(403);
        $this->getJson("/edge/local/pos/restaurant/tables/{$this->t3}/reservation")->assertStatus(403);
    }

    public function test_online_made_reservation_after_handover_reaches_the_board_as_status_only(): void
    {
        // R19 FACT: EdgeBootstrapService exports restaurant_tables (id, branch_id, restaurant_floor_id, table_no, name, capacity,
        // status, sort_order) — NOT reserved_* — so a table reserved on the Online POS arrives as status `reserved` with no details.
        DB::connection('tenant')->table('restaurant_tables')->where('id', $this->t3)->update(['status' => 'reserved']);
        $tile = $this->tableOnBoard($this->t3);
        $this->assertSame('reserved', $tile['status']);
        $this->assertNull($tile['reservation']);
        $this->assertTrue($tile['reservation_details_missing'], 'the page is told the details did not come across');
        // It can still be opened (and the reservation's customer is NOT carried — there is none on the Edge).
        $sessionId = (int) $this->postJson("/edge/local/pos/restaurant/tables/{$this->t3}/open", ['guest_count' => 2])->assertStatus(201)->json('session_id');
        $hold = $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId, 'lines' => [['product_id' => $this->productQ, 'quantity' => 1]]])->assertStatus(201);
        $this->getJson('/edge/local/pos/held-sales/' . $hold->json('sale_id'))->assertOk()->assertJsonPath('held_sale.customer_name', null);
        $this->assertSame('occupied', $this->tableOnBoard($this->t3)['status']);
        // A move can never land on a table that is reserved Online (status reserved ≠ available/cleaning).
        DB::connection('tenant')->table('restaurant_tables')->where('id', $this->t2)->update(['status' => 'reserved']);
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/move", ['target_table_id' => $this->t2])->assertStatus(422);
    }
}
