<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W3 (Team 3) — the order-lifecycle surfaces of the Online POS on the Branch Server, over REAL HTTP:
 *  - A27 Recent / Completed Orders (Online POSController::recentSales): non-held sales of the branch, allowed types only,
 *    optional type filter that narrows but never widens, newest first, print state;
 *  - A26 Held Orders (Online HeldSaleController::ajaxList): type filter + cancel from the list (reason + approval);
 *  - R25 sent-line voids from the page payload shape: ONE line → void_kot_item approval; SEVERAL lines in one save → ONE grouped
 *    void_kot_items approval (the shared KotCancellationService rule the page follows);
 *  - R25/R27 the reason book carries the branch approval modes the page uses for "Manager code required";
 *  - the page renders every W3 control id (the census gate verifies the full register).
 */
class EdgeCashierOrderLifecycleHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $managerId;
    private string $managerCode;
    private int $tableId;
    private int $productP;
    private int $productQ;
    private int $cashMethodId;
    private int $voidReasonId;

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
            'manager_approvals', 'manager_pins', 'void_reasons', 'model_has_permissions', 'permissions',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi',
            'held_kot_cancellation_approval_mode' => 'manager_required', 'held_kot_line_cancellation_approval_mode' => 'manager_required']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'OL' . Str::random(4)]);
        $this->managerId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'OLM' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'available', 'capacity' => 4]);
        $cat = $this->makeCategory(['name' => 'Karahi']);
        $this->productP = $this->makeProduct($cat, ['name' => 'Karahi', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->productQ = $this->makeProduct($cat, ['name' => 'Naan', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->voidReasonId = (int) DB::connection('tenant')->table('void_reasons')->insertGetId(['name' => 'Guest changed mind', 'reason_type' => 'cancel', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([
            ['product_id' => $this->productP, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->productQ, 'product_variant_id' => null, 'quantity' => 50],
        ]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->seedEdgeCredential($this->managerId, $this->branchId, 1, 'MgrPass1');
        foreach ([$this->userId, $this->managerId] as $uid) {
            $this->grantEdgePermission($uid, 'tenant.pos.void-kot-item');
        }
        $this->managerCode = (string) User::on('tenant')->find($this->managerId)->employee_code;
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

    private function approve(string $action, array $payload): int
    {
        return (int) $this->postJson('/edge/local/pos/manager-approvals/verify', [
            'manager_employee_code' => $this->managerCode, 'manager_credential' => 'MgrPass1', 'action_type' => $action, 'payload' => $payload,
        ])->assertStatus(201)->json('approval_id');
    }

    private function quickSale(float $amount = 100): int
    {
        return (int) $this->postJson('/edge/local/pos/sales', [
            'client_uuid' => (string) Str::uuid(), 'order_type' => 'takeaway',
            'lines' => [['product_id' => $this->productP, 'quantity' => $amount / 100]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => $amount, 'tendered_amount' => $amount]],
        ])->assertStatus(201)->json('sale_id');
    }

    public function test_recent_orders_list_non_held_sales_with_filters_and_print_state(): void
    {
        $paid = $this->quickSale(100);
        $held = (int) $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'takeaway', 'lines' => [['product_id' => $this->productQ, 'quantity' => 1]]])->assertStatus(201)->json('sale_id');
        $this->postJson("/edge/local/pos/sales/{$paid}/receipt", [])->assertSuccessful();

        $r = $this->getJson('/edge/local/pos/recent-sales')->assertOk();
        $ids = collect($r->json('sales'))->pluck('id')->all();
        $this->assertContains($paid, $ids);
        $this->assertNotContains($held, $ids, 'held checks live in Held Orders, never in Recent Orders');
        $row = collect($r->json('sales'))->firstWhere('id', $paid);
        $this->assertSame('paid', $row['status']);
        $this->assertSame('Walk-in', $row['customer']);
        $this->assertSame(100.0, (float) $row['grand_total']);
        $this->assertNotNull($row['printing'], 'the receipt job is reported');

        // A filter narrows; a type the operator may not run never widens the list.
        $this->assertNotContains($paid, collect($this->getJson('/edge/local/pos/recent-sales?order_type=delivery')->assertOk()->json('sales'))->pluck('id')->all());
        $this->assertContains($paid, collect($this->getJson('/edge/local/pos/recent-sales?order_type=takeaway')->json('sales'))->pluck('id')->all());
        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['allowed_order_types' => json_encode(['dine_in'])]);
        auth('tenant')->setUser(User::on('tenant')->find($this->userId));
        $this->assertNotContains($paid, collect($this->getJson('/edge/local/pos/recent-sales?order_type=takeaway')->assertOk()->json('sales'))->pluck('id')->all(),
            'an operator only ever sees the order types he may run');
    }

    public function test_held_orders_type_filter_and_cancel_from_the_list_with_manager_approval(): void
    {
        $sessionId = (int) $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->json('session_id');
        $dine = (int) $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId, 'lines' => [['product_id' => $this->productP, 'quantity' => 1]]])->assertStatus(201)->json('sale_id');
        $take = (int) $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'takeaway', 'lines' => [['product_id' => $this->productQ, 'quantity' => 1]]])->assertStatus(201)->json('sale_id');

        $this->assertEqualsCanonicalizing([$dine, $take], collect($this->getJson('/edge/local/pos/held-sales')->assertOk()->json('held_sales'))->pluck('id')->all());
        $this->assertSame([$take], collect($this->getJson('/edge/local/pos/held-sales?order_type=takeaway')->assertOk()->json('held_sales'))->pluck('id')->all());
        $row = collect($this->getJson('/edge/local/pos/held-sales?order_type=dine_in')->json('held_sales'))->first();
        $this->assertSame('T1', $row['table_no']);
        $this->assertNotNull($row['updated_at']);

        // R27: cancel with food already sent in manager_required mode — refused without approval, accepted with it.
        $this->postJson("/edge/local/pos/held-sales/{$dine}/kot")->assertOk();
        $this->postJson("/edge/local/pos/held-sales/{$dine}/cancel", ['reason_id' => $this->voidReasonId])->assertStatus(422)
            ->assertJsonPath('message', 'Manager approval is required for this branch.');
        $approval = $this->approve('cancel_held_order', ['sales_order_id' => $dine]);
        $cx = $this->postJson("/edge/local/pos/held-sales/{$dine}/cancel", ['reason_id' => $this->voidReasonId, 'manager_approval_id' => $approval])->assertOk()->assertJsonPath('status', 'cancelled');
        // T3-2 (D-05): the CANCEL KOT jobs come back for the page's handlePrintJobs (no printer mapped → browser fallback).
        $this->assertNotEmpty($cx->json('jobs'), 'the cancel returns the CANCEL KOT job(s) it created');
        $cxJob = $cx->json('jobs.0');
        foreach (['id', 'document_type', 'print_status', 'printer_name', 'fallback', 'preview_url'] as $k) {
            $this->assertArrayHasKey($k, $cxJob);
        }
        $this->assertSame('kot', $cxJob['document_type']);
        $this->assertTrue($cxJob['fallback']);
        $this->assertStringContainsString('/edge/local/pos/print-jobs/' . $cxJob['id'] . '/document', $cxJob['preview_url']);
        $this->assertSame('available', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableId)->value('status'), 'cancel frees the table');
        // Nothing sent to the kitchen → no approval needed even in manager_required mode (the shared rule).
        $this->postJson("/edge/local/pos/held-sales/{$take}/cancel", ['reason_id' => $this->voidReasonId])->assertOk();
        $this->assertSame([], $this->getJson('/edge/local/pos/held-sales')->json('held_sales'));
    }

    public function test_sent_line_voids_single_and_grouped_approval_and_reason_book_modes(): void
    {
        $meta = $this->getJson('/edge/local/pos/void-reasons')->assertOk();
        $this->assertSame('manager_required', $meta->json('line_approval_mode'));
        $this->assertSame('manager_required', $meta->json('order_approval_mode'));
        $this->assertSame($this->voidReasonId, (int) $meta->json('reasons.0.id'));

        $hold = $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'takeaway',
            'lines' => [['product_id' => $this->productP, 'quantity' => 3], ['product_id' => $this->productQ, 'quantity' => 2]]])->assertStatus(201);
        $saleId = (int) $hold->json('sale_id');
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();
        $lines = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->orderBy('id')->get();
        [$lp, $lq] = [$lines[0], $lines[1]];

        // ONE reduced line (3 → 2): void_kot_item approval bound to {sale, line, qty} — the page's approveVoids(single) payload.
        $a1 = $this->approve('void_kot_item', ['sales_order_id' => $saleId, 'sales_order_line_id' => (int) $lp->id, 'quantity' => 1]);
        $r1 = $this->postJson('/edge/local/pos/held-sales', ['held_sale_id' => $saleId, 'order_type' => 'takeaway',
            'lines' => [['sales_order_line_id' => $lp->id, 'product_id' => $this->productP, 'quantity' => 2], ['sales_order_line_id' => $lq->id, 'product_id' => $this->productQ, 'quantity' => 2]],
            'void_items' => [['old_line_id' => $lp->id, 'quantity' => 1, 'reason_id' => $this->voidReasonId, 'manager_approval_id' => $a1]]])->assertOk();
        $this->assertSame(300.0, (float) $r1->json('grand_total'));
        // T3-3 (D-06): a revise with voids runs Team 5's line-void correction-Reminder planning for THIS save's cancel batch.
        $this->assertIsArray($r1->json('void_print_jobs'));
        $this->assertSame(1, DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->where('event_type', 'cancel')->count());

        // TWO reduced lines in one save: the shared service requires ONE grouped void_kot_items approval.
        $lines = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->orderBy('id')->get();
        [$lp, $lq] = [$lines[0], $lines[1]];
        $payload = fn (?int $ap) => ['held_sale_id' => $saleId, 'order_type' => 'takeaway',
            'lines' => [['sales_order_line_id' => $lp->id, 'product_id' => $this->productP, 'quantity' => 1]],
            'void_items' => [
                ['old_line_id' => $lp->id, 'quantity' => 1, 'reason_id' => $this->voidReasonId, 'manager_approval_id' => $ap],
                ['old_line_id' => $lq->id, 'quantity' => 2, 'reason_id' => $this->voidReasonId, 'manager_approval_id' => $ap],
            ]];
        $this->postJson('/edge/local/pos/held-sales', $payload(null))->assertStatus(422)
            ->assertJsonPath('message', 'One manager approval is required for this grouped cancellation.');
        $cancellations = collect([['line_id' => (int) $lp->id, 'quantity' => 1], ['line_id' => (int) $lq->id, 'quantity' => 2]])->sortBy('line_id')->values()->all();
        $a2 = $this->approve('void_kot_items', ['sales_order_id' => $saleId, 'cancellations' => $cancellations]);
        $r2 = $this->postJson('/edge/local/pos/held-sales', $payload($a2))->assertOk();
        $this->assertSame(100.0, (float) $r2->json('grand_total'));
        $this->assertSame(3, DB::connection('tenant')->table('sales_order_line_cancellations')->where('sales_order_id', $saleId)->count());
        $this->assertSame(2, DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->where('event_type', 'cancel')->count());
        $this->assertSame(1.0, (float) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->value('kot_sent_quantity'));
    }

    public function test_hold_keeps_kitchen_notes_and_change_order_details_retargets_one_check(): void
    {
        // W2 fields pass the held-sale validation and come back for Recall / Add Round re-hydration.
        $hold = $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'takeaway',
            'lines' => [['product_id' => $this->productP, 'quantity' => 1, 'kitchen_note' => 'no chilli']]])->assertStatus(201);
        $saleId = (int) $hold->json('sale_id');
        $line = $this->getJson("/edge/local/pos/held-sales/{$saleId}")->assertOk()->json('held_sale.lines.0');
        $this->assertSame('no chilli', $line['kitchen_note']);
        foreach (['modifiers', 'variant_name', 'unit_code', 'discount_amount'] as $k) {
            $this->assertArrayHasKey($k, $line);
        }
        $this->assertArrayHasKey('notes', $this->getJson("/edge/local/pos/held-sales/{$saleId}")->json('held_sale'));

        // R21 Change Order Details: the held takeaway check becomes dine-in on table T1 (change_order_details passes validation).
        $sessionId = (int) $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->assertStatus(201)->json('session_id');
        $this->postJson('/edge/local/pos/held-sales', ['held_sale_id' => $saleId, 'order_type' => 'dine_in', 'change_order_details' => true,
            'restaurant_table_session_id' => $sessionId,
            'lines' => [['sales_order_line_id' => $line['id'], 'product_id' => $this->productP, 'quantity' => 1]]])->assertOk();
        $row = DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->first();
        $this->assertSame('dine_in', $row->order_type);
        $this->assertSame($sessionId, (int) $row->restaurant_table_session_id);
        $this->assertSame('no chilli', DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->value('kitchen_note'), 'the carried line keeps its kitchen note');
    }
    public function test_the_cashier_page_renders_the_w3_controls(): void
    {
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        foreach ([
            'tableWorkspaceModal', 'table-workspace-back', 'table-workspace-board', 'table-board-body', 'table-workspace-open', 'open-table-form',
            'restaurant_waiter_id', 'waiter-roster', 'guest_count', 'table_notes', 'open-table-submit', 'table-workspace-held', 'table-workspace-held-body',
            'table-workspace-move', 'table-workspace-move-body', 'table-workspace-split', 'table-workspace-split-body', 'table-workspace-manage',
            'pos-session-bar', 'pos-session-details', 'pos-session-table-no', 'pos-session-no', 'pos-session-waiter', 'pos-session-guests',
            'pos-session-open-check', 'pos-session-actions', 'pos-session-bill-preview', 'pos-session-request-bill-form',
            'heldSalesModal', 'held-type-filters', 'held-sales-modal-body', 'completedOrdersModal', 'recent-type-filters', 'completed-orders-modal-body',
            'completed-orders-btn', 'deadSessionModal', 'dead-table-pick', 'dead-move', 'dead-reopen',
            'changeOrderModal', 'co-type-btns', 'co-order-type', 'co-table-wrap', 'co-table-session', 'co-terminal', 'co-branch', 'co-apply-btn', 'edit-order-btn',
            'clear-cart-btn', 'start-fresh-btn', 'start-fresh-label', 'new-sale-btn', 'recalled-order-bar', 'recalled-order-no', 'pos-draft-badge',
            'reserveTableModal', 'reserve-customer-search', 'reserve-customer-suggest', 'reserve-customer-chip', 'reserve-customer-name', 'reserve-customer-clear',
            'reserve-customer-id', 'reserve-name', 'reserve-phone', 'reserve-for', 'reserve-note', 'reserve-save-btn', 'reservationDetailsModal', 'reservation-details-body', 'open-table-error', 'reserve-toast',
        ] as $id) {
            $this->assertTrue(str_contains($html, 'id="' . $id . '"') || str_contains($html, "'" . $id . "'"), "W3 control #{$id} is missing from the cashier page");
        }
        foreach (['function openTableWorkspace', 'function openHeldOrders', 'function openCompletedOrders', 'function openChangeOrder', 'function clearCart',
            'function newSale', 'function voidSentLine', 'function renderSessionBar', 'function requestBill', 'function moveTable', 'function mergeTables',
            'function viewTables', 'function recallList', "'/recent-sales'", "'/bill-requested'", "'/reattach-table'", 'await fireKot(id)', 'function kotAfterHold', "handlePrintJobs(r.jobs, 'CANCEL KOT')", 'openLastPrint(id, no)', "tablePayload('here')"] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }
}
