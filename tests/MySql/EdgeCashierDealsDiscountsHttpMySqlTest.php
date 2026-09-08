<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Reports\SalesReportDocumentService;
use App\Services\Reports\SalesReportEngine;
use App\Support\TenantClock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * PHASE A — the remaining normal Online-POS cashier workflows, over REAL HTTP on a branch_server-booted app,
 * through the endpoints the cashier page calls:
 *
 *  DEALS      — the client names the deal + quantity; the server expands the synced combo book the way Cloud writes
 *               it (header row = bundle price + deal name, component rows at 0 with the parent link); components
 *               consume operational stock, the header never; KOT carries the deal identity; the receipt shows the
 *               deal name only; the canonical report engine counts the deal ONCE under its own identity.
 *  DISCOUNTS  — the shared totals service; a manual discount is refused without a consumed manager approval when the
 *               branch demands one, and accepted when the branch auto-approves.
 *  PROMOS     — a synced promotion code applies through the shared PromotionService; the envelope carries it.
 *  DELIVERY   — channel required; own delivery needs a customer from the synced book; an aggregator owns its customer;
 *               the charge follows the branch lock; address + rider ride the sale and the envelope.
 *  SPLIT BILL — quantities move onto a new held check on the same table (same session/customer/dates), kitchen-sent
 *               state carries (no re-KOT), each check pays on its own, stock exactly once, the table frees last.
 */
class EdgeCashierDealsDiscountsHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $managerId;
    private string $managerCode;
    private int $tableId;
    private int $karahi;
    private int $naan;
    private int $comboId;
    private int $cashMethodId;
    private int $baselineId;
    private int $ownChannel;
    private int $aggregatorChannel;
    private int $riderId;
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
            'manager_approvals', 'manager_pins', 'model_has_permissions', 'permissions',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'promotion_targets', 'promotions', 'customer_addresses', 'customers', 'delivery_riders', 'delivery_channels',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'combo_components', 'combos', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'manual_discount_approval_mode' => 'manager_required', 'delivery_charge_locked' => 1, 'default_delivery_charge' => 150]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'DEAL' . Str::random(4)]);
        $this->managerId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'MGR' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'available']);
        $categoryId = $this->makeCategory(['name' => 'Karahi']);
        $this->karahi = $this->makeProduct($categoryId, ['name' => 'Chicken Karahi', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->naan = $this->makeProduct($categoryId, ['name' => 'Roghni Naan', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $this->comboId = (int) DB::connection('tenant')->table('combos')->insertGetId(['branch_id' => $this->branchId, 'code' => 'FAM', 'name' => 'Family Deal', 'price' => 220, 'sort_order' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('combo_components')->insert([
            ['combo_id' => $this->comboId, 'product_id' => $this->karahi, 'quantity' => 1, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['combo_id' => $this->comboId, 'product_id' => $this->naan, 'quantity' => 2, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->ownChannel = (int) DB::connection('tenant')->table('delivery_channels')->insertGetId(['name' => 'Own Riders', 'type' => 'own', 'is_active' => 1, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->aggregatorChannel = (int) DB::connection('tenant')->table('delivery_channels')->insertGetId(['name' => 'Foodpanda', 'type' => 'aggregator', 'is_active' => 1, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->riderId = (int) DB::connection('tenant')->table('delivery_riders')->insertGetId(['branch_id' => $this->branchId, 'name' => 'Rider Bilal', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->customerId = (int) DB::connection('tenant')->table('customers')->insertGetId(['customer_uuid' => (string) Str::ulid(), 'code' => 'C-' . Str::random(6), 'name' => 'Mr Zafar', 'phone' => '0300-7777777', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('promotions')->insert(['branch_id' => null, 'name' => 'Save Ten', 'code' => 'SAVE10', 'promotion_type' => 'order', 'discount_type' => 'percent', 'discount_value' => 10, 'min_order_amount' => 0, 'requires_code' => 1, 'used_count' => 0, 'status' => 'active', 'priority' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $permId = (int) DB::connection('tenant')->table('permissions')->insertGetId(['name' => 'tenant.pos.void-kot-item', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('model_has_permissions')->insert(['permission_id' => $permId, 'model_type' => User::class, 'model_id' => $this->managerId]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->baselineId = (int) $this->acceptTestBaseline([
            ['product_id' => $this->karahi, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->naan, 'product_variant_id' => null, 'quantity' => 50],
        ])->id;
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->seedEdgeCredential($this->managerId, $this->branchId, 1, 'MgrPass1');
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

    private function cash(float $amount): array
    {
        return [['payment_method_id' => $this->cashMethodId, 'amount' => $amount, 'tendered_amount' => $amount]];
    }

    public function test_deal_sells_as_online_writes_it_stock_kot_receipt_and_report_identity(): void
    {
        // The page ships the deal in its payload.
        $this->assertStringContainsString('"name":"Family Deal"', $this->get('/edge/local/pos')->assertOk()->getContent());

        // TAKEAWAY: 2 × Family Deal (220) — the client names the deal + quantity only.
        $sale = $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['combo_id' => $this->comboId, 'quantity' => 2]],
            'payments' => $this->cash(440),
        ])->assertStatus(201)->assertJsonPath('grand_total', 440);
        $saleId = (int) $sale->json('sale_id');
        $lines = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->orderBy('id')->get();
        $this->assertCount(3, $lines);
        $header = $lines[0];
        $this->assertSame('combo_header', $header->line_kind);
        $this->assertSame('Family Deal', $header->product_name);
        $this->assertSame(220.0, (float) $header->unit_price);
        $this->assertSame($this->comboId, (int) $header->combo_id);
        foreach ([$lines[1], $lines[2]] as $c) {
            $this->assertSame('component', $c->line_kind);
            $this->assertSame(0.0, (float) $c->unit_price);
            $this->assertSame((int) $header->id, (int) $c->parent_sales_order_line_id, 'components hang off the deal header');
            $this->assertSame($this->comboId, (int) $c->combo_id);
        }
        $this->assertSame(2.0, (float) $lines[1]->quantity, 'Karahi ×1 per deal × 2 deals');
        $this->assertSame(4.0, (float) $lines[2]->quantity, 'Naan ×2 per deal × 2 deals');

        // STOCK: components consumed, the header never (Karahi is also the header product — consumed ONCE).
        $this->assertSame(48.0, $this->edgeOnHand($this->baselineId, $this->karahi));
        $this->assertSame(46.0, $this->edgeOnHand($this->baselineId, $this->naan));

        // RECEIPT shows the deal by name only (COMBO-RECEIPT-NAME-ONLY).
        $job = $this->postJson("/edge/local/pos/sales/{$saleId}/receipt")->assertStatus(201);
        $receipt = $this->get($job->json('preview_url'))->assertOk()->getContent();
        $this->assertStringContainsString('Family Deal', $receipt);
        $this->assertStringNotContainsString('Roghni Naan', $receipt, 'components are not itemised on the receipt');

        // REPORT identity through the canonical engine: the deal counts ONCE under its own name; components are not sales.
        $date = app(TenantClock::class)->currentBusinessDate(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId));
        $data = app(SalesReportDocumentService::class)->data(app(SalesReportEngine::class)->normalizeFilters([
            'date_from' => $date, 'date_to' => $date, 'branch_ids' => [$this->branchId],
        ]), ['deals', 'items', 'overview']);
        $this->assertTrue(collect($data['deals'])->contains(fn ($r) => str_contains(json_encode($r), 'Family Deal')), 'the deal reports under its own identity');
        $this->assertFalse(collect($data['items'])->contains(fn ($r) => str_contains(json_encode($r), 'Roghni Naan')), 'deal components are not counted as separate sales');
        $this->assertSame(440.0, round((float) data_get($data, 'overview.net_sales'), 2));

        // The Quick Report the cashier sees is the same engine.
        $this->grant('tenant.pos.quick-report-send');
        $this->assertStringContainsString('Family Deal', $this->get('/edge/local/pos/quick-report/view?date=' . $date . '&sections[]=deals')->assertOk()->getContent());
    }

    public function test_dine_in_deal_kot_carries_the_deal_identity_and_add_round_scales_components(): void
    {
        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 4])->json('session_id');
        $hold = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['combo_id' => $this->comboId, 'quantity' => 1]],
        ])->assertStatus(201)->assertJsonPath('grand_total', 220);
        $saleId = (int) $hold->json('sale_id');
        $headerId = (int) collect($hold->json('lines'))->first(fn ($l) => (float) $l['unit_price'] === 220.0)['id'];

        // KOT round 1: the kitchen gets the COMPONENTS (Karahi 1, Naan 2), never the header.
        $kot1 = $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();
        $sent = collect($kot1->json('batch.lines'))->mapWithKeys(fn ($l) => [$l['product_name'] => (float) $l['quantity']])->sortKeys()->all();
        $this->assertSame(['Chicken Karahi' => 1.0, 'Roghni Naan' => 2.0], $sent);
        // The KOT document names the deal each component belongs to (COMBO-KOT-DEAL-NAME-1).
        $doc = $this->get('/edge/local/pos/print-jobs/' . $kot1->json('jobs.0.id') . '/document')->assertOk()->getContent();
        $this->assertTrue(stripos($doc, 'Family Deal') !== false, 'the ticket names the deal for its components');

        // ADD ROUND: the deal goes 1 → 2 (carried by its header id) — components scale, sent state carries.
        $this->postJson('/edge/local/pos/held-sales', [
            'held_sale_id' => $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['sales_order_line_id' => $headerId, 'combo_id' => $this->comboId, 'quantity' => 2]],
        ])->assertOk()->assertJsonPath('grand_total', 440);
        $kot2 = $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();
        $delta = collect($kot2->json('batch.lines'))->mapWithKeys(fn ($l) => [$l['product_name'] => (float) $l['quantity']])->sortKeys()->all();
        $this->assertSame(['Chicken Karahi' => 1.0, 'Roghni Naan' => 2.0], $delta, 'round 2 sends only the second deal\'s components');

        // Settle: stock for the components of BOTH deals, once.
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/settle", ['client_uuid' => (string) Str::uuid(), 'payments' => $this->cash(440)])->assertOk()->assertJsonPath('status', 'paid');
        $this->assertSame(48.0, $this->edgeOnHand($this->baselineId, $this->karahi));
        $this->assertSame(46.0, $this->edgeOnHand($this->baselineId, $this->naan));
    }

    public function test_manual_discount_follows_the_branch_approval_mode_and_consumes_the_manager_approval(): void
    {
        $clientUuid = (string) Str::uuid();
        $payload = [
            'order_type' => 'takeaway', 'client_uuid' => $clientUuid,
            'discount_type' => 'fixed', 'discount_value' => 50,
            'lines' => [['product_id' => $this->karahi, 'quantity' => 2]],
            'payments' => $this->cash(150),
        ];
        // manager_required: refused without an approval.
        $this->postJson('/edge/local/pos/sales', $payload)->assertStatus(422);

        // The manager approves with THEIR OWN Edge credential — same payload binding as Cloud consume().
        $approvalId = $this->postJson('/edge/local/pos/manager-approvals/verify', [
            'manager_employee_code' => $this->managerCode, 'manager_credential' => 'MgrPass1',
            'action_type' => 'manual_discount',
            'payload' => ['sales_order_id' => 0, 'branch_id' => $this->branchId, 'client_uuid' => $clientUuid, 'discount_type' => 'fixed', 'discount_value' => 50, 'discount_amount' => 50],
        ])->assertStatus(201)->json('approval_id');
        $sale = $this->postJson('/edge/local/pos/sales', $payload + ['manager_approval_id' => $approvalId])->assertStatus(201);
        $this->assertSame(150.0, (float) $sale->json('grand_total'), '2 × 100 − 50');
        $row = DB::connection('tenant')->table('sales_orders')->where('id', $sale->json('sale_id'))->first();
        $this->assertSame('fixed', $row->discount_type);
        $this->assertSame(50.0, (float) $row->discount_amount);
        $this->assertNotNull(DB::connection('tenant')->table('manager_approvals')->where('id', $approvalId)->value('consumed_at'), 'single-use approval consumed');

        // auto_approve branch: the discount needs no manager.
        DB::connection('tenant')->table('branches')->where('id', $this->branchId)->update(['manual_discount_approval_mode' => 'auto_approve']);
        $this->postJson('/edge/local/pos/sales', array_merge($payload, ['client_uuid' => (string) Str::uuid()]))->assertStatus(201)->assertJsonPath('grand_total', 150);

        // Out-of-range request is refused (same vocabulary as Cloud).
        $this->postJson('/edge/local/pos/sales', array_merge($payload, ['client_uuid' => (string) Str::uuid(), 'discount_type' => 'percent', 'discount_value' => 150]))->assertStatus(422);
    }

    public function test_synced_promotion_code_applies_through_the_shared_promotion_service_and_travels_in_the_envelope(): void
    {
        $sale = $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(), 'promo_code' => 'SAVE10',
            'lines' => [['product_id' => $this->karahi, 'quantity' => 2]],
            'payments' => $this->cash(180),
        ])->assertStatus(201)->assertJsonPath('grand_total', 180);
        $row = DB::connection('tenant')->table('sales_orders')->where('id', $sale->json('sale_id'))->first();
        $this->assertSame('SAVE10', $row->promo_code);
        $this->assertNotNull($row->promotion_id);
        $this->assertSame(20.0, (float) $row->discount_amount);
        // The outbox envelope carries the commercial attribution to the Cloud (frozen money + how it was reached).
        $envelope = json_decode((string) DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $row->sale_uuid)->value('envelope'), true);
        $this->assertSame('SAVE10', data_get($envelope, 'totals.promo_code'));
        $this->assertSame(20.0, (float) data_get($envelope, 'totals.discount_amount'));
    }

    public function test_delivery_follows_online_channel_customer_and_charge_rules(): void
    {
        $base = ['order_type' => 'delivery', 'lines' => [['product_id' => $this->karahi, 'quantity' => 1]]];

        // No channel → refused. Own delivery without a customer → refused.
        $this->postJson('/edge/local/pos/sales', $base + ['client_uuid' => (string) Str::uuid(), 'payments' => $this->cash(250)])->assertStatus(422);
        $this->postJson('/edge/local/pos/sales', $base + ['client_uuid' => (string) Str::uuid(), 'delivery_channel_id' => $this->ownChannel, 'payments' => $this->cash(250)])->assertStatus(422);

        // Aggregator owns its customer: accepted without one; the LOCKED branch charge (150) is applied, never the submitted 5.
        $agg = $this->postJson('/edge/local/pos/sales', $base + [
            'client_uuid' => (string) Str::uuid(), 'delivery_channel_id' => $this->aggregatorChannel, 'delivery_charge_amount' => 5,
            'payments' => $this->cash(250),
        ])->assertStatus(201)->assertJsonPath('grand_total', 250);
        $this->assertSame(150.0, (float) DB::connection('tenant')->table('sales_orders')->where('id', $agg->json('sale_id'))->value('delivery_charge_amount'));

        // Own delivery with a synced customer, address and rider — all preserved on the local sale and in the envelope.
        $own = $this->postJson('/edge/local/pos/sales', $base + [
            'client_uuid' => (string) Str::uuid(), 'delivery_channel_id' => $this->ownChannel, 'delivery_rider_id' => $this->riderId,
            'customer_id' => $this->customerId, 'delivery_address' => 'House 12, Street 4, DHA',
            'payments' => $this->cash(250),
        ])->assertStatus(201)->assertJsonPath('grand_total', 250);
        $row = DB::connection('tenant')->table('sales_orders')->where('id', $own->json('sale_id'))->first();
        $this->assertSame($this->customerId, (int) $row->customer_id);
        $this->assertSame('House 12, Street 4, DHA', $row->delivery_address);
        $this->assertSame($this->riderId, (int) $row->delivery_rider_id);
        $this->assertSame($this->ownChannel, (int) $row->delivery_channel_id);
        $envelope = json_decode((string) DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $row->sale_uuid)->value('envelope'), true);
        $this->assertSame('House 12, Street 4, DHA', data_get($envelope, 'delivery.delivery_address'));
        $this->assertSame($this->riderId, (int) data_get($envelope, 'delivery.delivery_rider_id'));
        $this->assertSame('customer', data_get($envelope, 'customer.kind'));
        $this->assertSame(150.0, (float) data_get($envelope, 'totals.delivery_charge_amount'));

        // An unknown customer id is refused — the till never invents a customer.
        $this->postJson('/edge/local/pos/sales', $base + ['client_uuid' => (string) Str::uuid(), 'delivery_channel_id' => $this->ownChannel, 'customer_id' => 999999, 'payments' => $this->cash(250)])->assertStatus(422);
    }

    public function test_split_bill_moves_quantities_onto_a_new_check_that_pays_on_its_own(): void
    {
        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->json('session_id');
        $hold = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->karahi, 'quantity' => 3], ['product_id' => $this->naan, 'quantity' => 2]],
        ])->assertStatus(201)->assertJsonPath('grand_total', 400);
        $parentId = (int) $hold->json('sale_id');
        $karahiLine = (int) collect($hold->json('lines'))->first(fn ($l) => (int) $l['product_id'] === $this->karahi)['id'];
        $naanLine = (int) collect($hold->json('lines'))->first(fn ($l) => (int) $l['product_id'] === $this->naan)['id'];
        $this->postJson("/edge/local/pos/held-sales/{$parentId}/kot")->assertOk(); // everything sent to the kitchen

        // SPLIT: 1 Karahi + both Naan onto a new check.
        $split = $this->postJson("/edge/local/pos/held-sales/{$parentId}/split", [
            'lines' => [['sales_order_line_id' => $karahiLine, 'quantity' => 1], ['sales_order_line_id' => $naanLine, 'quantity' => 2]],
        ])->assertStatus(201);
        $childId = (int) $split->json('child.id');
        $this->assertSame(200.0, (float) $split->json('child.grand_total'), '1 × 100 + 2 × 50');
        $this->assertSame(200.0, (float) $split->json('parent.grand_total'), '2 × 100 remain');
        $this->assertSame($sessionId, (int) $split->json('child.restaurant_table_session_id'), 'same table session');
        $childLines = collect($split->json('child.lines'));
        $this->assertSame(1.0, (float) $childLines->first(fn ($l) => (int) $l['product_id'] === $this->karahi)['kot_sent_quantity'], 'kitchen-sent state moved with the food');
        $this->assertSame(2.0, (float) $childLines->first(fn ($l) => (int) $l['product_id'] === $this->naan)['kot_sent_quantity']);
        $parentSale = DB::connection('tenant')->table('sales_orders')->where('id', $parentId)->first();
        $childSale = DB::connection('tenant')->table('sales_orders')->where('id', $childId)->first();
        $this->assertSame((string) $parentSale->sale_date, (string) $childSale->sale_date, 'SALE-DATE-TRUTH: the child inherits when the food was ordered');
        $this->assertSame((string) $parentSale->business_date, (string) $childSale->business_date);

        // Nothing new for the kitchen on either check (no re-KOT of split food).
        $this->postJson("/edge/local/pos/held-sales/{$childId}/kot")->assertOk()->assertJsonPath('batch', null);
        $this->postJson("/edge/local/pos/held-sales/{$parentId}/kot")->assertOk()->assertJsonPath('batch', null);

        // Each pays on its own; the table frees only when the LAST check settles; stock exactly once overall.
        $this->postJson("/edge/local/pos/held-sales/{$childId}/settle", ['client_uuid' => (string) Str::uuid(), 'payments' => $this->cash(200)])->assertOk()->assertJsonPath('status', 'paid');
        $this->assertSame('open', DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $sessionId)->value('status'), 'the parent check still holds the table');
        $this->postJson("/edge/local/pos/held-sales/{$parentId}/settle", ['client_uuid' => (string) Str::uuid(), 'payments' => $this->cash(200)])->assertOk()->assertJsonPath('status', 'paid');
        $this->assertSame('closed', DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $sessionId)->value('status'));
        $this->assertSame('available', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableId)->value('status'));
        $this->assertSame(47.0, $this->edgeOnHand($this->baselineId, $this->karahi), '3 Karahi consumed once across both checks');
        $this->assertSame(48.0, $this->edgeOnHand($this->baselineId, $this->naan));
        $this->assertSame(2, DB::connection('tenant')->table('edge_sync_outbox')->count(), 'one outbox row per settled check');
    }

    private function grant(string $permission): void
    {
        $this->grantEdgePermission($this->userId, $permission);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
    }
}
