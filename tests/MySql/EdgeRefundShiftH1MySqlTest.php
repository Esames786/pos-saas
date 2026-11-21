<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeAuthorityTick;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeHandbackOrchestrator;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeLocalReturnService;
use App\Services\Edge\EdgeReturnEnvelopeBuilder;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\EdgeCloudBridgeFixture;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * REFUND-SHIFT-H1 (owner decision H1, 22 Oct 2026) — "THE SHIFT THAT ACTUALLY PAYS THE REFUND OWNS THE REFUND CASH
 * MOVEMENT", proven on the Edge side with real code on both ends and TWO databases (EdgeCloudBridgeFixture):
 *
 *   (1) sale and cash refund in the same open local shift → that shift's buckets move once; the local row carries
 *       shift_id / terminal_id / processing_shift_uuid / the shift's business day;
 *   (2) the sale's shift closed, refund in a later open shift → the later shift pays; the sale's shift is a snapshot;
 *   (3) refund on ANOTHER terminal's open shift → that terminal's shift pays, by the cashier's selected terminal,
 *       while the sale's own shift stays open and untouched;
 *   (4) no open shift on the selected terminal → the cash refund is refused with the shared Online message;
 *   (5) the return event (edge-return-envelope-v2) names the processing shift by STABLE identity only; the Cloud row
 *       carries that shift_uuid + terminal; when a Cloud shifts row with that uuid exists (a future Edge-shift mirror,
 *       simulated here) it gets shift_id and its buckets move exactly once; without one the identity alone is kept;
 *   (6) replay of the same event → already_applied, no second row, no second cash movement, shift buckets unchanged;
 *   (7) a lost ACK recovered by reconciliation and the controlled handback preserve the attribution on both sides;
 *   (8) the local shift's close totals (ShiftService::closeShift — what EdgeLocalShiftController@closeShift runs)
 *       reflect the refund on the PROCESSING shift, not on the sale's shift;
 *   and the pre-H1 limitation: an edge-return-envelope-v1 event (no processing shift, a numeric local actor.shift_id
 *   that must never be used) is still ingested, attributed by COUNTER only — the open Cloud shift on that terminal if
 *   any, else no shift; a v2 cash event with NO processing shift is refused (fail closed on post-H1 appliances).
 */
class EdgeRefundShiftH1MySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeCloudBridgeFixture;

    private const CONFIG_TABLES = ['branches', 'users', 'categories', 'units', 'products', 'terminals', 'payment_methods'];

    private int $branchId;
    private int $userId;
    private int $terminalA;
    private int $terminalB;
    private int $burgerId;
    private int $cashMethodId;
    private int $cloudSaleId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        $this->provisionTwoDatabases();

        // ── the Cloud's truth ──
        $this->cleanTenant([
            'edge_branch_authority_leases', 'edge_inbound_return_ingestions', 'edge_inbound_sale_ingestions', 'sales_return_lines', 'sales_returns', 'sales_ledgers',
            'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'accounts', 'cash_bank_accounts', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts', 'payment_methods', 'products', 'categories', 'units', 'terminals', 'branches', 'users',
        ]);
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        $this->branchId = $this->makeBranch(['name' => 'H1 Branch', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'H1' . Str::random(4)]);
        $this->terminalA = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->terminalB = $this->makeTerminal($this->branchId, ['name' => 'Counter B']);
        $conn = DB::connection('tenant');
        $pc = $conn->table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->burgerId = $this->makeProduct($this->makeCategory(), ['name' => 'Burger', 'unit_id' => $pc, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $accountId = $conn->table('accounts')->where('code', '1000')->value('id') ?? $conn->table('accounts')->value('id');
        $cbId = $conn->table('cash_bank_accounts')->insertGetId(['code' => 'TILL', 'name' => 'Till', 'account_type' => 'cash', 'account_id' => $accountId, 'current_balance' => 0, 'is_active' => 1, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('payment_methods')->where('id', $this->cashMethodId)->update(['cash_bank_account_id' => $cbId]);
        $batchId = $conn->table('inventory_batches')->insertGetId(['batch_key' => "b-{$this->branchId}-{$this->burgerId}", 'branch_id' => $this->branchId, 'product_id' => $this->burgerId, 'batch_no' => 'B' . $this->burgerId, 'received_date' => now()->toDateString(), 'unit_cost' => 40, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('stock_balances')->insert(['balance_key' => "{$this->branchId}-{$this->burgerId}-0-{$batchId}", 'branch_id' => $this->branchId, 'product_id' => $this->burgerId, 'inventory_batch_id' => $batchId, 'quantity_on_hand' => 100, 'average_cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
        $this->cloudSaleId = $this->insertCloudSale();
        $this->registerCloudTenantAndDevice($this->branchId, 1);

        // ── the appliance ──
        $this->mirrorConfigToAppliance(self::CONFIG_TABLES);
        $this->asEdge(function () {
            $this->cleanTenant([
                'edge_returnable_sale_lines', 'edge_returnable_sales', 'edge_local_connection_transitions', 'edge_baseline_cutovers', 'edge_sync_outbox',
                'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_user_credentials', 'edge_local_meta',
                'sales_return_lines', 'sales_returns', 'sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts', 'manager_approvals',
            ]);
            $this->bindEdgeLocalMeta($this->branchId, 1, $this->cloudTenantId, $this->cloudDeviceUuid, 1);
            DB::connection('tenant')->table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'tenant_code' => $this->bridgeTenantCode]);
            $this->seedEdgeCredential($this->userId, $this->branchId, 1);
            $this->grantEdgePermission($this->userId, 'tenant.sales-returns.store');
        });
        $this->configureApplianceCloudUrls();
        config(['edge.authority.ttl_seconds' => 60, 'edge.authority.skew_margin_seconds' => 10, 'edge.authority.unstable_after_failures' => 2, 'edge.authority.lost_after_failures' => 4, 'edge.authority.handback_min_consecutive_acks' => 2]);
        $this->bridgeCloud();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->cleanupCloudRegistration();
        $this->useDb($this->cloudDb);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    // ── (1)(2)(3)(5)(6)(7)(8) ────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_processing_shift_pays_each_refund_and_its_stable_identity_reaches_the_cloud_exactly_once(): void
    {
        $this->assertSame('refreshed:initial', $this->tick()['work']['stock']);
        $this->goLocal();

        // (1) sale and cash refund in the SAME open shift (S1 on Counter A).
        $s1Id = $this->openLocalShift($this->terminalA, 1000.0);
        $sale1 = $this->localSale($this->terminalA, 3);
        $this->assertSame($s1Id, (int) $sale1->shift_id, 'the sale records its shift — provenance of the sale');
        $r1 = $this->localReturn((int) $sale1->id, $this->terminalA);
        $s1 = $this->localShift($s1Id);
        $row1 = $this->localReturnRow($r1['return_uuid']);
        $this->assertSame($s1Id, (int) $row1->shift_id);
        $this->assertSame($this->terminalA, (int) $row1->terminal_id);
        $this->assertSame($s1->shift_uuid, $row1->processing_shift_uuid, 'the processing shift by STABLE identity');
        $this->assertSame(substr((string) $s1->business_date, 0, 10), substr((string) $row1->business_date, 0, 10), "the processing shift's day");
        $this->assertSame(100.0, (float) $s1->total_refunds, 'exactly once');
        $this->assertSame(100.0, (float) $s1->total_cash_refunds);
        $this->assertSame(1000.0 + 300.0 - 100.0, (float) $s1->expected_cash);

        // (8) the shift close the Edge controller runs (ShiftService::closeShift): the closed totals carry the refund.
        $closed1 = $this->closeLocalShift($s1Id);
        $this->assertSame('closed', $closed1->status);
        $this->assertSame(100.0, (float) $closed1->total_refunds);
        $this->assertSame(100.0, (float) $closed1->total_cash_refunds);
        $this->assertSame(1200.0, (float) $closed1->expected_cash);
        $this->assertSame(0.0, (float) $closed1->cash_variance);
        $s1Snapshot = (array) $closed1;

        // (2) the sale's shift is CLOSED; the refund goes into the LATER open shift S2 on the same counter.
        $s2Id = $this->openLocalShift($this->terminalA, 500.0);
        $r2 = $this->localReturn((int) $sale1->id, $this->terminalA);
        $s2 = $this->localShift($s2Id);
        $row2 = $this->localReturnRow($r2['return_uuid']);
        $this->assertSame($s2Id, (int) $row2->shift_id);
        $this->assertSame($s2->shift_uuid, $row2->processing_shift_uuid);
        $this->assertSame(100.0, (float) $s2->total_refunds);
        $this->assertSame(400.0, (float) $s2->expected_cash);
        $this->assertSame($s1Snapshot, (array) $this->localShift($s1Id), "the sale's closed shift is never reopened or mutated");

        // (3) a sale under S2 (still OPEN, Counter A) returned by the cashier on Counter B → S3 (Counter B) pays.
        $s3Id = $this->openLocalShift($this->terminalB, 200.0);
        $sale2 = $this->localSale($this->terminalA, 2);
        $this->assertSame($s2Id, (int) $sale2->shift_id);
        $s2Before = (array) $this->localShift($s2Id);
        $r3 = $this->localReturn((int) $sale2->id, $this->terminalB);
        $s3 = $this->localShift($s3Id);
        $row3 = $this->localReturnRow($r3['return_uuid']);
        $this->assertSame($s3Id, (int) $row3->shift_id, "the cashier's SELECTED counter's shift pays");
        $this->assertSame($this->terminalB, (int) $row3->terminal_id);
        $this->assertSame($s3->shift_uuid, $row3->processing_shift_uuid);
        $this->assertSame(100.0, (float) $s3->total_cash_refunds);
        $this->assertSame(100.0, (float) $s3->expected_cash);
        $this->assertSame($s2Before, (array) $this->localShift($s2Id), "the sale's own OPEN shift on Counter A is untouched");

        // The event: v2, the processing shift by stable identity only — no numeric local shift id anywhere in it.
        $env3 = $this->envelopeOf($r3['return_uuid']);
        $this->assertSame(EdgeReturnEnvelopeBuilder::SCHEMA, $env3['envelope_schema_version']);
        $this->assertSame('edge-return-envelope-v2', $env3['envelope_schema_version']);
        $this->assertEqualsCanonicalizing(['shift_uuid', 'terminal_id', 'business_date', 'opened_at', 'opened_by_user_id'], array_keys($env3['processing_shift']), 'identity + facts only (the stored envelope is canonical JSON: keys sorted)');
        $this->assertSame($s3->shift_uuid, $env3['processing_shift']['shift_uuid']);
        $this->assertSame($this->terminalB, $env3['processing_shift']['terminal_id']);
        $this->assertSame(substr((string) $s3->business_date, 0, 10), $env3['processing_shift']['business_date']);
        $this->assertSame($this->userId, $env3['processing_shift']['opened_by_user_id']);
        $this->assertNotNull($env3['processing_shift']['opened_at']);
        $this->assertSame(substr((string) $s3->business_date, 0, 10), $env3['business_date'], "the event's business day is the processing shift's");
        $this->assertArrayNotHasKey('shift_id', $env3['actor'], 'v2 carries no numeric local shift id');
        $this->assertStringNotContainsString('"shift_id"', json_encode($env3));

        // (5) a FUTURE Edge-shift mirror on the Cloud: a shifts row carrying S3's uuid (none for S1 / S2).
        $mirrorId = $this->mirrorShiftOnCloud($s3);

        // WAN back: everything syncs (2 sales, 3 returns); the Cloud posts each official return exactly once.
        $this->bridgeFailures = [];
        $this->drain();
        $cloud = $this->cloudCounts();
        $this->assertSame(3, $cloud['returns'], json_encode($cloud));
        $this->assertSame(3, $cloud['registry_applied']);
        $this->assertSame(3, $cloud['cashbank_out']);
        $c1 = $this->cloudReturnRow($r1['return_uuid']);
        $c2 = $this->cloudReturnRow($r2['return_uuid']);
        $c3 = $this->cloudReturnRow($r3['return_uuid']);
        $this->assertSame($s1->shift_uuid, $c1->processing_shift_uuid, 'the Cloud row keeps the processing identity even with no Cloud shift row');
        $this->assertSame($this->terminalA, (int) $c1->terminal_id);
        $this->assertNull($c1->shift_id, 'no Cloud shifts row with that uuid → no numeric attribution, no guess');
        $this->assertSame(substr((string) $s1->business_date, 0, 10), substr((string) $c1->business_date, 0, 10));
        $this->assertSame($s2->shift_uuid, $c2->processing_shift_uuid);
        $this->assertNull($c2->shift_id);
        $this->assertSame($s3->shift_uuid, $c3->processing_shift_uuid);
        $this->assertSame($this->terminalB, (int) $c3->terminal_id);
        $this->assertSame($mirrorId, (int) $c3->shift_id, 'the mirror resolves by identity → the corresponding Cloud shift record');
        $mirror = $this->cloudShift($mirrorId);
        $this->assertSame(100.0, (float) $mirror->total_refunds, 'the mirrored shift paid once');
        $this->assertSame(100.0, (float) $mirror->total_cash_refunds);
        $this->assertSame(100.0, (float) $mirror->expected_cash);
        $this->assertSame(100.0, $cloud['shift_refunds_total'], 'no other Cloud shift moved (the sale\'s shift has no Cloud record)');
        $cloudSale1 = $this->asCloud(fn () => DB::connection('tenant')->table('sales_orders')->where('sale_uuid', $sale1->sale_uuid)->first());
        $this->assertNull($cloudSale1->shift_id, 'the Cloud copy of an Edge sale carries no shift (no mirror today) — and was never consulted for the refund');

        // (6) replay the SAME event → already_applied; nothing moves.
        $this->assertSame('already_applied', $this->replay($env3)->assertOk()->json('status'));
        $this->assertSame($cloud, $this->cloudCounts(), 'no second row, no second cash movement');
        $this->assertSame((array) $mirror, (array) $this->cloudShift($mirrorId), 'the mirrored shift buckets are unchanged');

        // (7) the ACK was lost: reconciliation recovers it; the attribution survives on both sides.
        $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $r3['return_uuid'])->update(['state' => EdgeSyncOutbox::STATE_PENDING, 'acknowledged_at' => null, 'ack_ingestion_uuid' => null, 'ack_payload' => null, 'lease_owner' => null, 'lease_expires_at' => null]));
        $this->bridgeFailures = ['sync/returns' => 'down'];
        $t = $this->tick();
        $this->assertSame(1, (int) ($t['work']['findings']['recovered_lost_ack'] ?? 0), json_encode($t['work']));
        $this->bridgeFailures = [];
        $this->assertSame($cloud, $this->cloudCounts(), 'ONE of everything on the Cloud');
        $this->assertSame((array) $c3, (array) $this->cloudReturnRow($r3['return_uuid']), 'the Cloud row still carries the processing identity');
        $this->assertSame((array) $row3, (array) $this->localReturnRow($r3['return_uuid']), 'the local row still carries shift_id + uuid');
        $this->assertSame((array) $mirror, (array) $this->cloudShift($mirrorId));
        $this->assertSame('acknowledged', $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $r3['return_uuid'])->value('state')));

        // Controlled handback: the open shifts block it (close them through the shared close), then hand back.
        $this->tick();
        $this->assertSame(['OPEN_SHIFTS'], array_column($this->asEdge(fn () => app(EdgeHandbackOrchestrator::class)->assess())['blockers'], 'code'));
        $closed3 = $this->closeLocalShift($s3Id);
        $this->closeLocalShift($s2Id);
        $this->assertSame(100.0, (float) $closed3->total_refunds, "(8) the PROCESSING shift closes with the refund on it");
        $this->assertSame(100.0, (float) $closed3->expected_cash);
        $hb = $this->asEdge(fn () => app(EdgeHandbackOrchestrator::class)->run('supervisor'));
        $this->assertSame(EdgeHandbackOrchestrator::HANDED_BACK, $hb['status'], json_encode($hb));
        $this->assertSame($s3->shift_uuid, $this->cloudReturnRow($r3['return_uuid'])->processing_shift_uuid, 'attribution preserved after handback (Cloud)');
        $this->assertSame($mirrorId, (int) $this->cloudReturnRow($r3['return_uuid'])->shift_id);
        $this->assertSame($s3->shift_uuid, $this->localReturnRow($r3['return_uuid'])->processing_shift_uuid, 'attribution preserved after handback (local)');
        $this->assertSame($s3Id, (int) $this->localReturnRow($r3['return_uuid'])->shift_id);
        $this->assertSame((array) $mirror, (array) $this->cloudShift($mirrorId), 'handback moved no money');
        $this->assertSame($s1Snapshot, (array) $this->localShift($s1Id), "the original sale's shift: untouched from start to finish");
    }

    // ── (4) + the v1 limitation ─────────────────────────────────────────────────────────────────────────────────────

    public function test_cash_without_an_open_shift_on_the_selected_terminal_is_refused_and_a_v1_event_is_attributed_by_counter_only(): void
    {
        $this->assertSame('refreshed:initial', $this->tick()['work']['stock']);
        $this->goLocal();
        $s1Id = $this->openLocalShift($this->terminalA, 500.0);
        $sale = $this->localSale($this->terminalA, 3);

        // (4) Counter B has no open shift → refused, although the sale's own shift S1 is open on Counter A.
        $this->assertRefused(fn () => $this->localReturn((int) $sale->id, $this->terminalB));
        // S1 closed → Counter A has none either → refused (the sale's shift is never reopened).
        $this->closeLocalShift($s1Id);
        $this->assertRefused(fn () => $this->localReturn((int) $sale->id, $this->terminalA));
        $this->assertSame(0, $this->asEdge(fn () => DB::connection('tenant')->table('sales_returns')->count()), 'nothing posted');
        $this->assertSame(0, $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('envelope_schema_version', EdgeReturnEnvelopeBuilder::SCHEMA)->count()), 'no event queued');
        $this->assertSame(0.0, (float) $this->localShift($s1Id)->total_refunds);
        $this->assertSame('closed', $this->localShift($s1Id)->status);

        // v1 LIMITATION — an appliance built before H1 returns the ONLINE sale: no processing shift, a numeric local
        // actor.shift_id that must never be used. The Cloud does not fail closed: attributed by COUNTER only.
        $cloudLineId = (int) $this->asCloud(fn () => DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $this->cloudSaleId)->where('product_id', $this->burgerId)->value('id'));
        $v1 = $this->handCrafted(EdgeReturnEnvelopeBuilder::SCHEMA_V1, $cloudLineId, actorShiftId: 7, processingShift: null);
        $res = $this->replay($v1);
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertSame('applied', $res->json('status'));
        $c = $this->cloudReturnRow($v1['return_uuid']);
        $this->assertNull($c->shift_id, 'no open Cloud shift on that counter → no shift (the v1 limitation; never the local numeric id 7)');
        $this->assertNull($c->processing_shift_uuid, 'v1 carries no identity to keep');
        $this->assertSame($this->terminalA, (int) $c->terminal_id, 'the counter is kept');
        $this->assertNotNull($c->business_date);
        $this->assertSame('cash', $c->refund_method);
        $this->assertSame(1, $this->cloudCounts()['cashbank_out'], 'the cash refund posted (it did leave a local drawer)');

        // … and with an OPEN Cloud shift on that counter, the v1 event is attributed to it by counter.
        $cloudShiftId = (int) $this->asCloud(fn () => DB::connection('tenant')->table('shifts')->insertGetId([
            'shift_uuid' => (string) Str::ulid(), 'branch_id' => $this->branchId, 'terminal_id' => $this->terminalA, 'opened_by_user_id' => $this->userId,
            'opening_cash' => 1000, 'total_sales' => 0, 'total_cash' => 0, 'total_refunds' => 0, 'total_cash_refunds' => 0, 'expected_cash' => 1000, 'status' => 'open',
            'business_date' => now()->toDateString(), 'timezone_name' => 'Asia/Karachi', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));
        $v1b = $this->handCrafted(EdgeReturnEnvelopeBuilder::SCHEMA_V1, $cloudLineId, actorShiftId: 7, processingShift: null);
        $this->assertSame('applied', $this->replay($v1b)->assertOk()->json('status'));
        $cb = $this->cloudReturnRow($v1b['return_uuid']);
        $this->assertSame($cloudShiftId, (int) $cb->shift_id);
        $this->assertSame($this->cloudShift($cloudShiftId)->shift_uuid, $cb->processing_shift_uuid, 'the resolved shift\'s own identity is recorded');
        $this->assertSame(100.0, (float) $this->cloudShift($cloudShiftId)->total_cash_refunds);
        $this->assertSame(900.0, (float) $this->cloudShift($cloudShiftId)->expected_cash);

        // v2 FAIL CLOSED: a post-H1 event refunding CASH with no processing shift is refused with the shared message.
        $v2 = $this->handCrafted(EdgeReturnEnvelopeBuilder::SCHEMA, $cloudLineId, actorShiftId: null, processingShift: null);
        $res = $this->replay($v2);
        $this->assertSame(422, $res->getStatusCode(), $res->getContent());
        $this->assertSame('RETURN_REFUSED', $res->json('failure_code'));
        $this->assertSame(SalesReturnService::CASH_REFUND_NEEDS_SHIFT, $res->json('message'));
        $this->assertSame(2, $this->cloudCounts()['returns'], 'the refused v2 event posted nothing');
        $this->assertSame(100.0, (float) $this->cloudShift($cloudShiftId)->total_cash_refunds, 'and moved no drawer');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────────────

    /** An ONLINE sale as the Cloud tables hold it: 3 Burger @100, paid cash 300. */
    private function insertCloudSale(): int
    {
        $conn = DB::connection('tenant');
        $saleId = $conn->table('sales_orders')->insertGetId([
            'sale_no' => 'SO-ONLINE-' . Str::random(6), 'branch_id' => $this->branchId, 'terminal_id' => $this->terminalA, 'order_source' => 'pos', 'order_type' => 'takeaway',
            'sale_date' => now()->subHours(2), 'business_date' => now()->toDateString(), 'subtotal' => 300, 'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0,
            'tax_amount' => 0, 'service_charge_amount' => 0, 'delivery_charge_amount' => 0, 'tip_amount' => 0, 'grand_total' => 300, 'paid_amount' => 300, 'change_amount' => 0,
            'status' => 'paid', 'inventory_posted' => 1, 'completed_at' => now()->subHours(2), 'created_by_user_id' => $this->userId, 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2),
        ]);
        $conn->table('sales_order_lines')->insert([
            'sales_order_id' => $saleId, 'line_kind' => 'standard', 'product_id' => $this->burgerId, 'product_name' => 'Burger', 'unit_code' => 'pc', 'quantity' => 3, 'returned_quantity' => 0,
            'unit_price' => 100, 'unit_cost' => 40, 'cost_total' => 120, 'discount_amount' => 0, 'tax_amount' => 0, 'line_total' => 300, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payment = new \App\Models\Tenant\SalePayment(['sales_order_id' => $saleId, 'payment_method_id' => $this->cashMethodId, 'amount' => 300, 'tendered_amount' => 300, 'change_amount' => 0]);
        $payment->payment_uuid = (string) Str::ulid();
        $payment->save();

        return (int) $saleId;
    }

    private function tick(): array
    {
        return $this->asEdge(fn () => app()->make(EdgeAuthorityTick::class)->run('test-worker'));
    }

    private function goLocal(): void
    {
        $this->bridgeFailures = ['authority/heartbeat' => 'down', 'sync/returns' => 'down', 'sync/sales' => 'down', 'sync/reconcile' => 'down', 'returnable/refresh' => 'down', 'sync/baseline' => 'down'];
        for ($i = 0; $i < 4; $i++) {
            $this->tick();
        }
        Carbon::setTestNow(now()->addSeconds(71));
        $this->assertSame('preparing_local', $this->tick()['state']['state']);
        $this->asEdge(fn () => app(EdgeAuthorityService::class)->takeOver(true, 'supervisor'));
        $this->assertSame('local_active', $this->asEdge(fn () => app(EdgeAuthorityService::class)->state()));
    }

    /** Drain the outbox over a few ticks (sales first, then returns — in creation order) until nothing is pending. */
    private function drain(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $t = $this->tick();
            $pending = (int) $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('state', '!=', EdgeSyncOutbox::STATE_ACKNOWLEDGED)->count());
            if ($pending === 0) {
                return;
            }
        }
        $this->fail('the outbox did not drain: ' . json_encode($t['work'] ?? null) . ' / ' . json_encode($this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->get(['sale_uuid', 'envelope_schema_version', 'state', 'last_error'])->all())));
    }

    private function openLocalShift(int $terminalId, float $openingCash): int
    {
        return (int) $this->asEdge(fn () => app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($terminalId), $this->userId, $openingCash)->id);
    }

    /** The shared close (EdgeLocalShiftController@closeShift → ShiftService::closeShift), counting exactly what is expected. */
    private function closeLocalShift(int $shiftId): object
    {
        return $this->asEdge(function () use ($shiftId) {
            $shift = Shift::on('tenant')->findOrFail($shiftId);
            app(ShiftService::class)->closeShift($shift, $this->userId, (float) $shift->expected_cash);

            return DB::connection('tenant')->table('shifts')->find($shiftId);
        });
    }

    private function localShift(int $shiftId): object
    {
        return $this->asEdge(fn () => DB::connection('tenant')->table('shifts')->find($shiftId));
    }

    private function cloudShift(int $shiftId): object
    {
        return $this->asCloud(fn () => DB::connection('tenant')->table('shifts')->find($shiftId));
    }

    /** A LOCAL cash sale of $qty Burger on the given counter (needs that counter's open shift), as the sales_orders row. */
    private function localSale(int $terminalId, int $qty): object
    {
        return $this->asEdge(function () use ($terminalId, $qty) {
            $user = User::on('tenant')->find($this->userId);
            Auth::guard('tenant')->setUser($user);
            Auth::shouldUse('tenant');
            $sale = app(EdgeLocalPosService::class)->completePaidSale(['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(), 'lines' => [['product_id' => $this->burgerId, 'quantity' => $qty]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100 * $qty]]], $user, $terminalId);

            return DB::connection('tenant')->table('sales_orders')->find($sale->id);
        });
    }

    /** One Burger back for cash, by the cashier on the given counter. */
    private function localReturn(int $saleId, int $terminalId): array
    {
        return $this->asEdge(function () use ($saleId, $terminalId) {
            $lineId = (int) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->value('id');

            return app(EdgeLocalReturnService::class)->processReturn($saleId, [['sales_order_line_id' => $lineId, 'quantity' => 1]], 'h1', 'cash', 100.0, User::on('tenant')->find($this->userId), $terminalId);
        });
    }

    private function assertRefused(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('a cash refund with no open shift on the selected terminal must be refused');
        } catch (ValidationException $e) {
            $this->assertSame(SalesReturnService::CASH_REFUND_NEEDS_SHIFT, $e->errors()['shift'][0] ?? null, json_encode($e->errors()));
        }
    }

    private function localReturnRow(string $returnUuid): object
    {
        return $this->asEdge(fn () => DB::connection('tenant')->table('sales_returns')->where('edge_return_uuid', $returnUuid)->first());
    }

    private function envelopeOf(string $returnUuid): array
    {
        return json_decode((string) $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $returnUuid)->value('envelope')), true);
    }

    private function cloudReturnRow(string $returnUuid): ?object
    {
        return $this->asCloud(function () use ($returnUuid) {
            $id = DB::connection('tenant')->table('edge_inbound_return_ingestions')->where('return_uuid', $returnUuid)->where('status', 'applied')->value('sales_return_id');

            return $id ? DB::connection('tenant')->table('sales_returns')->find($id) : null;
        });
    }

    private function cloudCounts(): array
    {
        return $this->asCloud(fn () => [
            'returns' => DB::connection('tenant')->table('sales_returns')->count(),
            'return_lines' => DB::connection('tenant')->table('sales_return_lines')->count(),
            'cashbank_out' => DB::connection('tenant')->table('cash_bank_account_transactions')->where('transaction_type', 'sales_return_refund')->count(),
            'ledger' => DB::connection('tenant')->table('sales_ledgers')->where('entry_type', 'sale_return')->count(),
            'registry_applied' => DB::connection('tenant')->table('edge_inbound_return_ingestions')->where('status', 'applied')->count(),
            'shift_refunds_total' => (float) DB::connection('tenant')->table('shifts')->sum('total_refunds'),
            'till_balance' => (float) DB::connection('tenant')->table('cash_bank_accounts')->where('code', 'TILL')->value('current_balance'),
        ]);
    }

    /** A FUTURE Edge-shift mirror: the Cloud holds a shifts row with the local shift's stable identity. */
    private function mirrorShiftOnCloud(object $localShift): int
    {
        return (int) $this->asCloud(fn () => DB::connection('tenant')->table('shifts')->insertGetId([
            'shift_uuid' => $localShift->shift_uuid, 'branch_id' => $this->branchId, 'terminal_id' => (int) $localShift->terminal_id, 'opened_by_user_id' => $this->userId,
            'opening_cash' => $localShift->opening_cash, 'total_sales' => 0, 'total_cash' => 0, 'total_refunds' => 0, 'total_cash_refunds' => 0,
            'expected_cash' => $localShift->opening_cash, 'status' => 'open', 'business_date' => substr((string) $localShift->business_date, 0, 10),
            'timezone_name' => $localShift->timezone_name, 'opened_at' => $localShift->opened_at, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    /** POST an envelope straight to the Cloud's return ingestion as the paired device. */
    private function replay(array $envelope): \Illuminate\Testing\TestResponse
    {
        $headers = ['X-Edge-Device-ID' => $this->cloudDeviceUuid, 'Authorization' => 'Bearer ' . $this->cloudDeviceSecret];

        return $this->asCloud(fn () => $this->postJson('http://' . config('tenancy.central_domain') . '/api/edge/sync/returns', ['envelope' => $envelope], $headers));
    }

    /** A hand-built return event (1 Burger of the ONLINE sale, cash 100) in the given schema generation. */
    private function handCrafted(string $schema, int $cloudLineId, ?int $actorShiftId, ?array $processingShift): array
    {
        $actor = ['user_id' => $this->userId, 'employee_code' => null, 'terminal_id' => $this->terminalA];
        if ($schema === EdgeReturnEnvelopeBuilder::SCHEMA_V1) {
            $actor['shift_id'] = $actorShiftId;   // the pre-H1 numeric LOCAL id — the Cloud must never use it
        }
        $envelope = [
            'envelope_schema_version' => $schema, 'event_type' => 'sales_return', 'return_uuid' => (string) Str::ulid(),
            'tenant_id' => $this->cloudTenantId, 'tenant_code' => $this->bridgeTenantCode, 'branch_id' => $this->branchId, 'device_public_uuid' => $this->cloudDeviceUuid,
            'activation_epoch' => 1, 'config_revision' => 1,
            'original' => ['kind' => 'cloud', 'cloud_sales_order_id' => $this->cloudSaleId, 'sale_uuid' => null, 'sale_no' => 'SO-ONLINE'],
            'return_no' => 'SR-H1', 'return_date' => now()->toIso8601String(), 'business_date' => now()->toDateString(), 'reason' => 'h1',
            'refund_method' => 'cash', 'refund_amount' => 100.0,
            'lines' => [['return_line_uuid' => (string) Str::ulid(), 'cloud_sales_order_line_id' => $cloudLineId, 'line_uuid' => null, 'product_id' => $this->burgerId, 'product_variant_id' => null,
                'quantity' => 1.0, 'unit_code' => 'pc', 'unit_price' => 100.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'line_total' => 100.0]],
            'totals' => ['subtotal' => 100.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'delivery_charge_amount' => 0.0, 'grand_total' => 100.0],
            'approval' => null, 'actor' => $actor,
            'freshness' => ['returnable_cache_watermark' => null, 'returnable_cache_as_of' => null], 'created_at' => now()->toIso8601String(),
        ];
        if ($schema !== EdgeReturnEnvelopeBuilder::SCHEMA_V1) {
            $envelope['processing_shift'] = $processingShift;
        }
        $envelope['content_hash'] = hash('sha256', app(EdgeBootstrapService::class)->canonicalJson($envelope));

        return $envelope;
    }
}
