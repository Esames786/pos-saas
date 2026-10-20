<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeHandbackOrchestrator;
use App\Services\Edge\EdgePosRuntimeFactory;
use App\Services\Printing\ReminderPauseService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * D2 (owner decision, 20 Oct 2026) — the Branch Server twin of the Online cashier reminder-pause workflow
 * (POS-REMINDER-PAUSE-1), over the REAL branch_server routes:
 *
 *  - POST /edge/local/pos/reminder-pause answers Online's {reminder: statusFor()} shape; the shift-status badge (GET /shift)
 *    carries the SAME `reminder` block the Online badge polls — same keys, same values, computed by the SAME shared service
 *    over the LOCAL shift row; pause + resume stamp / clear shifts.reminders_paused_at on the appliance's open shift;
 *  - the appliance's reminder plan (shared PrintJobService through EdgeLocalPrintKotService) prints the KOT but NO Reminder
 *    while the terminal is paused, another terminal keeps its slips, and the next round after resume prints again;
 *  - STANDBY refuses the mutation exactly like every Edge mutation (422 {ok:false, code: STANDBY}) while the badge still reads;
 *  - without tenant.pos.pause-reminders: a named 403 and nothing changes (the state is still shown, can_toggle=false);
 *  - no open shift → Online's ShiftException 422; a terminal of another branch → 422 INVALID_TERMINAL; a terminal without a
 *    Reminder rule shows no switch (available=false);
 *  - RECONCILIATION: the pause is shift-scoped; a handback is BLOCKED while the paused shift is open (OPEN_SHIFTS); closing the
 *    shift ends the pause; the next shift starts with Reminder ON; the closed row keeps the stamp for audit; the sale envelope's
 *    shift block carries no pause field — the Cloud shift is unaffected by design (its own "new shift starts ON" rule applies).
 */
class EdgeReminderPauseHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $otherBranchId;
    private int $otherTerminal;
    private int $dineIn;     // the counter that pauses (own Reminder rule)
    private int $takeaway;   // its own Reminder rule — must be unaffected
    private int $bare;       // no Reminder rule at all
    private int $cat;
    private int $productId;
    private int $cashMethodId;
    private int $cashierId;

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
            'edge_local_print_deliveries', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'model_has_permissions', 'permissions',
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_line_cancellations',
            'category_printer_mappings', 'terminal_printer_settings', 'printers',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['name' => 'Reminder Branch', 'allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->otherBranchId = $this->makeBranch(['name' => 'Other Branch', 'timezone' => 'Asia/Karachi']);
        $this->otherTerminal = $this->makeTerminal($this->otherBranchId, ['name' => 'Elsewhere']);
        $this->dineIn = $this->makeTerminal($this->branchId, ['code' => 'T4', 'name' => '4-Dine In']);
        $this->takeaway = $this->makeTerminal($this->branchId, ['code' => 'T1', 'name' => '1-Takeaway']);
        $this->bare = $this->makeTerminal($this->branchId, ['code' => 'T9', 'name' => '9-Spare']);
        $this->cat = $this->makeCategory(['name' => 'Biryani']);
        $this->productId = $this->makeProduct($this->cat, ['name' => 'Beef Biryani', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);

        // The Online fixture: each counter has its own KOT + Reminder rule on a Reminder-capable network printer; T9 prints KOT only.
        $dinePrinter = $this->makePrinter(['code' => 'P-DINE', 'print_role' => 'both', 'branch_id' => $this->branchId, 'supports_reminder' => 1, 'ip_address' => '127.0.0.1', 'port' => 9611]);
        $takePrinter = $this->makePrinter(['code' => 'P-TAKE', 'print_role' => 'both', 'branch_id' => $this->branchId, 'supports_reminder' => 1, 'ip_address' => '127.0.0.1', 'port' => 9612]);
        foreach ([[$this->dineIn, $dinePrinter], [$this->takeaway, $takePrinter]] as [$term, $printer]) {
            foreach (['kot', 'reminder'] as $role) {
                DB::table('category_printer_mappings')->insert([
                    'branch_id' => $this->branchId, 'terminal_id' => $term, 'category_id' => $role === 'kot' ? $this->cat : null,
                    'printer_id' => $printer, 'print_role' => $role, 'order_type' => 'all', 'is_active' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        DB::table('category_printer_mappings')->insert([
            'branch_id' => $this->branchId, 'terminal_id' => $this->bare, 'category_id' => $this->cat,
            'printer_id' => $takePrinter, 'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->cashierId = $this->makeUser(['name' => 'Shahzad', 'default_branch_id' => $this->branchId, 'employee_code' => 'RP' . Str::random(4)]);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 500]]);
        // The template (PosPermissionCatalog::cashier()) now carries tenant.pos.pause-reminders (D3).
        $this->seedEdgeCredential($this->cashierId, $this->branchId, 1);
        $this->login();
        $this->select($this->dineIn);
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

    private function login(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($this->cashierId), 'tenant');
        Auth::shouldUse('tenant');
    }

    private function select(int $terminalId): void
    {
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $terminalId])->assertOk();
    }

    /** The shared page's call: POST reminder-pause with the client-selected terminal. */
    private function toggle(int $terminalId, bool $paused): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/edge/local/pos/reminder-pause', ['terminal_id' => $terminalId, 'paused' => $paused]);
    }

    /** The badge poll: GET /shift?terminal_id= → its `reminder` block. */
    private function badge(int $terminalId): array
    {
        return $this->getJson('/edge/local/pos/shift?terminal_id=' . $terminalId)->assertOk()->json('reminder');
    }

    private function pausedAt(int $terminalId): ?string
    {
        return DB::table('shifts')->where('terminal_id', $terminalId)->where('status', 'open')->value('reminders_paused_at');
    }

    /** A held takeaway order with one unsent line on the selected terminal + its KOT round (the shared view's kotQueue route). */
    private function holdAndKot(): array
    {
        $hold = $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'takeaway', 'lines' => [['product_id' => $this->productId, 'quantity' => 1]]])->assertStatus(201);
        $saleId = (int) $hold->json('sale_id');
        $kot = $this->postJson("/edge/local/pos/sales/{$saleId}/kot");
        $this->assertContains($kot->status(), [200, 201], substr((string) $kot->getContent(), 0, 300));

        return [$saleId, $kot->json()];
    }

    private function reminders(int $saleId): int
    {
        return PrintJob::on('tenant')->where('reference_type', 'sales_order')->where('reference_id', $saleId)->where('document_type', 'reminder')->count();
    }

    private function kots(int $saleId): int
    {
        return PrintJob::on('tenant')->where('reference_type', 'sales_order')->where('reference_id', $saleId)->where('document_type', 'kot')->count();
    }

    // ── the twin ────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_pause_and_resume_answer_the_online_shape_and_the_badge_carries_the_same_state(): void
    {
        $this->assertNull($this->pausedAt($this->dineIn));
        $before = $this->badge($this->dineIn);
        $this->assertSame(['available' => true, 'paused' => false, 'paused_by' => null, 'paused_at' => null, 'can_toggle' => true], $before);

        $res = $this->toggle($this->dineIn, true)->assertOk();
        $reminder = $res->json('reminder');
        $this->assertSame(['available', 'paused', 'paused_by', 'paused_at', 'can_toggle'], array_keys($reminder), 'Online ShiftController::posReminderPause shape');
        $this->assertTrue($reminder['available']);
        $this->assertTrue($reminder['paused']);
        $this->assertSame('Shahzad', $reminder['paused_by'], 'who paused it is on record');
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $reminder['paused_at'], 'H:i in the shift timezone, as Online');
        $this->assertTrue($reminder['can_toggle']);
        $this->assertNotNull($this->pausedAt($this->dineIn), 'the stamp is on the LOCAL open shift row');
        $this->assertSame($this->cashierId, (int) DB::table('shifts')->where('terminal_id', $this->dineIn)->where('status', 'open')->value('reminders_paused_by_user_id'));

        // The badge the page polls = the SAME block, from the SAME shared service over the local row.
        $this->assertSame($reminder, $this->badge($this->dineIn));
        $this->assertSame($reminder, app(ReminderPauseService::class)->statusFor(Terminal::on('tenant')->find($this->dineIn), User::on('tenant')->find($this->cashierId)));
        // Pausing twice keeps the first stamp (Online: `?? now()`).
        $again = $this->toggle($this->dineIn, true)->assertOk()->json('reminder');
        $this->assertSame($reminder['paused_at'], $again['paused_at']);

        // Resume.
        $resumed = $this->toggle($this->dineIn, false)->assertOk()->json('reminder');
        $this->assertFalse($resumed['paused']);
        $this->assertNull($resumed['paused_by']);
        $this->assertNull($resumed['paused_at']);
        $this->assertNull($this->pausedAt($this->dineIn));
        $this->assertFalse($this->badge($this->dineIn)['paused']);

        // The runtime the shared page renders with: capability ON, the local route, no off-hint.
        $rt = app(EdgePosRuntimeFactory::class)->make();
        $this->assertTrue($rt->can('reminderPause'));
        $this->assertSame('/edge/local/pos/reminder-pause', $rt->route('reminderPause'));
        $this->assertArrayNotHasKey('capability.reminderPause', $rt->labels);
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        $this->assertStringContainsString('id="pos-reminder-toggle"', $html);
        $this->assertStringNotContainsString('/api/pos/reminder-pause', $html, 'no Cloud literal in the shared page on the appliance');
    }

    public function test_the_local_reminder_plan_prints_the_kot_but_no_reminder_while_paused_and_resumes_after(): void
    {
        $this->toggle($this->dineIn, true)->assertOk();

        [$saleA, $kotA] = $this->holdAndKot();
        $this->assertGreaterThan(0, $this->kots($saleA), 'the KOT still goes to the kitchen');
        $this->assertSame(0, $this->reminders($saleA), 'no Reminder slip while paused — the shared PrintJobService reads the local shift stamp');
        $this->assertSame([], $kotA['reminder']['auto_jobs'] ?? ['missing'], 'the KOT answer plans no automatic Reminder');
        $this->assertSame([], $kotA['reminder']['ask_printers'] ?? ['missing']);

        // Another counter (its own open shift) keeps its slips.
        $this->select($this->takeaway);
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
        [$saleB] = $this->holdAndKot();
        $this->assertSame(1, $this->reminders($saleB), 'pausing the Dine In counter must not silence Takeaway');
        $this->assertFalse($this->badge($this->takeaway)['paused']);
        $this->assertTrue($this->badge($this->dineIn)['paused'], 'the other counter sees the Dine In state on its poll too');

        // Back on Dine In: resume → the next round prints.
        $this->select($this->dineIn);
        $this->toggle($this->dineIn, false)->assertOk();
        [$saleC] = $this->holdAndKot();
        $this->assertSame(1, $this->reminders($saleC), 'ON again → the next round prints');
    }

    public function test_standby_refuses_the_mutation_while_the_badge_still_reads(): void
    {
        $this->toggle($this->dineIn, true)->assertOk();

        // Lease mode on (a heartbeat URL is configured) and the Cloud holds the branch → every Edge mutation refuses.
        config(['edge.authority.heartbeat_url' => 'http://127.0.0.1:9/api/edge/authority/heartbeat']);
        DB::connection('tenant')->table('edge_local_meta')->update(['authority_state' => 'standby']);
        app()->forgetInstance(EdgeBranchContext::class);

        $r = $this->toggle($this->dineIn, false);
        $r->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('code', 'STANDBY');
        $this->assertStringContainsString('Local Mode is not active', (string) $r->json('message'));
        $this->assertNotNull($this->pausedAt($this->dineIn), 'nothing changed on standby');
        $this->toggle($this->dineIn, true)->assertStatus(422)->assertJsonPath('code', 'STANDBY');

        // Reading the state is not a mutation: the badge still tells the truth.
        $this->assertTrue($this->badge($this->dineIn)['paused']);
    }

    public function test_without_the_permission_a_named_403_and_nothing_changes(): void
    {
        $this->revokeEdgePermission($this->cashierId, ReminderPauseService::PERMISSION);
        $this->login();

        $this->toggle($this->dineIn, true)->assertStatus(403)
            ->assertJsonPath('permission', 'tenant.pos.pause-reminders')
            ->assertJsonPath('message', 'You are not allowed to pause Reminder slips.');
        $this->assertNull($this->pausedAt($this->dineIn));

        $badge = $this->badge($this->dineIn);
        $this->assertTrue($badge['available'], 'the state is still shown to him');
        $this->assertFalse($badge['can_toggle'], 'but not the button');

        $this->grantEdgePermission($this->cashierId, ReminderPauseService::PERMISSION);
        $this->login();
        $this->assertTrue($this->toggle($this->dineIn, true)->assertOk()->json('reminder.paused'));
    }

    public function test_no_open_shift_invalid_terminal_and_bad_input_are_refused(): void
    {
        // Validation first (Online: terminal_id + paused required).
        $this->postJson('/edge/local/pos/reminder-pause', ['terminal_id' => $this->dineIn])->assertStatus(422)->assertJsonValidationErrors(['paused']);
        $this->postJson('/edge/local/pos/reminder-pause', ['paused' => true])->assertStatus(422)->assertJsonValidationErrors(['terminal_id']);

        // A terminal of another branch is never adopted (W-B: INVALID_TERMINAL).
        $this->toggle($this->otherTerminal, true)->assertStatus(422)->assertJsonPath('code', 'INVALID_TERMINAL');
        $this->assertNull($this->pausedAt($this->dineIn));

        // No open shift → Online's ShiftException text, nothing stamped.
        DB::table('shifts')->where('terminal_id', $this->dineIn)->update(['status' => 'closed', 'closed_at' => now()]);
        $r = $this->toggle($this->dineIn, true);
        $r->assertStatus(422);
        $this->assertStringContainsString('No open shift on this terminal', (string) $r->json('message'));
        $this->assertSame(0, DB::table('shifts')->where('terminal_id', $this->dineIn)->whereNotNull('reminders_paused_at')->count());
        $closedBadge = $this->badge($this->dineIn);
        $this->assertTrue($closedBadge['available']);
        $this->assertFalse($closedBadge['paused']);
    }

    public function test_the_switch_shows_only_where_a_reminder_rule_fires(): void
    {
        $this->assertTrue($this->badge($this->dineIn)['available']);
        $this->select($this->bare);
        $bare = $this->badge($this->bare);
        $this->assertFalse($bare['available'], 'a terminal without a Reminder rule shows no switch');
        $this->assertFalse($bare['paused']);
        $this->assertTrue($bare['can_toggle'], 'the permission flag is independent of the rule (Online shape)');
        // No terminal selected by the page → Online's no-terminal answer.
        $none = $this->getJson('/edge/local/pos/shift?terminal_id=0')->assertOk();
        $this->assertFalse($none->json('has_terminal'));
        $this->assertSame(['available' => false, 'paused' => false, 'paused_by' => null, 'paused_at' => null, 'can_toggle' => true], $none->json('reminder'));
    }

    // ── reconciliation after reconnect / handback ───────────────────────────────────────────────────────────────────

    public function test_the_pause_ends_with_the_shift_a_handback_is_blocked_while_it_is_open_and_the_envelope_carries_no_pause(): void
    {
        $this->toggle($this->dineIn, true)->assertOk();
        $pausedShiftId = (int) DB::table('shifts')->where('terminal_id', $this->dineIn)->where('status', 'open')->value('id');

        // A cash sale made while paused: its envelope's shift block is the canonical parent snapshot — no pause field rides it.
        $this->postJson('/edge/local/pos/sales', ['kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip', 'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100, 'tendered_amount' => 100]]])->assertStatus(201);
        $envelope = json_decode((string) DB::table('edge_sync_outbox')->orderByDesc('id')->value('envelope'), true);
        $this->assertIsArray($envelope['shift'] ?? null);
        $this->assertEqualsCanonicalizing(['shift_uuid', 'business_date', 'opened_at', 'terminal_id', 'opened_by_user_id'], array_keys($envelope['shift']), 'the shift snapshot carries identity only — never reminders_paused_* (canonical JSON sorts the keys)');
        $this->assertStringNotContainsString('reminders_paused', json_encode($envelope));

        // 1. While the paused shift is open the handback is BLOCKED (OPEN_SHIFTS) — the operator closes the shift on the appliance.
        $assess = app(EdgeHandbackOrchestrator::class)->assess();
        $this->assertFalse($assess['ready']);
        $this->assertContains('OPEN_SHIFTS', array_column($assess['blockers'], 'code'));
        $this->assertSame(1, $assess['facts']['open_shifts']);

        // 2. Closing the shift ends the pause (the stamp stays on the closed row for audit).
        $this->postJson('/edge/local/pos/shift/close', ['counted_cash' => 100])->assertOk();
        $closed = DB::table('shifts')->where('id', $pausedShiftId)->first();
        $this->assertSame('closed', $closed->status);
        $this->assertNotNull($closed->reminders_paused_at, 'audit: who paused this shift stays on record');
        $this->assertNotContains('OPEN_SHIFTS', array_column(app(EdgeHandbackOrchestrator::class)->assess()['blockers'], 'code'), 'no appliance shift is open any more');
        $afterClose = $this->badge($this->dineIn);
        $this->assertTrue($afterClose['available']);
        $this->assertFalse($afterClose['paused'], 'no open shift → nothing is paused');

        // 3. The next shift (appliance or, after handback, the Cloud's own row) starts with Reminder ON — nobody touched it.
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
        $this->assertFalse($this->badge($this->dineIn)['paused'], 'the new shift is ON');
        $this->assertNull($this->pausedAt($this->dineIn));
        [$sale] = $this->holdAndKot();
        $this->assertSame(1, $this->reminders($sale), 'the new shift prints its Reminder');
    }
}
