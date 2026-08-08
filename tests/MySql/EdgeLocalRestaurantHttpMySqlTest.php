<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-LOCAL-POS-1 — the RESTAURANT layer through REAL HTTP on a branch_server-BOOTED app: dine-in table
 * session (frozen session_uuid), held order + Add Round (per-line captured price; durable sale_uuid with
 * line churn), KOT BUSINESS EVENTS through the real PrintJobService (kot_batches sequence/event_type +
 * kot_batch_lines kot_line_uuid/source_line_uuid; browser-fallback route completed server-side — NO print
 * transport runs), manager re-auth (real manager_pins Hash::check + single-use approval consumption),
 * settle (stock ONCE at settle, shared settlement, session closes) and the shift-close blockers.
 */
class EdgeLocalRestaurantHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $managerId;
    private int $tableId;
    private int $productId;
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
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta',
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs',
            // printing config MUST be empty: this suite proves the zero-printer browser-fallback KOT
            // route, and residue from other printing tests would route to a real network printer.
            'category_printer_mappings', 'terminal_printer_settings', 'printers',
            'manager_approvals', 'manager_pins', 'void_reasons',
            'model_has_permissions', 'permissions',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'held_kot_cancellation_approval_mode' => 'manager_required']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'REST' . Str::random(4)]);
        $this->managerId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'MGR' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'available']);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->voidReasonId = (int) DB::connection('tenant')->table('void_reasons')->insertGetId([
            'name' => 'Guest changed mind', 'reason_type' => 'cancel', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // cashier AND manager need the REAL Cloud cancellation permission (spatie, tenant guard) — the
        // cashier to request a cancellation, the manager to APPROVE it (Edge manager contract).
        $permId = (int) DB::connection('tenant')->table('permissions')->insertGetId([
            'name' => 'tenant.pos.void-kot-item', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$this->userId, $this->managerId] as $uid) {
            DB::connection('tenant')->table('model_has_permissions')->insert([
                'permission_id' => $permId, 'model_type' => User::class, 'model_id' => $uid,
            ]);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->baselineId = (int) $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 20]])->id;
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        // the manager's OWN Edge-local credential — manager_pins are deliberately NEVER seeded on Edge.
        $this->seedEdgeCredential($this->managerId, $this->branchId, 1, 'MgrPass1');
        $this->managerCode = (string) User::on('tenant')->find($this->managerId)->employee_code;
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');

        // terminal + open shift for every scenario.
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

    public function test_full_dine_in_lifecycle_open_hold_kot_add_round_settle(): void
    {
        // ── board: the table is available, no session ──
        $board = $this->getJson('/edge/local/pos/restaurant/board');
        $board->assertOk()->assertJsonPath('branch_id', $this->branchId)
            ->assertJsonPath('floors.0.tables.0.table_no', 'T1')
            ->assertJsonPath('floors.0.tables.0.status', 'available')
            ->assertJsonPath('floors.0.tables.0.session', null);

        // ── open the table: session with frozen session_uuid; table occupied; duplicate open refused ──
        $open = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 3]);
        $open->assertStatus(201)->assertJsonPath('status', 'open');
        $sessionId = $open->json('session_id');
        $sessionUuid = $open->json('session_uuid');
        $this->assertTrue(Str::isUlid($sessionUuid), 'session_uuid must be a ULID');
        $this->assertSame('TS-' . $this->branchId . '-' . $sessionUuid, $open->json('session_no'), 'display no derives from the identity');
        $this->assertSame('occupied', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableId)->value('status'));
        $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->assertStatus(422);

        // dine_in hold WITHOUT a session is refused.
        $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in',
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
        ])->assertStatus(422);

        // ── round 1: hold 2 × 100 on the session ──
        $hold = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
        ]);
        $hold->assertStatus(201)->assertJsonPath('status', 'held')->assertJsonPath('grand_total', 200);
        $saleId = $hold->json('sale_id');
        $saleUuid = $hold->json('sale_uuid');
        $line1Id = $hold->json('lines.0.id');
        $this->assertTrue(Str::isUlid($saleUuid));
        $this->assertFalse((bool) $hold->json('lines.0.kot_sent'));
        // a second NEW check on the same session is refused (one open check — continue instead).
        $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
        ])->assertStatus(422);
        // held sale consumed NO stock and produced NO settlement.
        $this->assertSame(20.0, $this->edgeOnHand($this->baselineId, $this->productId));
        $this->assertSame(0, DB::connection('tenant')->table('sales_ledgers')->count());

        // ── KOT round 1: REAL business event (sequence 1 / normal), browser-fallback completed ──
        $line1Uuid = DB::connection('tenant')->table('sales_order_lines')->where('id', $line1Id)->value('line_uuid');
        $kot1 = $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot");
        $kot1->assertOk()
            ->assertJsonPath('batch.sequence_no', 1)
            ->assertJsonPath('batch.event_type', 'normal')
            ->assertJsonPath('batch.lines.0.source_line_uuid', $line1Uuid);
        $this->assertSame(2.0, (float) $kot1->json('batch.lines.0.quantity'), 'round 1 sends the full unsent delta');
        $this->assertTrue(Str::isUlid($kot1->json('batch.lines.0.kot_line_uuid')));
        $this->assertSame(2.0, (float) DB::connection('tenant')->table('sales_order_lines')->where('id', $line1Id)->value('kot_sent_quantity'));
        // LOCKED RULE — business event ≠ physical print: the sent bookkeeping above advanced, but NO
        // print was claimed: every job is a queued logical intent, printed_at NULL, no printer attached.
        $kot1->assertJsonPath('jobs.0.print_status', 'queued');
        foreach (DB::connection('tenant')->table('print_jobs')->get() as $job) {
            $this->assertSame('queued', $job->print_status, 'no Edge print job may claim completion');
            $this->assertNull($job->printed_at);
            $this->assertNull($job->printer_id, 'no transport/printer is attached on the appliance');
        }
        $this->assertSame(0, (int) DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('kot_print_count'), 'print counters never advance without a real print');
        // no unsent delta → NO new batch.
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk()->assertJsonPath('batch', null);
        $this->assertSame(1, DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->count());

        // ── Add Round with CAPTURED price: catalog price moves 100 → 150; the carried line keeps 100 ──
        DB::connection('tenant')->table('products')->where('id', $this->productId)->update(['default_selling_price' => 150]);
        $round2 = $this->postJson('/edge/local/pos/held-sales', [
            'held_sale_id' => $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [
                ['sales_order_line_id' => $line1Id, 'product_id' => $this->productId, 'quantity' => 2],
                ['product_id' => $this->productId, 'quantity' => 1],
            ],
        ]);
        $round2->assertOk()->assertJsonPath('sale_uuid', $saleUuid)->assertJsonPath('grand_total', 350); // 2×100 captured + 1×150 new
        $lines = collect($round2->json('lines'))->sortBy('unit_price')->values();
        $this->assertSame(100.0, (float) $lines[0]['unit_price']);
        $this->assertSame(2.0, (float) $lines[0]['kot_sent_quantity'], 'KOT-sent state survives the line churn');
        $this->assertSame(150.0, (float) $lines[1]['unit_price']);
        $this->assertSame(0.0, (float) $lines[1]['kot_sent_quantity']);
        $this->assertSame(1, SalesOrder::on('tenant')->where('sale_uuid', $saleUuid)->count(), 'same durable sale row');

        // ── KOT round 2: sequence 2 / addition, ONLY the new delta (1) ──
        $kot2 = $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot");
        $kot2->assertOk()->assertJsonPath('batch.sequence_no', 2)->assertJsonPath('batch.event_type', 'addition');
        $this->assertCount(1, $kot2->json('batch.lines'));
        $this->assertSame(1.0, (float) $kot2->json('batch.lines.0.quantity'));

        // ── the open check + open table BLOCK the shift close (real assertClosableUnderLock rule) ──
        $this->postJson('/edge/local/pos/shift/close', ['counted_cash' => 0])->assertStatus(422);

        // ── settle: cash 350; stock consumed ONCE for final quantities; session closes; table frees ──
        $clientUuid = (string) Str::uuid();
        $settle = $this->postJson("/edge/local/pos/held-sales/{$saleId}/settle", [
            'client_uuid' => $clientUuid,
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 350, 'tendered_amount' => 400]],
        ]);
        $settle->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('sale_uuid', $saleUuid)
            ->assertJsonPath('change_amount', 50)->assertJsonPath('edge_sync_state', 'pending');
        $this->assertSame(17.0, $this->edgeOnHand($this->baselineId, $this->productId), '20 − (2+1) exactly once at settle');
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count(), 'official stock untouched');
        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count(), 'no GL offline');
        $ledger = DB::connection('tenant')->table('sales_ledgers')->where('sales_order_id', $saleId)->pluck('amount', 'entry_type');
        $this->assertSame(350.0, (float) $ledger['sale_total'], 'shared settlement posted the sale');
        $this->assertSame(350.0, (float) $ledger['sale_payment'], 'shared settlement posted the payment');
        $this->assertCount(2, $ledger, 'exactly sale_total + sale_payment (no discount/tax/tip rows)');
        $this->assertSame('closed', DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $sessionId)->value('status'));
        $this->assertSame('available', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableId)->value('status'));

        // settle replay: same client_uuid → same sale, stock NOT consumed twice.
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/settle", [
            'client_uuid' => $clientUuid,
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 350, 'tendered_amount' => 400]],
        ])->assertOk()->assertJsonPath('sale_id', $saleId);
        $this->assertSame(17.0, $this->edgeOnHand($this->baselineId, $this->productId));

        // ── the shift now closes: expected_cash grew by the APPLIED 350 only ──
        $this->postJson('/edge/local/pos/shift/close', ['counted_cash' => 350])
            ->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('cash_variance', 0);
    }

    public function test_reducing_kitchen_sent_line_requires_void_and_real_manager_approval(): void
    {
        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->json('session_id');
        $hold = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->productId, 'quantity' => 3]],
        ]);
        $saleId = $hold->json('sale_id');
        $lineId = $hold->json('lines.0.id');
        $lineUuid = DB::connection('tenant')->table('sales_order_lines')->where('id', $lineId)->value('line_uuid');
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk()->assertJsonPath('batch.sequence_no', 1);

        $reduce = fn (array $extra = []) => $this->postJson('/edge/local/pos/held-sales', array_merge([
            'held_sale_id' => $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['sales_order_line_id' => $lineId, 'product_id' => $this->productId, 'quantity' => 1]],
        ], $extra));

        // silent shrink of kitchen-sent food → refused; void without approval (manager_required) → refused.
        $reduce()->assertStatus(422);
        $reduce(['void_items' => [['old_line_id' => $lineId, 'quantity' => 2, 'reason_id' => $this->voidReasonId]]])->assertStatus(422);

        // ── manager re-auth is PURELY LOCAL: zero manager_pins exist (they never ship to an appliance)
        //    and the master DB is dead for the whole approval + void flow. ──
        $this->assertSame(0, DB::connection('tenant')->table('manager_pins')->count(), 'no manager_pins dependency on Edge');
        config(['database.connections.master.database' => 'nonexistent_master_mgr_proof']);
        DB::purge('master');

        // wrong Edge credential refused (real Argon2id verification, generic error).
        $this->postJson('/edge/local/pos/manager-approvals/verify', [
            'manager_employee_code' => $this->managerCode, 'manager_credential' => 'WrongPass9', 'action_type' => 'void_kot_item',
            'payload' => ['sales_order_id' => $saleId, 'sales_order_line_id' => $lineId, 'quantity' => 2],
        ])->assertStatus(422);
        // unknown action type has no offline contract → fail closed.
        $this->postJson('/edge/local/pos/manager-approvals/verify', [
            'manager_employee_code' => $this->managerCode, 'manager_credential' => 'MgrPass1', 'action_type' => 'approve_anything',
            'payload' => ['sales_order_id' => $saleId],
        ])->assertStatus(422);
        // the manager's OWN Edge-local credential mints the single-use approval; cashier session untouched.
        $verify = $this->postJson('/edge/local/pos/manager-approvals/verify', [
            'manager_employee_code' => $this->managerCode, 'manager_credential' => 'MgrPass1', 'action_type' => 'void_kot_item',
            'payload' => ['sales_order_id' => $saleId, 'sales_order_line_id' => $lineId, 'quantity' => 2],
        ]);
        $verify->assertStatus(201);
        $this->assertSame($this->userId, (int) auth('tenant')->id(), 'manager re-auth must not replace the cashier session');
        $approvalId = $verify->json('approval_id');
        $this->assertTrue(Str::isUlid($verify->json('approval_uuid')));
        $this->assertSame($this->managerId, (int) DB::connection('tenant')->table('manager_approvals')->where('id', $approvalId)->value('approved_by_user_id'));

        // the approved void now succeeds: cancel-KOT event + cancellation row with frozen snapshot refs.
        $ok = $reduce(['void_items' => [['old_line_id' => $lineId, 'quantity' => 2, 'reason_id' => $this->voidReasonId, 'manager_approval_id' => $approvalId]]]);
        $ok->assertOk()->assertJsonPath('grand_total', 100);
        $this->assertSame(1.0, (float) collect($ok->json('lines'))->first()['kot_sent_quantity']);

        $cancelBatch = DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->where('event_type', 'cancel')->first();
        $this->assertNotNull($cancelBatch, 'a cancel KOT business event is recorded');
        $cancellation = DB::connection('tenant')->table('sales_order_line_cancellations')->where('sales_order_id', $saleId)->first();
        $this->assertSame($lineUuid, $cancellation->source_line_uuid, 'snapshot survives the line churn');
        $this->assertSame($cancelBatch->event_uuid, $cancellation->referenced_kot_event_uuid);
        $this->assertSame(2.0, (float) $cancellation->quantity);
        $this->assertNotNull(DB::connection('tenant')->table('manager_approvals')->where('id', $approvalId)->value('consumed_at'), 'approval is single-use');
        $this->assertSame('held', SalesOrder::on('tenant')->find($saleId)->status);

        // the CONSUMED approval cannot authorize a second void (single-use, payload-bound).
        $takeaway = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'takeaway', 'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
        ]);
        $tSaleId = $takeaway->json('sale_id');
        $tLineId = $takeaway->json('lines.0.id');
        $this->postJson("/edge/local/pos/held-sales/{$tSaleId}/kot")->assertOk();
        $this->postJson('/edge/local/pos/held-sales', [
            'held_sale_id' => $tSaleId, 'order_type' => 'takeaway',
            'lines' => [['sales_order_line_id' => $tLineId, 'product_id' => $this->productId, 'quantity' => 1]],
            'void_items' => [['old_line_id' => $tLineId, 'quantity' => 1, 'reason_id' => $this->voidReasonId, 'manager_approval_id' => $approvalId]],
        ])->assertStatus(422);
    }

    /** Every stale/ineligible manager identity fails closed — with the master DB unavailable throughout. */
    public function test_manager_reauth_refusal_matrix_on_pure_local_authority(): void
    {
        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 1])->json('session_id');
        $hold = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
        ]);
        $saleId = $hold->json('sale_id');
        $lineId = $hold->json('lines.0.id');
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();

        config(['database.connections.master.database' => 'nonexistent_master_mgr_matrix']);
        DB::purge('master');

        $verify = fn (string $code, string $cred) => $this->postJson('/edge/local/pos/manager-approvals/verify', [
            'manager_employee_code' => $code, 'manager_credential' => $cred, 'action_type' => 'void_kot_item',
            'payload' => ['sales_order_id' => $saleId, 'sales_order_line_id' => $lineId, 'quantity' => 1],
        ]);

        // missing permission: enrolled Edge manager credential but NO tenant.pos.void-kot-item.
        $noPermId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'NOPERM' . Str::random(3)]);
        $this->seedEdgeCredential($noPermId, $this->branchId, 1, 'NoPermPass1');
        $verify(User::on('tenant')->find($noPermId)->employee_code, 'NoPermPass1')->assertStatus(422);

        // wrong branch: manager belongs to another branch (no assignment here) — refused before permission.
        $otherBranch = $this->makeBranch();
        $wrongBranchId = $this->makeUser(['default_branch_id' => $otherBranch, 'employee_code' => 'WRBR' . Str::random(4)]);
        $this->seedEdgeCredential($wrongBranchId, $this->branchId, 1, 'WrongBranch1');
        $verify(User::on('tenant')->find($wrongBranchId)->employee_code, 'WrongBranch1')->assertStatus(422);

        // disabled manager user.
        DB::connection('tenant')->table('users')->where('id', $this->managerId)->update(['status' => 'inactive']);
        $verify($this->managerCode, 'MgrPass1')->assertStatus(422);
        DB::connection('tenant')->table('users')->where('id', $this->managerId)->update(['status' => 'active']);

        // stale activation epoch on the manager credential (superseded appliance generation).
        DB::connection('tenant')->table('edge_local_user_credentials')->where('user_id', $this->managerId)->update(['activation_epoch' => 0]);
        $verify($this->managerCode, 'MgrPass1')->assertStatus(422);
        DB::connection('tenant')->table('edge_local_user_credentials')->where('user_id', $this->managerId)->update(['activation_epoch' => 1]);

        // and the restored, current manager works — proving the matrix refused for the right reasons.
        $ok = $verify($this->managerCode, 'MgrPass1');
        $ok->assertStatus(201);
        $this->assertSame($this->managerId, (int) DB::connection('tenant')->table('manager_approvals')->where('id', $ok->json('approval_id'))->value('approved_by_user_id'));
    }

    /** Captured price is bound to the line's economic identity — an old line id is not a price token. */
    public function test_captured_price_cannot_be_inherited_across_identity_changes(): void
    {
        // holds/revisions never touch operational stock, so the second product needs no baseline entry.
        $expensiveId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 500]);

        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 1])->json('session_id');
        $hold = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]], // captured at 100
        ]);
        $saleId = $hold->json('sale_id');
        $cheapLineId = $hold->json('lines.0.id');

        $revise = fn (array $lines) => $this->postJson('/edge/local/pos/held-sales', [
            'held_sale_id' => $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => $lines,
        ]);

        // A. old cheap line id + DIFFERENT product → cannot inherit the 100 price.
        $revise([['sales_order_line_id' => $cheapLineId, 'product_id' => $expensiveId, 'quantity' => 1]])->assertStatus(422);

        // B. old no-variant line id + a variant of the same product → refused (variant identity differs).
        $variantId = (int) DB::connection('tenant')->table('product_variants')->insertGetId(['product_id' => $this->productId, 'sku' => 'V' . Str::random(5), 'name' => 'Large', 'is_default' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $revise([['sales_order_line_id' => $cheapLineId, 'product_id' => $this->productId, 'product_variant_id' => $variantId, 'quantity' => 1]])->assertStatus(422);

        // C. the same old line id supplied twice → controlled refusal, not duplicated captured price.
        $revise([
            ['sales_order_line_id' => $cheapLineId, 'product_id' => $this->productId, 'quantity' => 1],
            ['sales_order_line_id' => $cheapLineId, 'product_id' => $this->productId, 'quantity' => 2],
        ])->assertStatus(422);

        // D. a line id belonging to a DIFFERENT held order → refused.
        $foreign = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'takeaway', 'lines' => [['product_id' => $expensiveId, 'quantity' => 1]],
        ]);
        $revise([['sales_order_line_id' => $foreign->json('lines.0.id'), 'product_id' => $expensiveId, 'quantity' => 1]])->assertStatus(422);

        // E. same-identity Add Round still preserves the captured price after a catalog move.
        DB::connection('tenant')->table('products')->where('id', $this->productId)->update(['default_selling_price' => 999]);
        $ok = $revise([['sales_order_line_id' => $cheapLineId, 'product_id' => $this->productId, 'quantity' => 2]]);
        $ok->assertOk();
        $this->assertSame(100.0, (float) collect($ok->json('lines'))->first()['unit_price'], 'captured price survives for the SAME identity only');
    }

    public function test_cancel_held_order_and_close_session_frees_the_table_and_shift(): void
    {
        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 1])->json('session_id');
        $saleId = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
        ])->json('sale_id');
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();

        // whole-order cancel of sent food requires the manager (branch mode) — without approval refused.
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/cancel", ['reason_id' => $this->voidReasonId])->assertStatus(422);
        $approvalId = $this->postJson('/edge/local/pos/manager-approvals/verify', [
            'manager_employee_code' => $this->managerCode, 'manager_credential' => 'MgrPass1',
            'action_type' => 'cancel_held_order', 'payload' => ['sales_order_id' => $saleId],
        ])->json('approval_id');
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/cancel", ['reason_id' => $this->voidReasonId, 'manager_approval_id' => $approvalId])
            ->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(20.0, $this->edgeOnHand($this->baselineId, $this->productId), 'a cancelled check never touched stock');

        // the session (no remaining open orders) closes; the table frees; the shift can close.
        $this->postJson("/edge/local/pos/restaurant/table-sessions/{$sessionId}/close")->assertOk()->assertJsonPath('status', 'closed');
        $this->assertSame('available', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableId)->value('status'));
        $this->postJson('/edge/local/pos/shift/close', ['counted_cash' => 0])->assertOk()->assertJsonPath('cash_variance', 0);
    }

    public function test_takeaway_hold_then_settle_without_a_table_session(): void
    {
        $hold = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'takeaway',
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
        ]);
        $hold->assertStatus(201)->assertJsonPath('status', 'held')->assertJsonPath('restaurant_table_session_id', null);
        $saleId = $hold->json('sale_id');

        $this->postJson("/edge/local/pos/held-sales/{$saleId}/settle", [
            'client_uuid' => (string) Str::uuid(),
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]],
        ])->assertOk()->assertJsonPath('status', 'paid');
        $this->assertSame(19.0, $this->edgeOnHand($this->baselineId, $this->productId));
    }
}
