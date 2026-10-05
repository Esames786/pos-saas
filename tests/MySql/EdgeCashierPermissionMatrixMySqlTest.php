<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Pos\PosPermissionCatalog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W-E (owner decision A6) — the `Cashier (Counter)` template (PosPermissionCatalog::cashier()) is COMPLETE for the
 * real Edge cashier workflows, and every catalogue permission is really enforced.
 *
 *  1. A cashier holding EXACTLY the template completes every workflow over the REAL branch_server routes: POS page,
 *     terminal switch, shift open/close, cash sale, receipt + KOT print queue, hold / draft / recall, sent-line KOT void
 *     with a manager approval, table open / bill preview / request bill / session detail / move / merge / split /
 *     reattach / close, cancel order, customer search, returns search + cash return + lists, Quick Report — 2xx (or a
 *     documented business 422), never 403.
 *  2. Table-driven over the template: the cashier LACKING one permission at a time gets 403 from the matching Edge
 *     endpoint (with `permission` = that name where the endpoint uses denyUnlessCan), and a non-403 again once it is
 *     back. tenant.pos.void-kot-item is enforced by the SHARED KotCancellationService on the requester — a 422 with
 *     errors.permission (the Online contract), not a 403.
 *  3. The provisioner creates the template for NEW tenants only and never overwrites an existing role; the audit
 *     command reports gaps read-only and grants nothing.
 */
class EdgeCashierPermissionMatrixMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    /** Catalogue cashier permissions with NO Edge endpoint that checks them today (documented, asserted exact). */
    private const NO_EDGE_ENDPOINT = [
        'tenant.pos.customers.quick-store' => 'W-D offline add-customer (EdgeLocalCustomerController) not yet on the appliance',
        'tenant.sales-orders.split-bill' => 'Online split-bill PAGE gate — the shared view table-board @can only (rendered on Edge from W-B)',
        'tenant.sales-returns.create' => 'Online returns create PAGE gate — the shared view Return button @can only (rendered on Edge from W-B)',
        'tenant.shifts.create' => 'Online open-shift PAGE gate — W-B shared shift pages (openPage gates tenant.shifts.store today; tenant/shifts/index @can)',
        'tenant.shifts.close-form' => 'Online close-shift PAGE gate — W-B shared shift pages (closePage gates tenant.shifts.close today; tenant/shifts/show @can)',
    ];

    private const TEMPLATE_TEST = 'test_cashier_role_template_is_for_new_tenants_only_and_the_audit_is_read_only';

    private int $branchId;
    private int $terminalA;
    private int $terminalB;
    private int $userId;
    private int $managerId;
    private string $managerCode;
    /** @var array<string, int> */
    private array $tables = [];
    private int $productP;
    private int $productQ;
    private int $cashMethodId;
    private int $voidReasonId;
    private int $customerId;
    private bool $branchServer = true;

    protected function setUp(): void
    {
        $this->branchServer = $this->name() !== self::TEMPLATE_TEST;
        if ($this->branchServer) {
            putenv('APP_ROLE=branch_server');
            $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
            $key = 'base64:' . base64_encode(random_bytes(32));
            putenv("EDGE_LOCAL_APP_KEY={$key}");
            $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        }
        parent::setUp();
        DB::setDefaultConnection('tenant');

        if (! $this->branchServer) {
            $this->cleanTenant(['role_has_permissions', 'model_has_roles', 'model_has_permissions', 'roles', 'permissions', 'users']);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

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
            'edge_returnable_sale_lines', 'edge_returnable_sales',
            'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations', 'edge_sync_outbox',
            'sales_return_lines', 'sales_returns',
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs',
            'manager_approvals', 'void_reasons', 'role_has_permissions', 'model_has_roles', 'roles', 'model_has_permissions', 'permissions',
            'customer_addresses', 'customers', 'terminal_user', 'branch_user',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi',
            'held_kot_cancellation_approval_mode' => 'manager_required', 'held_kot_line_cancellation_approval_mode' => 'manager_required']);
        $this->terminalA = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->terminalB = $this->makeTerminal($this->branchId, ['name' => 'Counter B']);
        // The cashier has a DEFAULT terminal, so tenant.pos.change-terminal is what lets him work on Counter B.
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'default_terminal_id' => $this->terminalA,
            'employee_code' => 'PM' . Str::random(4), 'name' => 'Template Cashier']);
        $this->managerId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'PMM' . Str::random(4), 'name' => 'Approver']);
        foreach (['T1', 'T2', 'T3', 'T4', 'T5', 'T6'] as $no) {
            $this->tables[$no] = $this->makeTable($this->branchId, ['table_no' => $no, 'status' => 'available', 'capacity' => 4]);
        }
        $cat = $this->makeCategory(['name' => 'Karahi']);
        $this->productP = $this->makeProduct($cat, ['name' => 'Karahi', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->productQ = $this->makeProduct($cat, ['name' => 'Naan', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->voidReasonId = (int) DB::connection('tenant')->table('void_reasons')->insertGetId(['name' => 'Guest changed mind', 'reason_type' => 'cancel', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->customerId = (int) DB::connection('tenant')->table('customers')->insertGetId(['name' => 'Kashif Rana', 'phone' => '0300-7654321', 'status' => 'active', 'customer_uuid' => (string) Str::ulid(), 'created_at' => now(), 'updated_at' => now()]);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([
            ['product_id' => $this->productP, 'product_variant_id' => null, 'quantity' => 200],
            ['product_id' => $this->productQ, 'product_variant_id' => null, 'quantity' => 200],
        ]);
        // Both hold EXACTLY the template (seedEdgeCredential grants onlinePosParityPermissions() = cashier()). Phase 3:
        // the approver additionally carries the bootstrap eligibility flag (may_approve_pos) — a permission is never
        // an approver marker; the template still supplies the per-action permission the approver must hold.
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->seedEdgeCredential($this->managerId, $this->branchId, 1, 'MgrPass1');
        $this->markPosApprover($this->managerId);
        $this->managerCode = (string) User::on('tenant')->find($this->managerId)->employee_code;
        $this->login();
    }

    protected function tearDown(): void
    {
        if ($this->branchServer) {
            putenv('APP_ROLE');
            unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
            putenv('EDGE_LOCAL_APP_KEY');
            unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        }
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function login(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    /** @return list<string> the cashier's effective permission names (direct + role), sorted. */
    private function effectivePermissions(int $userId): array
    {
        $names = User::on('tenant')->find($userId)->getAllPermissions()->pluck('name')->all();
        sort($names);

        return $names;
    }

    private function approve(string $action, array $payload): int
    {
        $r = $this->postJson('/edge/local/pos/manager-approvals/verify', [
            'manager_employee_code' => $this->managerCode, 'manager_credential' => 'MgrPass1', 'action_type' => $action, 'payload' => $payload,
        ]);
        // W-B aligns the Edge twin with Online (`ok` + 200); earlier builds answered 201 — either is a minted approval.
        $this->assertContains($r->status(), [200, 201], 'manager approval: ' . substr((string) $r->getContent(), 0, 300));

        return (int) $r->json('approval_id');
    }

    private function cashSale(float $qty = 3): array
    {
        return $this->postJson('/edge/local/pos/sales', ['kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip', 'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productP, 'quantity' => $qty]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100 * $qty]]])->assertStatus(201)->json();
    }

    /** Open a table, hold P×qty on it, send the KOT. @return array{0:int,1:int,2:int} [sessionId, saleId, lineId] */
    private function openHoldAndKot(string $table, float $qty = 2): array
    {
        $sessionId = (int) $this->postJson("/edge/local/pos/restaurant/tables/{$this->tables[$table]}/open", ['guest_count' => 2])->assertStatus(201)->json('session_id');
        $hold = $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->productP, 'quantity' => $qty]]])->assertStatus(201);
        $saleId = (int) $hold->json('sale_id');
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();

        return [$sessionId, $saleId, (int) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->value('id')];
    }

    /** A takeaway held check with P×qty whose KOT is sent. @return array{0:int,1:int} [saleId, lineId] */
    private function takeawayHeldWithKot(float $qty): array
    {
        $saleId = (int) $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'takeaway',
            'lines' => [['product_id' => $this->productP, 'quantity' => $qty]]])->assertStatus(201)->json('sale_id');
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();

        return [$saleId, (int) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->value('id')];
    }

    /** Void ONE unit of a sent line through the page's revise payload, with a fresh void_kot_item approval. */
    private function voidOne(int $saleId, int $lineId): TestResponse
    {
        $current = (float) DB::connection('tenant')->table('sales_order_lines')->where('id', $lineId)->value('quantity');
        $approval = $this->approve('void_kot_item', ['sales_order_id' => $saleId, 'sales_order_line_id' => $lineId, 'quantity' => 1]);

        return $this->postJson('/edge/local/pos/held-sales', ['held_sale_id' => $saleId, 'order_type' => 'takeaway',
            'lines' => [['sales_order_line_id' => $lineId, 'product_id' => $this->productP, 'quantity' => $current - 1]],
            'void_items' => [['old_line_id' => $lineId, 'quantity' => 1, 'reason_id' => $this->voidReasonId, 'manager_approval_id' => $approval]]]);
    }

    private function assertWorkflowOk(TestResponse $r, string $what, array $allowed = [200, 201]): TestResponse
    {
        $this->assertContains($r->status(), $allowed, "{$what}: expected " . implode('/', $allowed) . ", got {$r->status()} — " . substr((string) $r->getContent(), 0, 400));

        return $r;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_cashier_holding_exactly_the_template_completes_every_edge_workflow(): void
    {
        $template = PosPermissionCatalog::cashier();
        sort($template);
        $this->assertSame($template, $this->effectivePermissions($this->userId), 'the cashier holds EXACTLY the catalogue cashier set');

        // POS page + terminal authority (default terminal A; the template may switch).
        $this->assertWorkflowOk($this->get('/edge/local/pos'), 'POS page');
        $this->assertWorkflowOk($this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalB]), 'switch to Counter B (change-terminal)');
        $this->assertWorkflowOk($this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalA]), 'back to Counter A');

        // Shift open.
        $this->assertWorkflowOk($this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 500]), 'shift open');
        $shiftId = (int) DB::connection('tenant')->table('shifts')->where('status', 'open')->value('id');
        $this->assertGreaterThan(0, $shiftId);
        $this->assertWorkflowOk($this->getJson('/edge/local/pos/shift'), 'shift status');

        // Cash sale + printing (receipt queue, KOT print queue).
        $sale = $this->cashSale(3);
        $saleId = (int) $sale['sale_id'];
        $this->assertWorkflowOk($this->postJson("/edge/local/pos/sales/{$saleId}/receipt", []), 'receipt print queue');
        $this->assertWorkflowOk($this->getJson('/edge/local/pos/print-jobs'), 'recent prints');

        // Hold / draft / recall.
        $draft = $this->assertWorkflowOk($this->postJson('/edge/local/pos/held-sales', ['order_type' => 'takeaway', 'save_as_draft' => true,
            'lines' => [['product_id' => $this->productQ, 'quantity' => 1]]]), 'save draft');
        $draftId = (int) $draft->json('sale_id');
        $this->assertWorkflowOk($this->getJson('/edge/local/pos/held-sales'), 'recall list');
        $this->assertWorkflowOk($this->getJson("/edge/local/pos/held-sales/{$draftId}"), 'recall one');
        $this->assertWorkflowOk($this->getJson('/edge/local/pos/recent-sales'), 'recent orders');

        // Cancel an order (no KOT sent → no approval).
        $this->assertWorkflowOk($this->postJson("/edge/local/pos/held-sales/{$draftId}/cancel", ['reason_id' => $this->voidReasonId]), 'cancel order');

        // Void a SENT line with a manager approval (requester needs void-kot-item; approver holds only the template).
        [$voidSale, $voidLine] = $this->takeawayHeldWithKot(3);
        $voided = $this->assertWorkflowOk($this->voidOne($voidSale, $voidLine), 'void a sent KOT line');
        $this->assertSame(200.0, (float) $voided->json('grand_total'), 'the check is now 2 × 100');
        $this->assertSame(1, DB::connection('tenant')->table('sales_order_line_cancellations')->count(), 'the void is recorded');

        // Tables: open → hold → KOT (business event + print queue) → preview / request bill / detail → move → merge → split.
        [$s1, $h1, $l1] = $this->openHoldAndKot('T1', 2);
        $this->assertWorkflowOk($this->postJson("/edge/local/pos/sales/{$h1}/kot", []), 'KOT print queue');
        $this->assertWorkflowOk($this->getJson("/edge/local/pos/restaurant/table-sessions/{$s1}/bill-preview"), 'bill preview');
        $this->assertWorkflowOk($this->postJson("/edge/local/pos/restaurant/table-sessions/{$s1}/bill-requested"), 'request bill');
        $this->assertWorkflowOk($this->getJson("/edge/local/pos/restaurant/table-sessions/{$s1}"), 'session detail');
        $this->assertWorkflowOk($this->postJson("/edge/local/pos/restaurant/table-sessions/{$s1}/move", ['target_table_id' => $this->tables['T2']]), 'move table');
        [$s3, $h3] = $this->openHoldAndKot('T3', 1);
        $this->assertWorkflowOk($this->postJson("/edge/local/pos/restaurant/table-sessions/{$s3}/merge", ['target_session_id' => $s1]), 'merge tables');
        $this->assertSame($s1, (int) DB::connection('tenant')->table('sales_orders')->where('id', $h3)->value('restaurant_table_session_id'));
        $split = $this->assertWorkflowOk($this->postJson("/edge/local/pos/held-sales/{$h1}/split", ['lines' => [['sales_order_line_id' => $l1, 'quantity' => 1]]]), 'split bill');
        $child = (int) $split->json('child.id');
        foreach ([$child => 100, $h1 => 100, $h3 => 100] as $id => $amount) {
            $this->assertWorkflowOk($this->postJson("/edge/local/pos/held-sales/{$id}/settle", ['client_uuid' => (string) Str::uuid(), 'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip',
                'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => $amount]]]), "settle held #{$id}");
        }
        $this->assertSame('available', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tables['T2'])->value('status'), 'the last check freed the table');

        // Close an (empty) table session.
        $s4 = (int) $this->postJson("/edge/local/pos/restaurant/tables/{$this->tables['T4']}/open", ['guest_count' => 1])->assertStatus(201)->json('session_id');
        $this->assertWorkflowOk($this->postJson("/edge/local/pos/restaurant/table-sessions/{$s4}/close", ['status' => 'cancelled']), 'close table session');

        // Reattach a held bill whose session died (HELD-SALE-DEAD-SESSION-1).
        [$s5, $h5] = $this->openHoldAndKot('T5', 1);
        DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $s5)->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $this->userId]);
        DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tables['T5'])->update(['status' => 'available']);
        $this->assertWorkflowOk($this->postJson("/edge/local/pos/held-sales/{$h5}/reattach-table", ['restaurant_table_id' => $this->tables['T5']]), 'reattach table');
        $this->assertWorkflowOk($this->postJson("/edge/local/pos/held-sales/{$h5}/settle", ['client_uuid' => (string) Str::uuid(), 'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip',
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]]]), 'settle reattached');
        // settle the voided takeaway check too, so the shift can close clean.
        $this->assertWorkflowOk($this->postJson("/edge/local/pos/held-sales/{$voidSale}/settle", ['client_uuid' => (string) Str::uuid(), 'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip',
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 200]]]), 'settle voided check');

        // Customer search (synced customer book).
        $found = $this->assertWorkflowOk($this->getJson('/edge/local/pos/customers?q=Kashif'), 'customer search');
        $this->assertSame($this->customerId, (int) $found->json('customers.0.id'));

        // Returns: search → returnable sale → cash return → list / detail screens.
        $this->assertWorkflowOk($this->getJson('/edge/local/pos/returns/search?q='), 'returns search');
        $view = $this->assertWorkflowOk($this->getJson("/edge/local/pos/returns/sales/{$saleId}"), 'returnable sale')->json();
        $return = $this->assertWorkflowOk($this->postJson('/edge/local/pos/returns', ['sales_order_id' => $saleId, 'refund_method' => 'cash', 'refund_amount' => 100,
            'lines' => [['sales_order_line_id' => (int) $view['lines'][0]['sales_order_line_id'], 'quantity' => 1]]]), 'cash return')->json('return');
        $this->assertWorkflowOk($this->getJson('/edge/local/pos/returns/' . $return['id']), 'posted return document');
        $this->assertWorkflowOk($this->get('/edge/local/pos/sales-returns'), 'sales returns list');
        $this->assertWorkflowOk($this->get('/edge/local/pos/sales-returns/' . $return['id']), 'sales return detail');

        // Quick Report (view on the canonical report authority).
        $this->assertWorkflowOk($this->getJson('/edge/local/pos/quick-report/options'), 'quick report options');

        // Shift history / detail, then close.
        $this->assertWorkflowOk($this->get('/edge/local/pos/shifts'), 'shift history');
        $this->assertWorkflowOk($this->get("/edge/local/pos/shifts/{$shiftId}"), 'shift detail');
        $this->assertWorkflowOk($this->postJson('/edge/local/pos/shift/close', ['counted_cash' => 0]), 'shift close', [200, 201, 422]);
        $this->assertNotSame('open', DB::connection('tenant')->table('shifts')->where('id', $shiftId)->value('status'), 'the shift closed');
    }

    public function test_each_missing_cashier_permission_is_refused_by_its_edge_endpoint(): void
    {
        $db = DB::connection('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalA])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 500])->assertStatus(201);
        $shiftId = (int) $db->table('shifts')->where('status', 'open')->value('id');

        // Fixtures every probe can target (built while the cashier holds the full template).
        $saleId = (int) $this->cashSale(3)['sale_id'];
        $saleLine = (int) $db->table('sales_order_lines')->where('sales_order_id', $saleId)->value('id');
        $returnId = (int) $this->postJson('/edge/local/pos/returns', ['sales_order_id' => $saleId, 'refund_method' => 'cash', 'refund_amount' => 100,
            'lines' => [['sales_order_line_id' => $saleLine, 'quantity' => 1]]])->assertStatus(201)->json('return.id');
        [$session, $held, $heldLine] = $this->openHoldAndKot('T1', 2);
        $emptySession = (int) $this->postJson("/edge/local/pos/restaurant/tables/{$this->tables['T4']}/open", ['guest_count' => 1])->assertStatus(201)->json('session_id');
        [$voidSale, $voidLine] = $this->takeawayHeldWithKot(6);
        $cancelId = (int) $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'takeaway', 'lines' => [['product_id' => $this->productQ, 'quantity' => 1]]])->assertStatus(201)->json('sale_id');
        [$deadSession, $deadSale] = $this->openHoldAndKot('T3', 1);
        $db->table('restaurant_table_sessions')->where('id', $deadSession)->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $this->userId]);
        $db->table('restaurant_tables')->where('id', $this->tables['T3'])->update(['status' => 'available']);

        $sale = fn () => $this->postJson('/edge/local/pos/sales', ['kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip', 'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productQ, 'quantity' => 1]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 50]]]);

        // permission => [probe, the 403 carries `permission`, reprobe (defaults to probe)]
        $probes = [
            'tenant.pos.index' => [fn () => $this->getJson('/edge/local/pos'), false],
            'tenant.pos.store' => [$sale, false],
            'tenant.pos.change-terminal' => [
                fn () => $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalB]), true,
                function () {
                    $r = $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalB]);
                    $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalA])->assertOk();

                    return $r;
                },
            ],
            'tenant.pos.quick-report-send' => [fn () => $this->getJson('/edge/local/pos/quick-report/options'), false],
            'tenant.pos.void-kot-item' => [fn () => $this->voidOne($voidSale, $voidLine), 'errors.permission'],
            'tenant.held-sales.store' => [fn () => $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'takeaway', 'lines' => [['product_id' => $this->productQ, 'quantity' => 1]]]), true],
            'tenant.held-sales.cancel' => [fn () => $this->postJson("/edge/local/pos/held-sales/{$cancelId}/cancel", ['reason_id' => $this->voidReasonId]), true],
            'tenant.held-sales.reattach-table' => [fn () => $this->postJson("/edge/local/pos/held-sales/{$deadSale}/reattach-table", ['restaurant_table_id' => $this->tables['T3']]), true],
            'tenant.sales-orders.split-bill.store' => [fn () => $this->postJson("/edge/local/pos/held-sales/{$held}/split", ['lines' => [['sales_order_line_id' => $heldLine, 'quantity' => 1]]]), true],
            'tenant.api.manager-approvals.verify' => [fn () => $this->postJson('/edge/local/pos/manager-approvals/verify', ['manager_employee_code' => 'X', 'manager_credential' => 'y', 'action_type' => 'manual_discount']), true],
            'tenant.restaurant.table-sessions.open' => [fn () => $this->postJson("/edge/local/pos/restaurant/tables/{$this->tables['T2']}/open", ['guest_count' => 2]), true],
            'tenant.restaurant.table-sessions.close' => [fn () => $this->postJson("/edge/local/pos/restaurant/table-sessions/{$emptySession}/close", ['status' => 'cancelled']), true],
            'tenant.restaurant.table-sessions.show' => [fn () => $this->getJson("/edge/local/pos/restaurant/table-sessions/{$session}"), true],
            'tenant.restaurant.table-sessions.move' => [fn () => $this->postJson("/edge/local/pos/restaurant/table-sessions/{$session}/move", ['target_table_id' => $this->tables['T5']]), true],
            'tenant.restaurant.table-sessions.merge' => [fn () => $this->postJson("/edge/local/pos/restaurant/table-sessions/{$session}/merge", ['target_session_id' => $session]), true],
            'tenant.restaurant.table-sessions.bill-preview' => [fn () => $this->getJson("/edge/local/pos/restaurant/table-sessions/{$session}/bill-preview"), true],
            'tenant.restaurant.table-sessions.bill-requested' => [fn () => $this->postJson("/edge/local/pos/restaurant/table-sessions/{$session}/bill-requested"), true],
            'tenant.shifts.store' => [fn () => $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0]), true],
            // counted_cash -1: the gate answers first; once granted, validation refuses it (the shift is never closed here).
            'tenant.shifts.close' => [fn () => $this->postJson('/edge/local/pos/shift/close', ['counted_cash' => -1]), true],
            'tenant.shifts.index' => [fn () => $this->getJson('/edge/local/pos/shifts'), false],
            'tenant.shifts.show' => [fn () => $this->getJson("/edge/local/pos/shifts/{$shiftId}"), false],
            'tenant.sales-returns.index' => [fn () => $this->getJson('/edge/local/pos/sales-returns'), false],
            'tenant.sales-returns.show' => [fn () => $this->getJson("/edge/local/pos/sales-returns/{$returnId}"), false],
            'tenant.sales-returns.store' => [fn () => $this->getJson('/edge/local/pos/returns/search?q='), false],
        ];

        // Every catalogue cashier permission is either probed or documented as having no Edge endpoint yet.
        $covered = array_merge(array_keys($probes), array_keys(self::NO_EDGE_ENDPOINT));
        sort($covered);
        $template = PosPermissionCatalog::cashier();
        sort($template);
        $this->assertSame($template, $covered, 'each catalogue cashier permission needs a probe here (or a documented NO_EDGE_ENDPOINT reason)');

        foreach ($probes as $permission => $spec) {
            [$probe, $shape] = $spec;
            $reprobe = $spec[2] ?? $probe;

            $this->revokeEdgePermission($this->userId, $permission);
            $this->login();
            $this->assertNotContains($permission, $this->effectivePermissions($this->userId));

            $denied = $probe();
            if ($shape === 'errors.permission') {
                // Shared KotCancellationService (Online contract): a ValidationException on the requester, not a 403.
                $this->assertSame(422, $denied->status(), "{$permission}: expected the shared-service 422, got {$denied->status()} — " . substr((string) $denied->getContent(), 0, 300));
                $this->assertNotEmpty($denied->json('errors.permission'), "{$permission}: the refusal must name the missing permission");
            } else {
                $this->assertSame(403, $denied->status(), "{$permission}: expected 403 without it, got {$denied->status()} — " . substr((string) $denied->getContent(), 0, 300));
                if ($shape === true) {
                    $this->assertSame($permission, $denied->json('permission'), "{$permission}: the 403 must name the permission");
                }
            }

            $this->grantEdgePermission($this->userId, $permission);
            $this->login();
            $allowed = $reprobe();
            $this->assertContains($allowed->status(), [200, 201, 422], "{$permission}: with the permission back the endpoint must answer (2xx / business 422), got {$allowed->status()} — " . substr((string) $allowed->getContent(), 0, 300));
        }

        // Nothing leaked while permissions were missing: the cashier ends with exactly the template again.
        $this->assertSame($template, $this->effectivePermissions($this->userId));
    }

    public function test_cashier_role_template_is_for_new_tenants_only_and_the_audit_is_read_only(): void
    {
        $provisioner = app(TenantProvisioner::class);
        $name = PosPermissionCatalog::CASHIER_ROLE_TEMPLATE;

        // An EXISTING tenant (re-provision) gets nothing.
        $this->assertNull($provisioner->provisionCashierRoleTemplate(false));
        $this->assertFalse(Role::where('name', $name)->exists());

        // A NEW tenant gets the template = exactly the catalogue cashier set, assigned to nobody.
        $role = $provisioner->provisionCashierRoleTemplate(true);
        $this->assertNotNull($role);
        $held = $role->fresh()->permissions->pluck('name')->all();
        sort($held);
        $template = PosPermissionCatalog::cashier();
        sort($template);
        $this->assertSame($template, $held);
        $this->assertSame(0, DB::connection('tenant')->table('model_has_roles')->where('role_id', $role->id)->count(), 'the template is never assigned automatically');

        // The tenant trims its role; provisioning again never overwrites / re-expands it.
        $role->revokePermissionTo('tenant.pos.quick-report-send');
        $this->assertNull($provisioner->provisionCashierRoleTemplate(true));
        $this->assertNotContains('tenant.pos.quick-report-send', $role->fresh()->permissions->pluck('name')->all());

        // A tenant's own cashier-like role, held by one user.
        $custom = Role::create(['name' => 'Counter Staff', 'guard_name' => 'tenant']);
        $custom->givePermissionTo(['tenant.pos.index', 'tenant.pos.store', 'tenant.held-sales.store']);
        $staff = $this->makeUser(['employee_code' => 'CS' . Str::random(4)]);
        DB::connection('tenant')->table('model_has_roles')->insert(['role_id' => $custom->id, 'model_type' => User::class, 'model_id' => $staff]);
        // A non-cashier role is not audited.
        \Spatie\Permission\Models\Permission::findOrCreate('tenant.suppliers.ledger', 'tenant');
        Role::create(['name' => 'Accountant', 'guard_name' => 'tenant'])->givePermissionTo(['tenant.suppliers.ledger']);

        // Register this test tenant DB on the (test) master so the command can iterate it.
        $code = 'wepermaudit';
        $m = DB::connection('master');
        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenants')->where('tenant_code', $code)->delete();
        $tenantId = $m->table('tenants')->insertGetId(['tenant_code' => $code, 'business_name' => 'W-E Audit', 'owner_name' => 'Owner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $c = config('database.connections.tenant');
        $m->table('tenant_databases')->insert(['tenant_id' => $tenantId, 'db_connection' => 'tenant', 'db_host' => $c['host'], 'db_port' => (int) $c['port'],
            'db_database' => $this->tenantDb, 'db_username' => $c['username'], 'db_password' => null, 'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);

        $snapshot = fn () => [
            DB::connection('tenant')->table('role_has_permissions')->count(),
            DB::connection('tenant')->table('model_has_permissions')->count(),
            DB::connection('tenant')->table('model_has_roles')->count(),
            DB::connection('tenant')->table('permissions')->count(),
        ];
        $before = $snapshot();

        try {
            $exit = Artisan::call('permissions:audit-cashier-roles', ['--tenant' => $code, '--json' => true]);
            $out = Artisan::output();
            $this->assertSame(0, $exit, $out);
            $report = json_decode($out, true);
            $this->assertIsArray($report, $out);
            $this->assertTrue($report['read_only']);
            $roles = collect($report['tenants'][0]['roles'])->keyBy('role');
            $this->assertEqualsCanonicalizing([$name, 'Counter Staff'], $roles->keys()->all(), 'only roles that may open the POS / complete a sale are audited');
            $this->assertSame(['tenant.pos.quick-report-send'], $roles[$name]['missing']);
            $this->assertSame(0, $roles[$name]['users']);
            $this->assertSame(1, $roles['Counter Staff']['users']);
            $this->assertEqualsCanonicalizing(array_values(array_diff(PosPermissionCatalog::cashier(), ['tenant.pos.index', 'tenant.pos.store', 'tenant.held-sales.store'])), $roles['Counter Staff']['missing']);

            // Human-readable form too.
            $this->assertSame(0, Artisan::call('permissions:audit-cashier-roles', ['--tenant' => $code]));
            $text = Artisan::output();
            $this->assertStringContainsString('Counter Staff', $text);
            $this->assertStringContainsString('nothing is granted', $text);
        } finally {
            // TenancyManager::deactivate() leaves `master` as default — restore the harness state.
            config(['database.connections.tenant.database' => $this->tenantDb]);
            DB::purge('tenant');
            DB::setDefaultConnection('tenant');
            $m->table('tenant_databases')->where('tenant_id', $tenantId)->delete();
            $m->table('tenants')->where('id', $tenantId)->delete();
        }

        $this->assertSame($before, $snapshot(), 'the audit is read-only: no role, grant or permission row changed');
        $this->assertNotContains('tenant.pos.quick-report-send', $role->fresh()->permissions->pluck('name')->all(), 'the audit never grants');
    }
}
