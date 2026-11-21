<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W-B §3.2 — the canonical JSON contract: the Edge endpoints answer in the SAME shapes / codes as the Online endpoints the
 * shared page calls, so `tenant.pos.index` has ONE code path. Every change is ADDITIVE for the old Edge page (its keys stay).
 *
 *   board html twin {ok, html} · open-orders twin (O14) · held list `sales` with lines + Online keys · TABLE_HAS_OPEN_ORDERS 409 ·
 *   NO_OPEN_SHIFT / INVALID_TERMINAL codes · request terminal adoption · totals/promo quote twins (O16/O17) · manager verify
 *   200 {ok} + {ok:false} + throttle:10,1 · 403 {message, permission} on returns · print job_id / created_at_human ·
 *   recent sales time/ago · reservation Online field names + {ok, reservation} · customer search keys · settle takes the full
 *   sale payload.
 */
class EdgeSharedPosContractMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $terminal2Id;
    private int $userId;
    private int $managerId;
    private string $managerCode;
    private int $tableId;
    private int $table2Id;
    private int $categoryId;
    private int $karahi;
    private int $naan;
    private int $cashMethodId;
    private int $customerId;

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
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations',
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'printers', 'terminal_printer_settings',
            'manager_approvals', 'model_has_permissions', 'permissions',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'promotion_targets', 'promotions', 'customer_addresses', 'customers',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'product_modifier_group', 'modifiers', 'modifier_groups',
            'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'manual_discount_approval_mode' => 'manager_required', 'sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CON' . Str::random(4)]);
        $this->managerId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'MGR' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter 1']);
        $this->terminal2Id = $this->makeTerminal($this->branchId, ['name' => 'Counter 2']);
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'available']);
        $this->table2Id = $this->makeTable($this->branchId, ['table_no' => 'T2', 'status' => 'available']);
        $this->makeWaiter($this->branchId, ['name' => 'Waiter Ali']);
        $this->categoryId = $this->makeCategory(['name' => 'Karahi']);
        $this->karahi = $this->makeProduct($this->categoryId, ['name' => 'Chicken Karahi', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->naan = $this->makeProduct($this->categoryId, ['name' => 'Roghni Naan', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->customerId = (int) DB::connection('tenant')->table('customers')->insertGetId(['customer_uuid' => (string) Str::ulid(), 'code' => 'C-' . Str::random(6), 'name' => 'Mr Zafar', 'phone' => '0300-7777777', 'email' => 'zafar@example.test', 'address' => 'Old Town', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('customer_addresses')->insert(['customer_id' => $this->customerId, 'label' => 'Home', 'address' => 'House 1', 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('promotions')->insert(['branch_id' => null, 'name' => 'Save Ten', 'code' => 'SAVE10', 'promotion_type' => 'order', 'discount_type' => 'percent', 'discount_value' => 10, 'min_order_amount' => 0, 'requires_code' => 1, 'used_count' => 0, 'status' => 'active', 'priority' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([
            ['product_id' => $this->karahi, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->naan, 'product_variant_id' => null, 'quantity' => 50],
        ]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->seedEdgeCredential($this->managerId, $this->branchId, 1, 'MgrPass1');
        $this->markPosApprover($this->managerId); // Phase 3: eligibility is the bootstrap flag, not a permission
        $this->managerCode = (string) User::on('tenant')->find($this->managerId)->employee_code;
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

    private function selectAndOpen(): void
    {
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
    }

    private function cash(float $amount): array
    {
        return [['payment_method_id' => $this->cashMethodId, 'amount' => $amount, 'tendered_amount' => $amount]];
    }

    public function test_terminal_codes_request_terminal_adoption_and_no_open_shift(): void
    {
        // No terminal selected → 422 INVALID_TERMINAL (Online code) on every terminal-bound endpoint.
        $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'takeaway', 'lines' => [['product_id' => $this->naan, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_TERMINAL');
        $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->assertStatus(422)->assertJsonPath('code', 'INVALID_TERMINAL');
        // The shared page asks the badge for ITS terminal: empty → Online's no-terminal answer.
        $this->getJson('/edge/local/pos/shift?terminal_id=')->assertOk()->assertJsonPath('has_terminal', false)->assertJsonPath('open', false);

        // A request-named terminal (Online per-request semantics) is ADOPTED into the session when valid …
        $this->getJson('/edge/local/pos/shift?terminal_id=' . $this->terminalId)->assertOk()->assertJsonPath('has_terminal', true)->assertJsonPath('terminal_id', $this->terminalId);
        $this->getJson('/edge/local/pos/terminals')->assertOk()->assertJsonPath('selected_terminal_id', $this->terminalId);
        // … refused when it is not an active terminal of the bound branch.
        $this->getJson('/edge/local/pos/shift?terminal_id=999999')->assertStatus(422)->assertJsonPath('code', 'INVALID_TERMINAL');

        // No open shift → Online's NO_OPEN_SHIFT code (hold + open table).
        $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'takeaway', 'lines' => [['product_id' => $this->naan, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('code', 'NO_OPEN_SHIFT');
        $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->assertStatus(422)->assertJsonPath('code', 'NO_OPEN_SHIFT');

        // Online posStatus keys on an open shift (additive).
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
        $status = $this->getJson('/edge/local/pos/shift?terminal_id=' . $this->terminalId)->assertOk();
        foreach (['has_terminal', 'open', 'shift_id', 'shift_uuid', 'business_date', 'timezone', 'opened_at', 'server_epoch_ms', 'open_url', 'shift'] as $k) {
            $status->assertJsonStructure([$k]);
        }
        $this->assertSame('/edge/local/pos/shifts/open', $status->json('open_url'));
        // A pinned operator naming another terminal is refused (terminal authority, 403).
        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['default_terminal_id' => $this->terminalId]);
        $this->revokeEdgePermission($this->userId, 'tenant.pos.change-terminal');
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        $this->getJson('/edge/local/pos/shift?terminal_id=' . $this->terminal2Id)->assertStatus(403);
    }

    public function test_board_html_open_orders_held_list_and_table_has_open_orders(): void
    {
        $this->selectAndOpen();
        $sessionId = (int) $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 3])->assertStatus(201)->json('session_id');
        $held = $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->karahi, 'quantity' => 2, 'kitchen_note' => 'spicy']]])->assertStatus(201);
        $heldId = (int) $held->json('sale_id');

        // Board HTML twin — Online {ok, html} rendering the SHARED partial, selection highlighted, Edge paths only.
        $board = $this->getJson('/edge/local/pos/restaurant/board/html?selected_session_id=' . $sessionId)->assertOk()->assertJsonPath('ok', true);
        $html = (string) $board->json('html');
        $this->assertStringContainsString('restaurant-table-tile', $html);
        $this->assertStringContainsString('data-session-id="' . $sessionId . '"', $html);
        $this->assertStringContainsString('Selected / Continue', $html);
        $this->assertDoesNotMatchRegularExpression('#["\'=(]/pos[?/"\']#', $html, 'no Cloud /pos link in the Edge board');

        // Open-orders twin (O14).
        $oo = $this->getJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/open-orders")->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('table_session_id', $sessionId)->assertJsonPath('branch_id', $this->branchId)
            ->assertJsonPath('session.table_no', 'T1')->assertJsonPath('session.branch_id', $this->branchId)
            ->assertJsonPath('orders.0.id', $heldId)->assertJsonPath('orders.0.recall_url', null)
            ->assertJsonPath('orders.0.items_count', 1)->assertJsonPath('orders.0.grand_total_formatted', '200.00')
            ->assertJsonPath('orders.0.lines.0.kitchen_note', 'spicy');
        foreach (['id', 'product_id', 'product_variant_id', 'parent_sales_order_line_id', 'line_kind', 'combo_id', 'quantity', 'unit_price', 'discount_amount', 'tax_amount', 'line_total', 'product_name', 'variant_name', 'unit_code', 'modifiers', 'kot_sent', 'kot_sent_quantity', 'kitchen_note'] as $k) {
            $this->assertArrayHasKey($k, $oo->json('orders.0.lines.0'), "open-orders line key {$k}");
        }

        // Held list — Online `sales` (lines embedded, formatted total, item count, customer/waiter/table); old `held_sales` kept.
        $list = $this->getJson('/edge/local/pos/held-sales')->assertOk();
        $this->assertSame($heldId, (int) $list->json('held_sales.0.id'));
        $row = collect($list->json('sales'))->firstWhere('id', $heldId);
        $this->assertSame('200.00', $row['total']);
        $this->assertSame(1, $row['items']);
        $this->assertSame('Walk-in', $row['customer']);
        $this->assertSame('T1', $row['table']);
        $this->assertCount(1, $row['lines']);
        $this->assertSame('spicy', $row['lines'][0]['kitchen_note']);

        // A second NEW check on the same session → Online's TABLE_HAS_OPEN_ORDERS 409 with the session + its orders.
        $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->naan, 'quantity' => 1]]])
            ->assertStatus(409)->assertJsonPath('ok', false)->assertJsonPath('code', 'TABLE_HAS_OPEN_ORDERS')
            ->assertJsonPath('table_session_id', $sessionId)->assertJsonPath('orders.0.id', $heldId);
        $this->assertSame(1, DB::connection('tenant')->table('sales_orders')->where('restaurant_table_session_id', $sessionId)->count(), '409 writes nothing');

        // Settle accepts the FULL Online sale payload (inapplicable keys ignored).
        $this->postJson("/edge/local/pos/held-sales/{$heldId}/settle", [
            'client_uuid' => (string) Str::uuid(), 'held_sale_id' => $heldId, 'branch_id' => $this->branchId, 'terminal_id' => $this->terminalId,
            'order_type' => 'dine_in', 'lines' => [['product_id' => $this->karahi, 'quantity' => 2, 'unit_price' => 1]],
            'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip', 'customer_id' => null,
            'payments' => $this->cash(200),
        ])->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('grand_total', 200);
    }

    public function test_totals_and_promotion_quote_twins_answer_in_online_flat_keys(): void
    {
        $this->selectAndOpen();
        // Online body (client unit_price / category_id are IGNORED — the server prices).
        $q = $this->postJson('/edge/local/pos/totals/quote', ['branch_id' => $this->branchId, 'order_type' => 'takeaway', 'discount_type' => 'none',
            'lines' => [['product_id' => $this->karahi, 'category_id' => $this->categoryId, 'quantity' => 2, 'unit_price' => 1, 'tax_amount' => 0]]])->assertOk();
        foreach (['ok', 'subtotal', 'discount_amount', 'promotion_id', 'promo_code', 'promotion_discount_amount', 'tax_amount', 'service_charge_amount', 'tip_amount', 'delivery_charge_amount', 'grand_total'] as $k) {
            $this->assertArrayHasKey($k, $q->json(), "totals quote key {$k}");
        }
        $this->assertEquals(200.0, $q->json('subtotal'));
        $this->assertEquals(200.0, $q->json('grand_total'));
        $this->assertEquals(180.0, $this->postJson('/edge/local/pos/totals/quote', ['order_type' => 'takeaway', 'promo_code' => 'SAVE10',
            'lines' => [['product_id' => $this->karahi, 'quantity' => 2]]])->assertOk()->json('grand_total'));
        $this->assertEquals(0.0, $this->postJson('/edge/local/pos/totals/quote', ['order_type' => 'takeaway', 'lines' => []])->assertOk()->json('grand_total'));

        // PHASE 3 (B) — the quote is as lenient as Online's POSController::quoteTotals (:734-787, no modifier validation): a
        // REQUIRED single-choice group on the karahi no longer turns every quote into a 422 (the shared view quotes on each cart
        // change, with the modifier modal still open), the options the page names are priced from the synced book, an option
        // not on this menu / a max-violation is tolerated — while the paid sale and Preview Bill keep refusing (the store rule).
        $now = now();
        $t = fn (string $table) => DB::connection('tenant')->table($table);
        $spice = (int) $t('modifier_groups')->insertGetId(['branch_id' => null, 'name' => 'Spice Level', 'min_select' => 1, 'max_select' => 1, 'is_required' => 1, 'sort_order' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $mild = (int) $t('modifiers')->insertGetId(['modifier_group_id' => $spice, 'name' => 'Mild', 'price_delta' => 0, 'linked_product_id' => null, 'consume_stock' => 0, 'linked_quantity' => null, 'is_default' => 1, 'sort_order' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $hot = (int) $t('modifiers')->insertGetId(['modifier_group_id' => $spice, 'name' => 'Hot', 'price_delta' => 20, 'linked_product_id' => null, 'consume_stock' => 0, 'linked_quantity' => null, 'is_default' => 0, 'sort_order' => 2, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $t('product_modifier_group')->insert(['product_id' => $this->karahi, 'modifier_group_id' => $spice, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $quote = fn (array $line) => $this->postJson('/edge/local/pos/totals/quote', ['branch_id' => $this->branchId, 'order_type' => 'takeaway', 'discount_type' => 'none', 'lines' => [$line]]);
        // no modifiers on the quote body (the modal is still open) → 200, priced on the base price — never "Select at least 1 option…"
        $quote(['product_id' => $this->karahi, 'quantity' => 2, 'unit_price' => 1])->assertOk()->assertJsonPath('ok', true)->assertJsonPath('subtotal', 200);
        // the option the page names is priced from the BOOK (+20 Hot), the client's delta is ignored
        $quote(['product_id' => $this->karahi, 'quantity' => 2, 'line_kind' => 'standard', 'modifiers' => [['modifier_id' => $hot, 'name' => 'Hot', 'price_delta' => 999]]])
            ->assertOk()->assertJsonPath('subtotal', 240);
        // an option not on this menu is left out; a max-1 violation is not the quote's business (Online validates neither)
        $quote(['product_id' => $this->karahi, 'quantity' => 1, 'modifiers' => [['modifier_id' => 999999, 'name' => 'Ghost']]])->assertOk()->assertJsonPath('subtotal', 100);
        $quote(['product_id' => $this->karahi, 'quantity' => 1, 'modifiers' => [['modifier_id' => $mild], ['modifier_id' => $hot]]])->assertOk()->assertJsonPath('subtotal', 120);
        // …the STORE and Preview Bill stay strict: the required group is enforced where the kitchen/money is.
        $this->postJson('/edge/local/pos/sales', ['kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip', 'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->karahi, 'quantity' => 1]], 'payments' => $this->cash(100)])
            ->assertStatus(422)->assertJsonPath('message', 'Select at least 1 option for Spice Level on Chicken Karahi.');
        $this->postJson('/edge/local/pos/preview-bill', ['order_type' => 'takeaway', 'lines' => [['product_id' => $this->karahi, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('message', 'Select at least 1 option for Spice Level on Chicken Karahi.');
        $this->assertSame(0, DB::connection('tenant')->table('sales_orders')->count(), 'the lenient quote wrote nothing and the strict store refused');

        $this->postJson('/edge/local/pos/promotions/quote', ['promo_code' => 'SAVE10', 'branch_id' => $this->branchId, 'order_type' => 'takeaway', 'subtotal' => 200,
            'lines' => [['product_id' => $this->karahi, 'quantity' => 2]]])
            ->assertOk()->assertJsonPath('valid', true)->assertJsonPath('promo_code', 'SAVE10')->assertJsonPath('discount_amount', 20)
            ->assertJsonPath('discount_type', 'percent');
        $this->postJson('/edge/local/pos/promotions/quote', ['promo_code' => 'NOPE', 'order_type' => 'takeaway', 'lines' => [['product_id' => $this->karahi, 'quantity' => 2]]])
            ->assertStatus(422)->assertJsonPath('valid', false)->assertJsonPath('promotion_id', null);
    }

    public function test_manager_verify_is_online_shaped_and_throttled(): void
    {
        $this->selectAndOpen();
        $ok = $this->postJson('/edge/local/pos/manager-approvals/verify', ['manager_employee_code' => $this->managerCode, 'manager_credential' => 'MgrPass1',
            'action_type' => 'manual_discount', 'payload' => ['sales_order_id' => 0, 'branch_id' => $this->branchId, 'discount_type' => 'fixed', 'discount_value' => 10, 'discount_amount' => 10]])
            ->assertStatus(200)->assertJsonPath('ok', true);
        foreach (['approval_id', 'approval_no', 'approval_uuid'] as $k) {
            $this->assertNotEmpty($ok->json($k), $k);
        }
        $this->postJson('/edge/local/pos/manager-approvals/verify', ['manager_employee_code' => $this->managerCode, 'manager_credential' => 'wrong',
            'action_type' => 'manual_discount'])->assertStatus(422)->assertJsonPath('ok', false)->assertJsonStructure(['message']);
        // throttle:10,1 (Online's ceiling): the 11th attempt inside a minute is refused.
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/edge/local/pos/manager-approvals/verify', ['manager_employee_code' => 'X', 'manager_credential' => 'y', 'action_type' => 'manual_discount']);
        }
        $this->postJson('/edge/local/pos/manager-approvals/verify', ['manager_employee_code' => 'X', 'manager_credential' => 'y', 'action_type' => 'manual_discount'])->assertStatus(429);
    }

    public function test_403_shape_print_job_aliases_recent_sales_time_reservations_and_customer_search(): void
    {
        $this->selectAndOpen();
        // Returns refuse with the shared {message, permission} 403 (never a bare abort page).
        $this->revokeEdgePermission($this->userId, 'tenant.sales-returns.store');
        $this->getJson('/edge/local/pos/returns/search?q=SO')->assertStatus(403)->assertJsonPath('permission', 'tenant.sales-returns.store')->assertJsonStructure(['message']);
        $this->grantEdgePermission($this->userId, 'tenant.sales-returns.store');
        $this->getJson('/edge/local/pos/returns/search?q=SO')->assertOk()->assertJsonStructure(['sales', 'results', 'pagination']);

        // A paid sale + its receipt job → print job aliases (job_id, printer_id, created_at_human; path-only preview_url).
        $sale = $this->postJson('/edge/local/pos/sales', ['kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip', 'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->naan, 'quantity' => 1]], 'payments' => $this->cash(50)])->assertStatus(201);
        $saleId = (int) $sale->json('sale_id');
        $job = $this->postJson("/edge/local/pos/sales/{$saleId}/receipt")->assertStatus(201);
        $this->assertSame((int) $job->json('id'), (int) $job->json('job_id'));
        $this->assertArrayHasKey('printer_id', $job->json());
        $this->assertNotEmpty($job->json('created_at_human'));
        $this->assertStringStartsWith('/edge/local/pos/print-jobs/', (string) $job->json('preview_url'));
        $this->assertSame((int) $job->json('id'), (int) $this->getJson("/edge/local/pos/print-jobs?sale_id={$saleId}")->assertOk()->json('jobs.0.job_id'));

        // Recent sales: Online `time` (d M, h:i A in the sale's timezone) + `ago`; the ISO instant stays as time_iso.
        $recent = collect($this->getJson('/edge/local/pos/recent-sales')->assertOk()->json('sales'))->firstWhere('id', $saleId);
        $this->assertMatchesRegularExpression('/^\d{2} [A-Z][a-z]{2}, \d{2}:\d{2} (AM|PM)$/', $recent['time']);
        $this->assertNotEmpty($recent['ago']);
        $this->assertNotFalse(strtotime((string) $recent['time_iso']));

        // Reservations accept Online's field names and answer {ok:true, reservation} (+ the old flat keys).
        $r = $this->postJson("/edge/local/pos/restaurant/tables/{$this->table2Id}/reserve", ['reserved_name' => 'Mrs Ahmed', 'reserved_phone' => '0300-1234567',
            'reservation_note' => 'window seat', 'reserved_for' => now()->addHours(2)->toDateTimeString()])->assertStatus(201)
            ->assertJsonPath('ok', true)->assertJsonPath('reservation.customer_name', 'Mrs Ahmed')->assertJsonPath('reservation.note', 'window seat')
            ->assertJsonPath('customer_name', 'Mrs Ahmed');
        $this->assertNotEmpty($r->json('reservation.reserved_for_display'));
        $this->getJson("/edge/local/pos/restaurant/tables/{$this->table2Id}/reservation")->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('reservation.name', 'Mrs Ahmed')->assertJsonPath('reservation.phone', '0300-1234567');
        $this->postJson("/edge/local/pos/restaurant/tables/{$this->table2Id}/unreserve")->assertOk()->assertJsonPath('ok', true);
        $this->postJson("/edge/local/pos/restaurant/tables/{$this->table2Id}/reserve", ['reserved_customer_id' => $this->customerId])->assertStatus(201)
            ->assertJsonPath('reservation.customer_id', $this->customerId);

        // Customer search: Online /ajax/customers keys.
        $c = $this->getJson('/edge/local/pos/customers?q=Zafar')->assertOk()->json('customers.0');
        foreach (['id', 'customer_uuid', 'name', 'phone', 'email', 'addresses', 'legacy_address'] as $k) {
            $this->assertArrayHasKey($k, $c, "customer key {$k}");
        }
        $this->assertSame('zafar@example.test', $c['email']);
        $this->assertSame('Old Town', $c['legacy_address']);
        // Stage B (gap B of the same-dataset comparison): like Online Ajax\CustomerLookupController, an EMPTY or one-character
        // query lists the first 20 ACTIVE customers ordered by name (the shared customer modal opens populated on both runtimes);
        // the former Edge-only 2-character floor is gone. Limit 20 + name order kept; a retired customer is never offered.
        $book = [];
        foreach (range(1, 21) as $n) {
            $book[] = ['customer_uuid' => (string) Str::ulid(), 'code' => sprintf('BK%02d', $n), 'name' => sprintf('Book %02d', $n), 'phone' => '0300-00000' . $n, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()];
        }
        $book[] = ['customer_uuid' => (string) Str::ulid(), 'code' => 'BK99', 'name' => 'Aaa Retired', 'phone' => '0300-9', 'status' => 'inactive', 'created_at' => now(), 'updated_at' => now()];
        DB::connection('tenant')->table('customers')->insert($book);
        $empty = $this->getJson('/edge/local/pos/customers?q=')->assertOk()->json('customers');
        $this->assertCount(20, $empty, 'the 20-row limit holds on an empty query');
        $this->assertSame('Book 01', $empty[0]['name'], 'name order; the inactive "Aaa Retired" is never offered');
        $this->assertSame(array_column($empty, 'name'), collect(array_column($empty, 'name'))->sort()->values()->all());
        $this->assertCount(20, $this->getJson('/edge/local/pos/customers')->assertOk()->json('customers'), 'no q at all = the same list');
        // a ONE-character query SEARCHES (name / phone / code, like the longer ones): the rows whose name, phone or code carries a "Z"
        // — Mr Zafar by name, plus any active row whose code happens to carry the letter (the setUp codes are random) — never the whole book.
        $oneChar = array_column($this->getJson('/edge/local/pos/customers?q=Z')->assertOk()->json('customers'), 'name');
        $expectedZ = DB::connection('tenant')->table('customers')->where('status', 'active')
            ->where(fn ($w) => $w->where('name', 'like', '%Z%')->orWhere('phone', 'like', '%Z%')->orWhere('code', 'like', '%Z%'))
            ->orderBy('name')->limit(20)->pluck('name')->all();
        $this->assertSame($expectedZ, $oneChar, 'a ONE-character query searches');
        $this->assertContains('Mr Zafar', $oneChar);
        $this->assertNotContains('Book 01', $oneChar, 'a one-character query is a search, not the empty-query listing');
        $this->assertSame(['Mr Zafar'], array_column($this->getJson('/edge/local/pos/customers?id=' . $this->customerId)->assertOk()->json('customers'), 'name'), '?id= still pins the exact customer');
        $this->assertSame('House 1', $c['addresses'][0]['address']);
        $this->assertSame(26, strlen((string) $c['customer_uuid']));
    }
}
