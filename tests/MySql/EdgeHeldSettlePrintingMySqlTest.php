<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W-G1 — Direct Pay printing when a HELD order is settled on the Branch Server, over REAL HTTP on a branch_server-booted app.
 *
 * Online is the specification: paying a held sale is `POST /pos` + held_sale_id (SalesOrderController::store) — the
 * `kot_print_intent` / `receipt_print_intent` pair is validated (`in:print,skip`), hashed into the idempotent intent
 * (SaleIdempotencyService::canonicalSalePayload), stored on the paid row as DirectPayPrintOrchestrator::initialState,
 * orchestrated AFTER the commit and re-orchestrated on an idempotent replay, and the response carries Online's `printing`
 * block + `idempotent_replay`. The Edge settle now does the same through the SAME EdgeLocalPrintDirectPayService the Edge
 * Direct Pay endpoint uses — so a retried settle never queues a second receipt or KOT (ensure-once / existing-KOT reuse).
 */
class EdgeHeldSettlePrintingMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $karahi;
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
            'edge_local_print_deliveries', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations',
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'printers', 'terminal_printer_settings', 'category_printer_mappings',
            'manager_approvals', 'model_has_permissions', 'permissions',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'HSP' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $categoryId = $this->makeCategory(['name' => 'Karahi']);
        $this->karahi = $this->makeProduct($categoryId, ['name' => 'Chicken Karahi', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $this->karahi, 'product_variant_id' => null, 'quantity' => 100]]);
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

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────

    private function cash(float $amount): array
    {
        return [['payment_method_id' => $this->cashMethodId, 'amount' => $amount, 'tendered_amount' => $amount]];
    }

    /** A takeaway held check: 2 × Karahi (100) = 200, KOT NOT yet sent (the page sends KOT separately). */
    private function holdCheck(): int
    {
        return (int) $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'takeaway',
            'lines' => [['product_id' => $this->karahi, 'quantity' => 2]],
        ])->assertStatus(201)->assertJsonPath('grand_total', 200)->json('sale_id');
    }

    /** The settle body the shared page sends Online's POST /pos with (held_sale_id + both print intents). */
    private function settleBody(string $uuid, ?string $kot, ?string $receipt): array
    {
        $body = ['client_uuid' => $uuid, 'payments' => $this->cash(200)];
        if ($kot !== null) {
            $body['kot_print_intent'] = $kot;
        }
        if ($receipt !== null) {
            $body['receipt_print_intent'] = $receipt;
        }

        return $body;
    }

    private function settle(int $saleId, array $body)
    {
        return $this->postJson("/edge/local/pos/held-sales/{$saleId}/settle", $body);
    }

    private function jobs(int $saleId, string $type): \Illuminate\Support\Collection
    {
        return PrintJob::on('tenant')->where('reference_id', $saleId)->where('document_type', $type)->orderBy('id')->get();
    }

    private function printState(int $saleId): ?array
    {
        $raw = DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('direct_pay_print_state');

        return $raw === null ? null : json_decode((string) $raw, true);
    }

    // ── 1. print/print on a held settle = Online's printing block, queued once ────────────────────────────────

    public function test_settle_with_print_intents_queues_kot_and_receipt_and_answers_the_online_printing_block(): void
    {
        $saleId = $this->holdCheck();
        $this->assertCount(0, $this->jobs($saleId, 'kot'));
        $this->assertCount(0, $this->jobs($saleId, 'receipt'));

        $res = $this->settle($saleId, $this->settleBody((string) Str::uuid(), 'print', 'print'))->assertOk()
            ->assertJsonPath('sale_id', $saleId)->assertJsonPath('status', 'paid')
            ->assertJsonPath('idempotent_replay', false)
            ->assertJsonPath('print_intents', ['kot' => 'print', 'receipt' => 'print']);

        // Online's `printing` block (DirectPayPrintOrchestrator::response) with the Edge document URLs.
        $printing = $res->json('printing');
        $this->assertIsArray($printing, 'the settle response carries the Direct Pay printing result');
        foreach (['configured', 'stable', 'sale_paid', 'state', 'retry_available', 'kot_jobs', 'receipt', 'reminder'] as $k) {
            $this->assertArrayHasKey($k, $printing, "printing.{$k}");
        }
        $this->assertTrue($printing['configured']);
        $this->assertTrue($printing['sale_paid']);
        $this->assertFalse($printing['retry_available']);
        foreach (['revision', 'auto_jobs', 'ask_printers', 'confirmation_token', 'warning'] as $k) {
            $this->assertArrayHasKey($k, $printing['reminder'], "printing.reminder.{$k}");
        }

        // Receipt: ONE job, Print Here fallback (no printer configured), served from the appliance.
        $receiptJobs = $this->jobs($saleId, 'receipt');
        $this->assertCount(1, $receiptJobs);
        $this->assertSame((int) $receiptJobs->first()->id, (int) $printing['receipt']['job_id']);
        $this->assertSame((int) $receiptJobs->first()->id, (int) $printing['receipt']['id'], 'Edge alias `id` = job_id');
        $this->assertTrue((bool) $printing['receipt']['fallback']);
        $this->assertSame('browser', $printing['receipt']['printer_type']);
        $this->assertStringContainsString('/edge/local/pos/print-jobs/' . $receiptJobs->first()->id . '/document', (string) $printing['receipt']['preview_url']);
        $this->assertStringNotContainsString('/printing/documents/', (string) $printing['receipt']['preview_url'], 'never a Cloud document URL');

        // KOT: the unsent delta (the whole check — KOT was never sent) → one KOT event, its job(s) in the block.
        $kotJobs = $this->jobs($saleId, 'kot');
        $this->assertGreaterThanOrEqual(1, $kotJobs->count(), 'the un-sent lines print as a KOT at settle (Online queueKot delta)');
        $this->assertSame($kotJobs->pluck('id')->map(fn ($i) => (int) $i)->all(), collect($printing['kot_jobs'])->pluck('job_id')->map(fn ($i) => (int) $i)->all());
        foreach ($printing['kot_jobs'] as $job) {
            foreach (['job_id', 'job_no', 'printer_type', 'preview_url', 'fallback', 'id'] as $k) {
                $this->assertArrayHasKey($k, $job, "kot_jobs.*.{$k}");
            }
            $this->assertStringContainsString('/edge/local/pos/print-jobs/', (string) $job['preview_url']);
        }
        $this->assertSame(1, (int) DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->count());

        // The durable orchestrator state on the paid row — Online's initialState, advanced by the orchestrator.
        $state = $this->printState($saleId);
        $this->assertSame('print', $state['kot_intent']);
        $this->assertSame('print', $state['receipt_intent']);
        $this->assertSame('queued', $state['receipt_status']);
        $this->assertSame('queued', $state['kot_status']);
        $this->assertSame((int) $receiptJobs->first()->id, (int) $state['receipt_job_id']);
        $this->assertNotNull(DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('direct_pay_print_orchestrated_at'));
    }

    // ── 2. retry = replay, exactly once ──────────────────────────────────────────────────────────────────────

    public function test_a_retried_settle_replays_and_never_queues_a_second_receipt_or_kot(): void
    {
        $saleId = $this->holdCheck();
        $uuid = (string) Str::uuid();
        $body = $this->settleBody($uuid, 'print', 'print');

        $first = $this->settle($saleId, $body)->assertOk()->assertJsonPath('idempotent_replay', false)->json();
        $receiptBefore = $this->jobs($saleId, 'receipt')->pluck('id')->all();
        $kotBefore = $this->jobs($saleId, 'kot')->pluck('id')->all();
        $this->assertCount(1, $receiptBefore);
        $this->assertNotEmpty($kotBefore);
        $outboxBefore = (int) DB::connection('tenant')->table('edge_sync_outbox')->count();

        // Same client_uuid + same payload (incl. the same intents) → the replay re-orchestrates and REUSES the stored jobs.
        $replay = $this->settle($saleId, $body)->assertOk()->assertJsonPath('sale_id', $saleId)->assertJsonPath('idempotent_replay', true)->json();
        $this->assertSame($receiptBefore, $this->jobs($saleId, 'receipt')->pluck('id')->all(), 'ensure-once receipt: no duplicate bill on retry');
        $this->assertSame($kotBefore, $this->jobs($saleId, 'kot')->pluck('id')->all(), 'existing-KOT reuse: no duplicate kitchen ticket on retry');
        $this->assertSame((int) $first['printing']['receipt']['job_id'], (int) $replay['printing']['receipt']['job_id']);
        $this->assertSame(collect($first['printing']['kot_jobs'])->pluck('job_id')->all(), collect($replay['printing']['kot_jobs'])->pluck('job_id')->all());
        $this->assertSame(1, (int) DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->count());
        $this->assertSame($outboxBefore, (int) DB::connection('tenant')->table('edge_sync_outbox')->count(), 'one outbox row — the sale posted once');
        $this->assertSame(1, (int) DB::connection('tenant')->table('sale_payments')->where('sales_order_id', $saleId)->count());

        // Online hashes the intents (SaleIdempotencyService::canonicalSalePayload): a changed intent under the same uuid is a 409.
        $this->settle($saleId, $this->settleBody($uuid, 'print', 'skip'))->assertStatus(409);
        $this->assertSame($receiptBefore, $this->jobs($saleId, 'receipt')->pluck('id')->all());
    }

    // ── 3. skip/skip, absent intents (refused like Online since Phase 3 Stage A), invalid intent ──────────────

    public function test_skip_intents_print_nothing_absent_intents_are_refused_like_online_and_an_invalid_intent_is_422(): void
    {
        // skip / skip → configured, nothing queued (Online: kot skipped, receipt skipped).
        $skipId = $this->holdCheck();
        $printing = $this->settle($skipId, $this->settleBody((string) Str::uuid(), 'skip', 'skip'))->assertOk()
            ->assertJsonPath('print_intents', ['kot' => 'skip', 'receipt' => 'skip'])->json('printing');
        $this->assertTrue($printing['configured']);
        $this->assertNull($printing['receipt']);
        $this->assertSame([], $printing['kot_jobs']);
        $this->assertSame('skipped', $printing['state']['kot_status']);
        $this->assertSame('skipped', $printing['state']['receipt_status']);
        $this->assertSame(0, PrintJob::on('tenant')->where('reference_id', $skipId)->count());

        // Intents absent → REFUSED (Phase 3 Stage A: the shared page always sends both; Online SalesOrderController::store on
        // tenant.pos.store answers the SAME 422 — {message, errors.printing}). One missing intent is refused too. Nothing changes.
        $plainId = $this->holdCheck();
        foreach ([[null, null], ['print', null], [null, 'skip']] as [$kot, $receipt]) {
            $this->settle($plainId, $this->settleBody((string) Str::uuid(), $kot, $receipt))->assertStatus(422)
                ->assertJsonPath('message', 'Choose the Direct Pay KOT and Receipt intent before completing the sale.')
                ->assertJsonPath('errors.printing.0', 'Choose the Direct Pay KOT and Receipt intent before completing the sale.');
        }
        $this->assertSame('held', DB::connection('tenant')->table('sales_orders')->where('id', $plainId)->value('status'), 'a refused settle changes nothing');
        $this->assertNull($this->printState($plainId));
        $this->assertSame(0, PrintJob::on('tenant')->where('reference_id', $plainId)->count());

        // Online validateSale: `in:print,skip`.
        $badId = $this->holdCheck();
        $this->settle($badId, $this->settleBody((string) Str::uuid(), 'maybe', 'print'))->assertStatus(422)->assertJsonValidationErrors('kot_print_intent');
        $this->assertSame('held', DB::connection('tenant')->table('sales_orders')->where('id', $badId)->value('status'), 'a refused settle changes nothing');
    }

    // ── 4. KOT already sent before settle → no second kitchen ticket ─────────────────────────────────────────

    public function test_a_kot_already_sent_for_the_held_check_is_not_printed_again_at_settle(): void
    {
        $saleId = $this->holdCheck();
        // The kitchen already has this round (Online: the page sends the KOT when the check is held).
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();
        $kotBefore = $this->jobs($saleId, 'kot')->pluck('id')->all();
        $this->assertNotEmpty($kotBefore, 'the KOT round was queued before settle');

        $printing = $this->settle($saleId, $this->settleBody((string) Str::uuid(), 'print', 'print'))->assertOk()->json('printing');
        // Online queueKot is the UN-SENT delta → nothing left to send → kot not_required, no new job, no new batch.
        $this->assertSame($kotBefore, $this->jobs($saleId, 'kot')->pluck('id')->all(), 'no duplicate kitchen ticket at settle');
        $this->assertSame([], $printing['kot_jobs']);
        $this->assertSame('not_required', $printing['state']['kot_status']);
        $this->assertSame(1, (int) DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->count());
        // …while the receipt still prints once.
        $this->assertCount(1, $this->jobs($saleId, 'receipt'));
        $this->assertNotNull($printing['receipt']);
    }
}
