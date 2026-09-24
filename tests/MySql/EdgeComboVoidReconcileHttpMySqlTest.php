<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W6 reconcile (canonical b529c95, MANAGER-APPROVAL-COMBO-VOID-1) — R26 / D-25 on the Branch Server, over REAL HTTP.
 *
 * The Edge page asks for ONE grouped `void_kot_items` approval for every deal void (Online requestComboQuantity,
 * js/held approveVoids). Before the reconcile the shared KotCancellationService decided by COUNT: a deal void that
 * resolves to exactly ONE sent row demanded the singular `void_kot_item` and refused the grouped approval ("Manager
 * approval does not authorize this action"). After the reconcile the service decides by the approval's OWN shape:
 *  - a single-row deal void carrying a grouped `void_kot_items` approval (payload = that one line + qty) is ACCEPTED;
 *  - a singular `void_kot_item` approval still cannot authorise a cancel that spans TWO rows (never weakened);
 *  - the approval stays single-use and payload-bound (a second use is refused).
 */
class EdgeComboVoidReconcileHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $managerId;
    private string $managerCode;
    private int $karahi;
    private int $naan;
    private int $oneRowDeal;
    private int $twoRowDeal;
    private int $reasonId;

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
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs',
            'manager_approvals', 'manager_pins', 'void_reasons', 'model_has_permissions', 'permissions',
            'sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'combo_components', 'combos', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $now = now();
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi',
            'held_kot_cancellation_approval_mode' => 'manager_required', 'held_kot_line_cancellation_approval_mode' => 'manager_required']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CV' . Str::random(4)]);
        $this->managerId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CVM' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $cat = $this->makeCategory(['name' => 'Deals']);
        $this->karahi = $this->makeProduct($cat, ['name' => 'Chicken Karahi', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->naan = $this->makeProduct($cat, ['name' => 'Roghni Naan', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $this->makePaymentMethod(['method_type' => 'cash']);
        $t = DB::connection('tenant');
        // A deal whose kitchen food is ONE row (the header is never sent to the kitchen): its void resolves to one row.
        $this->oneRowDeal = (int) $t->table('combos')->insertGetId(['branch_id' => $this->branchId, 'code' => 'SOLO', 'name' => 'Karahi Deal', 'price' => 90, 'sort_order' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $t->table('combo_components')->insert(['combo_id' => $this->oneRowDeal, 'product_id' => $this->karahi, 'quantity' => 1, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $this->twoRowDeal = (int) $t->table('combos')->insertGetId(['branch_id' => $this->branchId, 'code' => 'FAM', 'name' => 'Family Deal', 'price' => 180, 'sort_order' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $t->table('combo_components')->insert([
            ['combo_id' => $this->twoRowDeal, 'product_id' => $this->karahi, 'quantity' => 1, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['combo_id' => $this->twoRowDeal, 'product_id' => $this->naan, 'quantity' => 2, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->reasonId = (int) $t->table('void_reasons')->insertGetId(['name' => 'Guest changed mind', 'reason_type' => 'cancel', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([
            ['product_id' => $this->karahi, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->naan, 'product_variant_id' => null, 'quantity' => 50],
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

    /** Hold `qty` of a deal, send it to the kitchen; returns [saleId, headerLineId, componentRows]. */
    private function heldDeal(int $comboId, int $qty): array
    {
        $hold = $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'takeaway', 'lines' => [['combo_id' => $comboId, 'quantity' => $qty]]])->assertStatus(201);
        $saleId = (int) $hold->json('sale_id');
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();
        $rows = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->orderBy('id')->get();
        $header = $rows->firstWhere('line_kind', 'combo_header');
        $components = $rows->where('line_kind', 'component')->values();

        return [$saleId, (int) $header->id, $components];
    }

    public function test_a_single_row_deal_void_with_the_grouped_approval_is_accepted_after_the_reconcile(): void
    {
        [$saleId, $headerId, $components] = $this->heldDeal($this->oneRowDeal, 2);
        $this->assertCount(1, $components);
        $component = $components[0];
        $this->assertSame(2.0, (float) $component->kot_sent_quantity, 'the kitchen has 2 karahi (the header is never sent)');

        // The page's approveVoids() for a deal: ONE grouped void_kot_items approval, payload = the rows that resolve (here one).
        $approval = $this->approve('void_kot_items', ['sales_order_id' => $saleId, 'cancellations' => [['line_id' => (int) $component->id, 'quantity' => 1]]]);
        $this->assertSame('void_kot_items', DB::connection('tenant')->table('manager_approvals')->where('id', $approval)->value('action_type'));

        $revise = fn (int $ap) => ['held_sale_id' => $saleId, 'order_type' => 'takeaway',
            'lines' => [['sales_order_line_id' => $headerId, 'combo_id' => $this->oneRowDeal, 'quantity' => 1]],
            'void_items' => [['old_line_id' => (int) $component->id, 'quantity' => 1, 'reason_id' => $this->reasonId, 'manager_approval_id' => $ap]]];

        // Pre-reconcile this answered 422 "Manager approval does not authorize this action" (count rule demanded void_kot_item).
        $r = $this->postJson('/edge/local/pos/held-sales', $revise($approval))->assertOk();
        $this->assertEquals(90.0, (float) $r->json('grand_total'), 'one deal left on the check');
        $this->assertSame(1, DB::connection('tenant')->table('sales_order_line_cancellations')->where('sales_order_id', $saleId)->count());
        $this->assertSame(1, DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->where('event_type', 'cancel')->count());
        $this->assertNotNull(DB::connection('tenant')->table('manager_approvals')->where('id', $approval)->value('consumed_at'), 'the approval is consumed');

        // single-use: the consumed grouped approval cannot authorise the next void of the same deal (remove the last deal)
        $component2 = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->where('line_kind', 'component')->first();
        $this->assertSame(1.0, (float) $component2->kot_sent_quantity);
        $this->postJson('/edge/local/pos/held-sales', ['held_sale_id' => $saleId, 'order_type' => 'takeaway',
            'lines' => [['product_id' => $this->naan, 'quantity' => 1]],
            'void_items' => [['old_line_id' => (int) $component2->id, 'quantity' => 1, 'reason_id' => $this->reasonId, 'manager_approval_id' => $approval]]])
            ->assertStatus(422);
        $this->assertSame(1, DB::connection('tenant')->table('sales_order_line_cancellations')->where('sales_order_id', $saleId)->count(), 'a reused approval cancels nothing');
    }

    public function test_a_singular_approval_still_cannot_authorise_a_void_spanning_two_rows(): void
    {
        [$saleId, $headerId, $components] = $this->heldDeal($this->twoRowDeal, 2);
        $this->assertCount(2, $components);
        [$k, $n] = [$components->firstWhere('product_id', $this->karahi), $components->firstWhere('product_id', $this->naan)];

        // 2 → 1 deal: karahi 2→1 and naan 4→2 — two rows resolve.
        $voids = fn (?int $ap) => [
            ['old_line_id' => (int) $k->id, 'quantity' => 1, 'reason_id' => $this->reasonId, 'manager_approval_id' => $ap],
            ['old_line_id' => (int) $n->id, 'quantity' => 2, 'reason_id' => $this->reasonId, 'manager_approval_id' => $ap],
        ];
        $payload = fn (?int $ap) => ['held_sale_id' => $saleId, 'order_type' => 'takeaway',
            'lines' => [['sales_order_line_id' => $headerId, 'combo_id' => $this->twoRowDeal, 'quantity' => 1]], 'void_items' => $voids($ap)];

        $singular = $this->approve('void_kot_item', ['sales_order_id' => $saleId, 'sales_order_line_id' => (int) $k->id, 'quantity' => 1]);
        $this->postJson('/edge/local/pos/held-sales', $payload($singular))->assertStatus(422)
            ->assertJsonPath('message', 'One manager approval is required for this grouped cancellation.');
        $this->assertSame(0, DB::connection('tenant')->table('sales_order_line_cancellations')->where('sales_order_id', $saleId)->count(), 'nothing cancelled on a refusal');

        $grouped = $this->approve('void_kot_items', ['sales_order_id' => $saleId, 'cancellations' => collect([
            ['line_id' => (int) $k->id, 'quantity' => 1], ['line_id' => (int) $n->id, 'quantity' => 2],
        ])->sortBy('line_id')->values()->all()]);
        $this->postJson('/edge/local/pos/held-sales', $payload($grouped))->assertOk()->assertJsonPath('grand_total', 180);
        $this->assertSame(2, DB::connection('tenant')->table('sales_order_line_cancellations')->where('sales_order_id', $saleId)->count());
    }
}
