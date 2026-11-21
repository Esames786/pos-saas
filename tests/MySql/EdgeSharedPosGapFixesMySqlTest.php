<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W-G3 — the contract gaps the real-browser workflow proof (docs/status/edge-w-g2-workflow-proof-report.md) found between
 * the Online specification and the Branch Server, closed and pinned over the REAL branch_server routes:
 *
 *   G1  the shared cashier view posts MULTIPART (`lines[i][modifiers]` is a JSON STRING; a deal is header + component rows):
 *       Online accepts it (SalesOrderController::validateSale / HeldSaleController::store `nullable|string` + normalise);
 *       Edge used to answer 422 "must be an array" on every sale and hold. Both forms are accepted now, normalised alike.
 *   G2  the hold response carries `client_line_key` per saved line (Online HeldSaleController::store $savedLinePayload), so
 *       the page learns each saved id and the NEXT Hold continues the kitchen-sent line (no 422 "Reducing … below its
 *       kitchen-sent quantity"); the second KOT round is exactly the delta.
 *   E3  the customer quick-add / add-address controls render DISABLED with the PosRuntime capability hint on Edge (same
 *       mechanism as every other capability-off control), and the hint text is what the page shows on a refused save.
 *   X2  the shared page offers a terminal-ASSIGNED operator only his assigned terminals (Online UserDataScope::terminalsForPos),
 *       and a pinned operator only his own — identical to Online's PosController::index rule.
 */
class EdgeSharedPosGapFixesMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $terminal2Id;
    private int $userId;
    private int $tableId;
    private int $waiterId;
    private int $tikka;
    private int $naan;
    private int $drink;
    private int $comboId;
    private int $extrasGroup;
    private int $cheeseOption;
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
            'manager_approvals', 'model_has_permissions', 'permissions', 'cash_count_lines',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'customer_addresses', 'customers', 'delivery_riders', 'delivery_channels',
            'sale_payments', 'sales_order_lines', 'sales_orders',
            'product_modifier_group', 'modifiers', 'modifier_groups',
            'payment_methods', 'combo_components', 'combos', 'products', 'categories', 'shifts', 'terminal_user', 'terminals', 'branches', 'users',
        ]);
        $now = now();
        $t = fn (string $table) => DB::connection('tenant')->table($table);

        $this->branchId = $this->makeBranch(['name' => 'Gap Fix Branch', 'allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'manual_discount_approval_mode' => 'auto_approve']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'GAP' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter One']);
        $this->terminal2Id = $this->makeTerminal($this->branchId, ['name' => 'Counter Two']);
        $floorId = (int) $t('restaurant_floors')->insertGetId(['branch_id' => $this->branchId, 'name' => 'Ground', 'sort_order' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'available', 'restaurant_floor_id' => $floorId]);
        $this->waiterId = $this->makeWaiter($this->branchId, ['name' => 'Waiter Ali']);
        $categoryId = $this->makeCategory(['name' => 'Grills']);
        $this->tikka = $this->makeProduct($categoryId, ['name' => 'Chicken Tikka', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 250]);
        $this->naan = $this->makeProduct($categoryId, ['name' => 'Roghni Naan', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $this->drink = $this->makeProduct($categoryId, ['name' => 'Cold Drink', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        // Options from the SYNCED book (the client's names/deltas are ignored): Extra Cheese +50 on the tikka.
        $this->extrasGroup = (int) $t('modifier_groups')->insertGetId(['branch_id' => null, 'name' => 'Extras', 'min_select' => 0, 'max_select' => 3, 'is_required' => 0, 'sort_order' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->cheeseOption = (int) $t('modifiers')->insertGetId(['modifier_group_id' => $this->extrasGroup, 'name' => 'Extra Cheese', 'price_delta' => 50, 'linked_product_id' => null, 'consume_stock' => 0, 'linked_quantity' => null, 'is_default' => 0, 'sort_order' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $t('product_modifier_group')->insert(['product_id' => $this->tikka, 'modifier_group_id' => $this->extrasGroup, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->comboId = (int) $t('combos')->insertGetId(['branch_id' => $this->branchId, 'code' => 'FAM', 'name' => 'Family Deal', 'price' => 400, 'sort_order' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $t('combo_components')->insert([
            ['combo_id' => $this->comboId, 'product_id' => $this->tikka, 'quantity' => 1, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['combo_id' => $this->comboId, 'product_id' => $this->naan, 'quantity' => 2, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash', 'name' => 'Cash']);
        $this->customerId = (int) $t('customers')->insertGetId(['customer_uuid' => (string) Str::ulid(), 'code' => 'C-' . Str::random(6), 'name' => 'Ahmed Raza', 'phone' => '03001234567', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([
            ['product_id' => $this->tikka, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->naan, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->drink, 'product_variant_id' => null, 'quantity' => 50],
        ]);
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

    /**
     * EXACTLY what index.blade.php buildInputs() emits for one cart row (a multipart form field set): every value a string,
     * `modifiers` a JSON string. Posted with the plain form transport (not JSON), as the browser does.
     */
    private function formLine(array $item): array
    {
        return [
            'sales_order_line_id' => (string) ($item['_dbLineId'] ?? ''),
            'product_id' => (string) ($item['product_id'] ?? ''),
            'product_variant_id' => '',
            'client_line_key' => (string) ($item['key'] ?? ''),
            'parent_client_line_key' => (string) ($item['parent_key'] ?? ''),
            'line_kind' => (string) ($item['line_kind'] ?? 'standard'),
            'combo_id' => (string) ($item['combo_id'] ?? ''),
            'line_name' => (string) ($item['name'] ?? ''),
            'quantity' => (string) $item['quantity'],
            'unit_price' => (string) ($item['unit_price'] ?? 0),
            'discount_amount' => '0',
            'tax_amount' => '0',
            'modifiers' => json_encode($item['modifiers'] ?? []),
        ];
    }

    private function formPost(string $uri, array $data)
    {
        return $this->post($uri, $data, ['Accept' => 'application/json']);
    }

    // ── G1 ──────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_g1_a_multipart_sale_with_json_string_modifiers_is_accepted_and_priced_from_the_synced_book(): void
    {
        $lines = [
            $this->formLine(['key' => 'k1', 'product_id' => $this->tikka, 'quantity' => 2, 'unit_price' => 1,
                // the client's delta/name are NOT the authority — the synced option (Extra Cheese, +50) is.
                'modifiers' => [['modifier_group_id' => $this->extrasGroup, 'modifier_group_name' => 'Extras', 'modifier_id' => $this->cheeseOption, 'name' => 'Client Says Cheese', 'price_delta' => 1]]]),
            $this->formLine(['key' => 'k2', 'product_id' => $this->drink, 'quantity' => 1, 'modifiers' => []]),
        ];
        $res = $this->formPost('/edge/local/pos/sales', [
            'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip', 'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(), 'branch_id' => (string) $this->branchId,
            'discount_type' => 'none', 'discount_value' => '0',
            'lines' => $lines,
            'payments' => [['payment_method_id' => (string) $this->cashMethodId, 'amount' => '700', 'tendered_amount' => '700']],
        ]);
        $res->assertStatus(201)->assertJsonPath('grand_total', 700); // 2 × (250 + 50) + 100

        $saleId = (int) $res->json('sale_id');
        $tikkaLine = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->where('product_id', $this->tikka)->first();
        $this->assertEquals(300.0, (float) $tikkaLine->unit_price, 'option delta folded in from the synced book (Online normalizeLineModifiers + resolve)');
        $stored = json_decode((string) $tikkaLine->modifiers, true);
        $this->assertSame('Extra Cheese', $stored[0]['name'], 'the stored snapshot names the synced option, never the client text');
        $this->assertEquals(50.0, (float) $stored[0]['price_delta']);

        // The old Edge page's ARRAY form keeps working (postJson).
        $this->postJson('/edge/local/pos/sales', [
            'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip', 'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->tikka, 'quantity' => 1, 'modifiers' => [['modifier_group_id' => $this->extrasGroup, 'modifier_id' => $this->cheeseOption]]]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 300, 'tendered_amount' => 300]],
        ])->assertStatus(201)->assertJsonPath('grand_total', 300);

        // An undecodable modifiers string = no options (Online normalizeLineModifiers decodes to []), never a 422.
        $this->formPost('/edge/local/pos/sales', [
            'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip', 'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => (string) $this->drink, 'quantity' => '1', 'modifiers' => 'not-json']],
            'payments' => [['payment_method_id' => (string) $this->cashMethodId, 'amount' => '100', 'tendered_amount' => '100']],
        ])->assertStatus(201)->assertJsonPath('grand_total', 100);
    }

    public function test_g1_a_multipart_deal_posted_as_header_plus_component_rows_sells_once_from_the_synced_combo_book(): void
    {
        // index.blade.php posts a deal as ONE combo_header row + one `component` row per bundled item (each carrying combo_id).
        $lines = [
            $this->formLine(['key' => 'd1', 'line_kind' => 'combo_header', 'combo_id' => $this->comboId, 'product_id' => $this->tikka, 'name' => 'Family Deal', 'quantity' => 2, 'unit_price' => 400]),
            $this->formLine(['key' => 'd1:component:1', 'parent_key' => 'd1', 'line_kind' => 'component', 'combo_id' => $this->comboId, 'product_id' => $this->tikka, 'quantity' => 2, 'unit_price' => 0]),
            $this->formLine(['key' => 'd1:component:2', 'parent_key' => 'd1', 'line_kind' => 'component', 'combo_id' => $this->comboId, 'product_id' => $this->naan, 'quantity' => 4, 'unit_price' => 0]),
        ];
        $res = $this->formPost('/edge/local/pos/sales', [
            'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip', 'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => $lines,
            'payments' => [['payment_method_id' => (string) $this->cashMethodId, 'amount' => '800', 'tendered_amount' => '800']],
        ]);
        $res->assertStatus(201)->assertJsonPath('grand_total', 800); // 2 × 400 — the deal ONCE, never once per posted row

        $rows = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', (int) $res->json('sale_id'))->orderBy('id')->get();
        $this->assertSame(['combo_header', 'component', 'component'], $rows->pluck('line_kind')->all(), 'header + 2 components, written the way Online writes a deal');
        $this->assertEquals([2.0, 2.0, 4.0], $rows->pluck('quantity')->map(fn ($q) => (float) $q)->all());
    }

    public function test_g1_a_multipart_hold_with_json_string_modifiers_is_accepted(): void
    {
        $res = $this->formPost('/edge/local/pos/held-sales', [
            'order_type' => 'takeaway', 'branch_id' => (string) $this->branchId, 'discount_type' => 'none', 'discount_value' => '0',
            'lines' => [
                $this->formLine(['key' => 'h1', 'product_id' => $this->tikka, 'quantity' => 1,
                    'modifiers' => [['modifier_group_id' => $this->extrasGroup, 'modifier_id' => $this->cheeseOption, 'name' => 'x', 'price_delta' => 0]]]),
            ],
        ]);
        $res->assertStatus(201)->assertJsonPath('status', 'held')->assertJsonPath('grand_total', 300);
        $this->assertSame('h1', $res->json('lines.0.client_line_key'), 'G2 shape on a new hold as well');
    }

    // ── G2 ──────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_g2_hold_response_carries_client_line_key_so_round_two_continues_the_sent_line_and_kot_sends_only_the_delta(): void
    {
        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['restaurant_waiter_id' => $this->waiterId, 'guest_count' => 2])
            ->assertStatus(201)->json('session_id');

        // ROUND 1 — exactly the page's multipart post; the response must mirror Online's per-line keys.
        $round1 = $this->formPost('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => (string) $sessionId,
            'lines' => [$this->formLine(['key' => 'line-drink', 'product_id' => $this->drink, 'quantity' => 1])],
        ]);
        $round1->assertStatus(201);
        $saleId = (int) $round1->json('sale_id');
        $this->assertCount(1, $round1->json('lines'));
        foreach (['id', 'client_line_key', 'kot_sent', 'kot_sent_quantity'] as $k) {
            $this->assertArrayHasKey($k, $round1->json('lines.0'), "Online HeldSaleController::store savedLinePayload key {$k}");
        }
        $this->assertSame('line-drink', $round1->json('lines.0.client_line_key'));
        $this->assertFalse($round1->json('lines.0.kot_sent'));
        $drinkLineId = (int) $round1->json('lines.0.id');
        $this->assertSame($drinkLineId, (int) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->value('id'));

        // KOT round 1.
        $kot1 = $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();
        $this->assertSame(1, (int) $kot1->json('batch.sequence_no'));

        // ROUND 2 — the page now knows the saved id (item._dbLineId from the matched client_line_key) and posts
        // `sales_order_line_id` for the sent line + a NEW line. Before G2 this was 422 "Reducing [Cold Drink] below its kitchen-sent quantity".
        $round2 = $this->formPost('/edge/local/pos/held-sales', [
            'held_sale_id' => (string) $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => (string) $sessionId,
            'lines' => [
                $this->formLine(['_dbLineId' => $drinkLineId, 'key' => 'line-drink', 'product_id' => $this->drink, 'quantity' => 1]),
                $this->formLine(['key' => 'line-naan', 'product_id' => $this->naan, 'quantity' => 2]),
            ],
        ]);
        $round2->assertOk()->assertJsonPath('grand_total', 200); // 100 + 2 × 50
        $byKey = collect($round2->json('lines'))->keyBy('client_line_key');
        $this->assertSame(['line-drink', 'line-naan'], $byKey->keys()->sort()->values()->all());
        $this->assertTrue($byKey['line-drink']['kot_sent'], 'the continued line keeps its kitchen-sent state');
        $this->assertSame(1.0, (float) $byKey['line-drink']['kot_sent_quantity']);
        $this->assertFalse($byKey['line-naan']['kot_sent']);

        // KOT round 2 = ONLY the new item.
        $kot2 = $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();
        $this->assertSame(2, (int) $kot2->json('batch.sequence_no'));
        $this->assertSame('addition', $kot2->json('batch.event_type'));
        $sent = collect($kot2->json('batch.lines'))->mapWithKeys(fn ($l) => [$l['product_name'] => (float) $l['quantity']]);
        $this->assertSame(['Roghni Naan' => 2.0], $sent->all(), 'round 2 sends exactly the new item');
        $this->assertSame(0, DB::connection('tenant')->table('sales_order_line_cancellations')->count(), 'nothing was voided');

        // A revision rewrites the line rows (Online's delete+recreate churn) — which is WHY the page needs the response ids:
        // the sent line's id moved, and only `client_line_key` tells the page which new id is its row.
        $this->assertNotSame($drinkLineId, (int) $byKey['line-drink']['id'], 'the revision re-created the row under a new id (Online churn)');

        // ROUND 3 — a deal added on a continued check: the header row answers with the deal's key; its components carry none.
        $round3 = $this->formPost('/edge/local/pos/held-sales', [
            'held_sale_id' => (string) $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => (string) $sessionId,
            'lines' => [
                $this->formLine(['_dbLineId' => $byKey['line-drink']['id'], 'key' => 'line-drink', 'product_id' => $this->drink, 'quantity' => 1]),
                $this->formLine(['_dbLineId' => $byKey['line-naan']['id'], 'key' => 'line-naan', 'product_id' => $this->naan, 'quantity' => 2]),
                $this->formLine(['key' => 'deal-1', 'line_kind' => 'combo_header', 'combo_id' => $this->comboId, 'product_id' => $this->tikka, 'name' => 'Family Deal', 'quantity' => 1, 'unit_price' => 400]),
                $this->formLine(['key' => 'deal-1:component:1', 'parent_key' => 'deal-1', 'line_kind' => 'component', 'combo_id' => $this->comboId, 'product_id' => $this->tikka, 'quantity' => 1]),
                $this->formLine(['key' => 'deal-1:component:2', 'parent_key' => 'deal-1', 'line_kind' => 'component', 'combo_id' => $this->comboId, 'product_id' => $this->naan, 'quantity' => 2]),
            ],
        ]);
        $round3->assertOk()->assertJsonPath('grand_total', 600); // 200 + 400
        $keys = collect($round3->json('lines'))->pluck('client_line_key')->all();
        $this->assertEqualsCanonicalizing(['line-drink', 'line-naan', 'deal-1', null, null], $keys, 'header keyed, server-expanded components unkeyed');
    }

    // ── E3 ──────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_e3_customer_quick_add_and_add_address_render_disabled_with_the_capability_hint_on_edge(): void
    {
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();

        $quick = \App\Services\Edge\EdgePosRuntimeFactory::LABELS['customerCreate'];
        $addr = \App\Services\Edge\EdgePosRuntimeFactory::LABELS['customerAddressCreate'];
        $this->assertMatchesRegularExpression('/<button[^>]*id="qa-save"[^>]*\sdisabled[^>]*title="' . preg_quote(e($quick), '/') . '"/s', $html,
            'A5/A6: the quick-add control is disabled-with-hint (same mechanism as the other capability-off controls)');
        $this->assertMatchesRegularExpression('/<button[^>]*id="new-addr-save"[^>]*\sdisabled[^>]*title="' . preg_quote(e($addr), '/') . '"/s', $html);

        // The JS shows THE SAME hint on a refused save (POS.hintText reads the runtime label the title came from).
        $this->assertStringContainsString("err.textContent = POS.hintText('customerCreate')", $html);
        $this->assertStringContainsString('"capability.customerCreate":' . json_encode($quick, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), str_replace('\/', '/', $html));
        // No new element took space: the controls are the same two buttons (disabled state + title only).
        $this->assertSame(1, preg_match_all('/id="qa-save"/', $html));
        $this->assertSame(1, preg_match_all('/id="new-addr-save"/', $html));
    }

    // ── X2 ──────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_x2_terminal_offer_follows_online_assignment_then_pin_rule_on_the_shared_page(): void
    {
        // (a) change-terminal held, no assignments (the dev seed's DEVCASH1): EVERY counter is offered — as on Online (P7 is expected).
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        $this->assertStringContainsString('Counter One', $html);
        $this->assertStringContainsString('Counter Two', $html);

        // (b) terminal-ASSIGNED operator (terminal_user) with change-terminal: only the assigned counter (Online terminalsForPos).
        DB::connection('tenant')->table('terminal_user')->insert(['terminal_id' => $this->terminalId, 'user_id' => $this->userId, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="' . $this->terminalId . '"[^>]*>Counter One/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="' . $this->terminal2Id . '"[^>]*>Counter Two/', $html, 'an assigned operator never sees an unassigned counter (Online UserDataScope::terminalsForPos)');
        DB::connection('tenant')->table('terminal_user')->where('user_id', $this->userId)->delete();

        // (c) PINNED operator (no change-terminal, default terminal set): only his own — Online PosController::index :432.
        $this->revokeEdgePermission($this->userId, 'tenant.pos.change-terminal');
        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['default_terminal_id' => $this->terminal2Id]);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="' . $this->terminal2Id . '"[^>]*>Counter Two/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="' . $this->terminalId . '"[^>]*>Counter One/', $html);
    }
}
