<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-CASHIER-UI-2 — the Dine-In / Recall / Add Round / KOT / table-action workflow the browser cashier
 * page drives, executed over REAL HTTP on a branch_server-booted app through the exact endpoints the page
 * calls (board → open table → hold → held list/detail → KOT → add round → KOT → settle / cancel / close).
 *
 * Online semantics proven here, in the shape the current Online POS defines them:
 *  - KOT-SENT-POOL: round 1 sends the intended lines; Add Round sends ONLY the new intended quantity — a
 *    raised named line's delta AND a brand-new line — never a duplicate of round 1, never a missing helping.
 *  - RECALL-TERMINAL: recalling/revising a check from another counter never hijacks the operator's
 *    selected terminal, and the order keeps its ORIGINAL terminal_id for reporting.
 *  - SALE-DATE-TRUTH: payment never rewrites the order's time.
 *  - HIDDEN-PRODUCT-HELD-BILL: a product hidden after it landed on an open bill stays recallable AND
 *    payable through the REAL route (page payload, detail, Add Round carry, settle) — new hidden lines refused.
 *  - CANCEL: whole-order cancel frees the table; the cancellation KOT prints at the CURRENT counter while
 *    the order keeps its original terminal.
 *  - DRAFT: no KOT until the check is held normally.  - TABLE-CLOSE-EMPTY: closes only when empty.
 */
class EdgeCashierDineInHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalA;
    private int $terminalB;
    private int $userId;
    private int $managerId;
    private int $tableId;
    private int $waiterId;
    private int $productP;
    private int $productQ;
    private int $cashMethodId;
    private int $voidReasonId;
    private int $baselineId;
    private string $managerCode;

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
            'category_printer_mappings', 'terminal_printer_settings', 'printers',
            'manager_approvals', 'manager_pins', 'void_reasons',
            'model_has_permissions', 'permissions',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'combo_components', 'combos', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'held_kot_cancellation_approval_mode' => 'manager_required']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'DIN' . Str::random(4)]);
        $this->managerId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'MGR' . Str::random(4)]);
        $this->terminalA = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->terminalB = $this->makeTerminal($this->branchId, ['name' => 'Counter B']);
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'available', 'capacity' => 4]);
        $this->waiterId = $this->makeWaiter($this->branchId, ['name' => 'Waiter Ali']);
        $categoryId = $this->makeCategory(['name' => 'Karahi']);
        $this->productP = $this->makeProduct($categoryId, ['name' => 'Hidden Later Karahi', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->productQ = $this->makeProduct($categoryId, ['name' => 'Visible Naan', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->voidReasonId = (int) DB::connection('tenant')->table('void_reasons')->insertGetId([
            'name' => 'Guest changed mind', 'reason_type' => 'cancel', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $permId = (int) DB::connection('tenant')->table('permissions')->insertGetId([
            'name' => 'tenant.pos.void-kot-item', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$this->userId, $this->managerId] as $uid) {
            DB::connection('tenant')->table('model_has_permissions')->insert(['permission_id' => $permId, 'model_type' => User::class, 'model_id' => $uid]);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->baselineId = (int) $this->acceptTestBaseline([
            ['product_id' => $this->productP, 'product_variant_id' => null, 'quantity' => 20],
            ['product_id' => $this->productQ, 'product_variant_id' => null, 'quantity' => 20],
        ])->id;
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->seedEdgeCredential($this->managerId, $this->branchId, 1, 'MgrPass1');
        $this->managerCode = (string) User::on('tenant')->find($this->managerId)->employee_code;
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');

        // The operator starts on Counter A with an open shift.
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalA])->assertOk();
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

    /** Move the operator to Counter B (its own open shift) — the "recall at another counter" situation. */
    private function switchToCounterB(): void
    {
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalB])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
    }

    private function openTableAndHold(float $qty = 2): array
    {
        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['restaurant_waiter_id' => $this->waiterId, 'guest_count' => 3])
            ->assertStatus(201)->json('session_id');
        $hold = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->productP, 'quantity' => $qty]],
        ]);
        $hold->assertStatus(201)->assertJsonPath('status', 'held');

        return [$sessionId, (int) $hold->json('sale_id'), (int) $hold->json('lines.0.id')];
    }

    public function test_dine_in_round1_round2_kot_deltas_recall_keeps_original_terminal_and_settles(): void
    {
        // Board: free table, no session, reservation exposed (null).
        $this->getJson('/edge/local/pos/restaurant/board')->assertOk()
            ->assertJsonPath('floors.0.tables.0.status', 'available')
            ->assertJsonPath('floors.0.tables.0.reservation', null);

        [$sessionId, $saleId, $line1] = $this->openTableAndHold(2);

        // Board reflects the occupied table with its waiter + open check; Recall list + detail carry what the page needs.
        $board = $this->getJson('/edge/local/pos/restaurant/board')->assertOk();
        $this->assertSame('occupied', $board->json('floors.0.tables.0.status'));
        $this->assertSame('Waiter Ali', $board->json('floors.0.tables.0.session.waiter_name'));
        $this->assertSame($saleId, (int) $board->json('floors.0.tables.0.session.held_orders.0.id'));

        $list = $this->getJson('/edge/local/pos/held-sales')->assertOk();
        $this->assertSame($saleId, (int) $list->json('held_sales.0.id'));
        $this->assertSame('T1', $list->json('held_sales.0.table_no'));
        $this->assertSame('Waiter Ali', $list->json('held_sales.0.waiter_name'));
        $this->assertSame($this->terminalA, (int) $list->json('held_sales.0.terminal_id'));
        $this->assertSame(2.0, (float) $list->json('held_sales.0.item_count'));

        $detail = $this->getJson("/edge/local/pos/held-sales/{$saleId}")->assertOk();
        $this->assertSame($line1, (int) $detail->json('held_sale.lines.0.id'));
        $this->assertSame(100.0, (float) $detail->json('held_sale.lines.0.unit_price'));
        $this->assertSame(0.0, (float) $detail->json('held_sale.lines.0.kot_sent_quantity'));

        // KOT ROUND 1 — the intended lines (2 × P).
        $kot1 = $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();
        $this->assertSame(1, (int) $kot1->json('batch.sequence_no'));
        $this->assertCount(1, $kot1->json('batch.lines'));
        $this->assertSame(2.0, (float) $kot1->json('batch.lines.0.quantity'));

        // RECALL at another counter: the operator moves to Counter B and continues THIS check.
        $this->switchToCounterB();
        $saleDateBefore = DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('sale_date');

        // ADD ROUND — the canonical second-helping shape: raise the NAMED line 2→3 AND add a new line (Q).
        $round2 = $this->postJson('/edge/local/pos/held-sales', [
            'held_sale_id' => $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [
                ['sales_order_line_id' => $line1, 'product_id' => $this->productP, 'quantity' => 3],
                ['product_id' => $this->productQ, 'quantity' => 1],
            ],
        ]);
        $round2->assertOk()->assertJsonPath('grand_total', 350); // 3×100 + 1×50

        // RECALL-TERMINAL parity: the operator's selection is still Counter B; the ORDER still belongs to Counter A.
        $this->getJson('/edge/local/pos/terminals')->assertOk()->assertJsonPath('selected_terminal_id', $this->terminalB);
        $this->assertSame($this->terminalA, (int) DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('terminal_id'), 'recall/revision never rewrites the original counter');

        // KOT ROUND 2 — ONLY the new intended quantity: P delta 1 + Q 1. No duplicate of round 1, no missing helping.
        $kot2 = $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();
        $this->assertSame(2, (int) $kot2->json('batch.sequence_no'));
        $this->assertSame('addition', $kot2->json('batch.event_type'));
        $sent = collect($kot2->json('batch.lines'))->mapWithKeys(fn ($l) => [$l['product_name'] => (float) $l['quantity']]);
        $this->assertSame(['Hidden Later Karahi' => 1.0, 'Visible Naan' => 1.0], $sent->sortKeys()->all(), 'round 2 sends exactly the second helping and the new line');
        $this->assertSame(2, DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->count());
        $this->assertSame(2.0, (float) DB::connection('tenant')->table('kot_batch_lines')
            ->join('kot_batches', 'kot_batches.id', '=', 'kot_batch_lines.kot_batch_id')
            ->where('kot_batches.sales_order_id', $saleId)->where('kot_batches.sequence_no', 1)->sum('kot_batch_lines.quantity'), 'round 1 is never re-sent');
        // nothing left unsent → no third batch.
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk()->assertJsonPath('batch', null);

        // The page itself still renders with an open check on the branch (its payload path runs).
        $this->get('/edge/local/pos')->assertOk()->assertSee('Hidden Later Karahi');

        // SETTLE from Counter B: cash 350 → paid; SALE-DATE-TRUTH: the order's time is untouched; the
        // check's OWN shift took the cash; the session closes and the table frees.
        $settle = $this->postJson("/edge/local/pos/held-sales/{$saleId}/settle", [
            'client_uuid' => (string) Str::uuid(),
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 350, 'tendered_amount' => 400]],
        ]);
        $settle->assertStatus(200)->assertJsonPath('status', 'paid')->assertJsonPath('change_amount', 50);
        $row = DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->first();
        $this->assertSame((string) $saleDateBefore, (string) $row->sale_date, 'SALE-DATE-TRUTH: payment must not rewrite the order time');
        $this->assertSame($this->terminalA, (int) $row->terminal_id, 'the settled order still reports under its original counter');
        $this->assertSame('closed', DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $sessionId)->value('status'));
        $this->assertSame('available', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableId)->value('status'));
        $this->assertSame(17.0, $this->edgeOnHand($this->baselineId, $this->productP), 'stock consumed once, for the FINAL quantities');
        $this->assertSame(19.0, $this->edgeOnHand($this->baselineId, $this->productQ));
        $this->assertSame(1, DB::connection('tenant')->table('edge_sync_outbox')->count(), 'one outbox row for the settled sale');
    }

    public function test_hidden_product_on_open_bill_stays_recallable_and_payable_through_the_real_route(): void
    {
        [$sessionId, $saleId, $line1] = $this->openTableAndHold(2);

        // The product is hidden from the menu AFTER it landed on the open bill (the 30 Aug outage shape).
        DB::connection('tenant')->table('products')->where('id', $this->productP)->update(['is_pos_visible' => 0]);

        // 1. The REAL page still loads and its payload carries the product — flagged hidden — so Recall can read the line.
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        $this->assertStringContainsString('"name":"Hidden Later Karahi"', $html);
        $this->assertMatchesRegularExpression('/"name":"Hidden Later Karahi".{0,120}"hidden":true/s', $html);
        $this->assertMatchesRegularExpression('/"name":"Visible Naan".{0,120}"hidden":false/s', $html);

        // 2. Recall detail still returns the line.
        $this->getJson("/edge/local/pos/held-sales/{$saleId}")->assertOk()->assertJsonPath('held_sale.lines.0.product_id', $this->productP);

        // 3. Add Round CARRYING the hidden line (by id) is accepted; a NEW line of the hidden product is refused.
        $this->postJson('/edge/local/pos/held-sales', [
            'held_sale_id' => $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [
                ['sales_order_line_id' => $line1, 'product_id' => $this->productP, 'quantity' => 2],
                ['product_id' => $this->productQ, 'quantity' => 1],
            ],
        ])->assertOk()->assertJsonPath('grand_total', 250);
        $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->productP, 'quantity' => 1]],
        ])->assertStatus(422);

        // 4. The bill is still payable.
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/settle", [
            'client_uuid' => (string) Str::uuid(),
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 250]],
        ])->assertOk()->assertJsonPath('status', 'paid');
    }

    public function test_whole_order_cancel_frees_table_prints_at_current_counter_and_keeps_original_terminal(): void
    {
        [$sessionId, $saleId] = $this->openTableAndHold(2);
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();

        // The cancelling operator stands at Counter B.
        $this->switchToCounterB();

        // Branch mode requires the manager for sent food — refused without approval, accepted with it.
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/cancel", ['reason_id' => $this->voidReasonId])->assertStatus(422);
        $approvalId = $this->postJson('/edge/local/pos/manager-approvals/verify', [
            'manager_employee_code' => $this->managerCode, 'manager_credential' => 'MgrPass1',
            'action_type' => 'cancel_held_order', 'payload' => ['sales_order_id' => $saleId],
        ])->json('approval_id');
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/cancel", ['reason_id' => $this->voidReasonId, 'manager_approval_id' => $approvalId])
            ->assertOk()->assertJsonPath('status', 'cancelled');

        // WHOLE-CANCEL-FREES-TABLE: session ended, table handed back, stock untouched.
        $this->assertSame('cancelled', DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $sessionId)->value('status'));
        $this->assertSame('available', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableId)->value('status'));
        $this->assertSame(20.0, $this->edgeOnHand($this->baselineId, $this->productP));

        // POS-CANCEL-TERMINAL-1: the cancellation KOT is routed at the CURRENT counter (B); the order keeps A.
        $cancelJob = DB::connection('tenant')->table('print_jobs')->where('reference_id', $saleId)
            ->where('document_type', 'kot')->where('payload->kot_event_type', 'cancel')->orderByDesc('id')->first();
        $this->assertNotNull($cancelJob, 'a cancellation KOT business event is recorded');
        $this->assertSame((string) $this->terminalB, (string) $cancelJob->terminal_id, 'the cancellation prints where the operator stands');
        $this->assertSame($this->terminalA, (int) DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('terminal_id'), 'the cancelled order still reports under its original counter');
    }

    public function test_draft_refuses_kot_until_held_normally(): void
    {
        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->json('session_id');
        $draft = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId, 'save_as_draft' => true,
            'lines' => [['product_id' => $this->productP, 'quantity' => 1]],
        ])->assertStatus(201)->assertJsonPath('is_draft', true);
        $saleId = (int) $draft->json('sale_id');
        $lineId = (int) $draft->json('lines.0.id');

        // DRAFT: no kitchen ticket; the Recall list shows it as a draft.
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertStatus(422);
        $this->getJson('/edge/local/pos/held-sales')->assertOk()->assertJsonPath('held_sales.0.is_draft', true);

        // Holding it normally (same check, carried line) makes the KOT go.
        $this->postJson('/edge/local/pos/held-sales', [
            'held_sale_id' => $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId, 'save_as_draft' => false,
            'lines' => [['sales_order_line_id' => $lineId, 'product_id' => $this->productP, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('is_draft', false);
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk()->assertJsonPath('batch.sequence_no', 1);
    }

    public function test_close_empty_table_from_board_and_refused_while_a_check_is_open(): void
    {
        // Opened by mistake → the board offers Close (empty) → the session ends and the table frees.
        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 1])->json('session_id');
        $this->getJson('/edge/local/pos/restaurant/board')->assertOk()->assertJsonPath('floors.0.tables.0.session.held_orders', []);
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/close", ['status' => 'closed'])->assertOk()->assertJsonPath('status', 'closed');
        $this->assertSame('available', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableId)->value('status'));

        // With a live check on it, the close is REFUSED — an order is never lost.
        [$sessionId2, $saleId] = $this->openTableAndHold(1);
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId2}/close", ['status' => 'closed'])->assertStatus(422);
        $this->assertSame('held', DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('status'));
        $this->assertSame('occupied', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableId)->value('status'));
    }
}
