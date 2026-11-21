<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\BranchOperatingModeService;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeAuthorityTick;
use App\Services\Edge\EdgeBaselineIssuanceService;
use App\Services\Edge\EdgeConnectionStateMachine;
use App\Services\Edge\EdgeHandbackOrchestrator;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeCloudBridgeFixture;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * Q — RECONNECT + CONTROLLED HANDBACK, real code on both ends, TWO databases (see EdgeCloudBridgeFixture).
 *
 * WAN back ⇒ the appliance REMAINS the writer while it drains, reconciles and waits for a stable connection. Handback
 * is refused with EXPLICIT reasons while anything is pending, permanently failed, or while tables / checks / shifts are
 * still open on the appliance (never silently discarded). A lost ACK is recovered exactly once without a repost. Only
 * a clean state hands back through the proven P protocol; a failed handback leaves BOTH sides fenced (a gap, never an
 * overlap) until it is retried.
 */
class EdgeHandbackOrchestratorHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeCloudBridgeFixture;

    private const CONFIG_TABLES = ['branches', 'users', 'categories', 'products', 'terminals', 'payment_methods'];

    private int $branchId;
    private int $userId;
    private int $terminalId;
    private int $productId;
    private int $cashMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        $this->provisionTwoDatabases();

        // ── the Cloud's truth ──
        $this->cleanTenant([
            'edge_branch_authority_leases', 'edge_inbound_sale_ingestions', 'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'accounts', 'cash_bank_accounts', 'stock_ledgers', 'stock_balances', 'inventory_batches', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'products', 'categories', 'terminals', 'branches', 'users',
        ]);
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        $this->branchId = $this->makeBranch(['name' => 'Handback Branch', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'HB' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['name' => 'Widget', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $conn = DB::connection('tenant');
        $batchId = $conn->table('inventory_batches')->insertGetId(['batch_key' => "b-{$this->branchId}-{$this->productId}", 'branch_id' => $this->branchId, 'product_id' => $this->productId, 'batch_no' => 'B1', 'received_date' => now()->toDateString(), 'unit_cost' => 40, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('stock_balances')->insert(['balance_key' => "{$this->branchId}-{$this->productId}-0-{$batchId}", 'branch_id' => $this->branchId, 'product_id' => $this->productId, 'inventory_batch_id' => $batchId, 'quantity_on_hand' => 100, 'average_cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
        $this->registerCloudTenantAndDevice($this->branchId, 1);

        // ── the appliance: mirrored config, bound, credentialed ──
        $this->mirrorConfigToAppliance(self::CONFIG_TABLES);
        $this->asEdge(function () {
            $this->cleanTenant([
                'edge_local_connection_transitions', 'edge_baseline_cutovers', 'edge_sync_outbox', 'edge_local_table_reservations',
                'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_user_credentials', 'edge_local_meta',
                'sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders', 'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'shifts',
            ]);
            $this->bindEdgeLocalMeta($this->branchId, 1, $this->cloudTenantId, $this->cloudDeviceUuid, 1);
            DB::connection('tenant')->table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'tenant_code' => $this->bridgeTenantCode]);
            $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        });
        $this->configureApplianceCloudUrls();
        config(['edge.authority.ttl_seconds' => 60, 'edge.authority.skew_margin_seconds' => 10, 'edge.authority.handback_min_consecutive_acks' => 2]);
        $this->bridgeCloud();

        // ONLINE first (initial baseline pulled), then the appliance is the LOCAL writer (the supervised takeover is
        // proven in the freshness/state-machine suites; here it is the starting point) and the Cloud learns it.
        $first = $this->tick();
        $this->assertSame('refreshed:initial', $first['work']['stock'] ?? null, 'setup: ' . json_encode($first));
        $this->asEdge(fn () => EdgeLocalMeta::on('tenant')->firstOrFail()->forceFill(['authority_state' => 'local_active', 'authority_takeover_at' => now(), 'heartbeat_consecutive_acks' => 0])->save());
    }

    protected function tearDown(): void
    {
        $this->cleanupCloudRegistration();
        $this->useDb($this->cloudDb);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function tick(): array
    {
        return $this->asEdge(fn () => app()->make(EdgeAuthorityTick::class)->run('test-worker'));
    }

    private function orchestrator(): EdgeHandbackOrchestrator
    {
        return app()->make(EdgeHandbackOrchestrator::class);
    }

    private function blockerCodes(): array
    {
        return array_column($this->asEdge(fn () => $this->orchestrator()->assess())['blockers'], 'code');
    }

    private function edgeState(): string
    {
        return $this->asEdge(fn () => app(EdgeAuthorityService::class)->state());
    }

    private function edgeMeta(): object
    {
        return $this->asEdge(fn () => DB::connection('tenant')->table('edge_local_meta')->first());
    }

    private function outbox(): ?object
    {
        return $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->first());
    }

    private function cloudFenced(): bool
    {
        return $this->asCloud(function () {
            try {
                app(BranchOperatingModeService::class)->assertSaleMutationAllowed(Branch::on('tenant')->find($this->branchId));

                return false;
            } catch (\App\Exceptions\BranchLocalEdgeException $e) {
                return true;
            }
        });
    }

    private function localWriteAllowed(): bool
    {
        return $this->asEdge(function () {
            try {
                app(EdgeAuthorityService::class)->assertLocalMutationAllowed();

                return true;
            } catch (\RuntimeException $e) {
                return false;
            }
        });
    }

    private function cloudOnHand(): float
    {
        return $this->asCloud(fn () => (float) DB::connection('tenant')->table('stock_balances')->where('branch_id', $this->branchId)->where('product_id', $this->productId)->sum('quantity_on_hand'));
    }

    private function cloudIngestions(): int
    {
        return $this->asCloud(fn () => DB::connection('tenant')->table('edge_inbound_sale_ingestions')->count());
    }

    /** Reconnect: two acknowledged heartbeats while local → the Cloud reports holder edge; the appliance stays the writer. */
    private function reconnectStable(): void
    {
        $first = $this->tick();
        $this->assertTrue($first['heartbeat']['ok'], 'heartbeat: ' . json_encode($first['heartbeat']));
        $this->assertSame('edge', $first['heartbeat']['holder'], 'the Cloud records the appliance as the lease holder');
        $this->assertSame('connection_restored', $first['state']['state']);
        $this->assertTrue($this->localWriteAllowed(), 'reconnect never switches the writer');
        $this->assertTrue($this->cloudFenced(), 'the Cloud branch stays fenced');
        $second = $this->tick();
        $this->assertContains($second['state']['state'], ['syncing', 'reconciling']);
    }

    public function test_open_tables_held_checks_and_open_shifts_block_handback_explicitly(): void
    {
        $this->reconnectStable();
        $this->assertSame([], $this->blockerCodes(), 'nothing open, nothing pending: ready — ' . json_encode($this->asEdge(fn () => $this->orchestrator()->assess())));

        // Active operational state on the appliance.
        [$heldId, $shiftId] = $this->asEdge(function () {
            $tableId = $this->makeTable($this->branchId);
            DB::connection('tenant')->table('restaurant_table_sessions')->insert(['session_no' => 'S-' . Str::random(6), 'branch_id' => $this->branchId, 'restaurant_table_id' => $tableId, 'opened_by_user_id' => $this->userId, 'guest_count' => 2, 'status' => 'open', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $heldId = $this->makeSale($this->branchId, ['status' => 'held', 'terminal_id' => $this->terminalId]);
            $shift = app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->userId, 0.0);

            return [$heldId, (int) $shift->id];
        });

        $codes = $this->blockerCodes();
        $this->assertContains('OPEN_TABLES', $codes);
        $this->assertContains('HELD_CHECKS', $codes);
        $this->assertContains('OPEN_SHIFTS', $codes);

        $r = $this->asEdge(fn () => $this->orchestrator()->run('supervisor'));
        $this->assertSame(EdgeHandbackOrchestrator::BLOCKED, $r['status']);
        $this->assertSame('local_active', $this->edgeState(), 'nothing changed: the appliance still owns the branch');
        $this->assertTrue($this->localWriteAllowed(), 'the cashier can still settle and close — the only way the blockers clear');
        $this->assertTrue($this->cloudFenced());
        $this->assertStringContainsString('OPEN_TABLES', (string) $this->edgeMeta()->handback_blocked_reason);
        $this->assertSame(1, $this->asEdge(fn () => DB::connection('tenant')->table('restaurant_table_sessions')->where('status', 'open')->count()), 'the open table was NOT discarded');
        $this->assertSame('held', $this->asEdge(fn () => DB::connection('tenant')->table('sales_orders')->where('id', $heldId)->value('status')), 'the held check was NOT discarded');

        // The operator resolves them on the appliance; the assessment clears.
        $this->asEdge(function () use ($heldId, $shiftId) {
            DB::connection('tenant')->table('restaurant_table_sessions')->update(['status' => 'closed', 'closed_at' => now()]);
            DB::connection('tenant')->table('sales_orders')->where('id', $heldId)->update(['status' => 'cancelled']);
            DB::connection('tenant')->table('shifts')->where('id', $shiftId)->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $this->userId]);
        });
        $this->assertSame([], $this->blockerCodes());
    }

    public function test_unsynced_or_permanently_failed_sales_block_handback(): void
    {
        $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->insert([
            'sale_uuid' => (string) Str::ulid(), 'config_revision' => 1, 'activation_epoch' => 1,
            'envelope_schema_version' => 'edge-sale-envelope-v99', 'content_hash' => str_repeat('0', 64), 'envelope' => json_encode(['nonsense' => true]), 'state' => EdgeSyncOutbox::STATE_PENDING,
            'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertContains('OUTBOX_PENDING', $this->blockerCodes());
        $this->assertContains('CONNECTION_NOT_STABLE', $this->blockerCodes(), 'no acknowledged heartbeat yet');

        $this->reconnectStable(); // the worker drains: the Cloud refuses this envelope terminally (or keeps it pending)
        $state = (string) $this->outbox()->state;
        $this->assertContains($state, [EdgeSyncOutbox::STATE_FAILED_PERMANENT, EdgeSyncOutbox::STATE_PENDING], "state {$state}");
        $codes = $this->blockerCodes();
        $this->assertNotEmpty(array_intersect(['PERMANENT_SYNC_FAILURE', 'OUTBOX_PENDING'], $codes), json_encode($codes));
        $r = $this->asEdge(fn () => $this->orchestrator()->run('supervisor'));
        $this->assertSame(EdgeHandbackOrchestrator::BLOCKED, $r['status']);
        $this->assertTrue($this->cloudFenced());
        $this->assertTrue($this->localWriteAllowed());
    }

    public function test_lost_ack_recovers_exactly_once_then_a_clean_sync_hands_back_in_order(): void
    {
        // A REAL offline sale while local: open shift → complete a paid takeaway → outbox row (appliance DB only).
        $this->asEdge(function () {
            app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->userId, 0.0);
            $user = User::on('tenant')->find($this->userId);
            Auth::guard('tenant')->setUser($user);
            Auth::shouldUse('tenant');
            app(EdgeLocalPosService::class)->completePaidSale([
                'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
                'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
                'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 200]],
            ], $user, $this->terminalId);
        });
        $this->assertSame(EdgeSyncOutbox::STATE_PENDING, $this->outbox()->state);
        $this->assertSame(0, $this->cloudIngestions());
        $this->assertSame(100.0, $this->cloudOnHand(), 'the Cloud knows nothing yet');

        // WAN back: the worker drains through the REAL sender into the REAL Cloud ingestion (official stock 100 → 98).
        $first = $this->tick();
        $this->assertSame('connection_restored', $first['state']['state']);
        $this->assertSame(1, $first['work']['drained'], json_encode($first['work']) . ' outbox: ' . json_encode($this->outbox()));
        $this->assertSame(EdgeSyncOutbox::STATE_ACKNOWLEDGED, $this->outbox()->state);
        $this->assertSame(1, $this->cloudIngestions());
        $this->assertSame(98.0, $this->cloudOnHand());

        // LOST ACK: the appliance never saw the acknowledgement (row back to pending) and the ingestion wire is flaky.
        $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->update(['state' => EdgeSyncOutbox::STATE_PENDING, 'acknowledged_at' => null, 'ack_ingestion_uuid' => null, 'ack_payload' => null, 'lease_owner' => null, 'lease_expires_at' => null]));
        $this->bridgeFailures = ['sync/sales' => 'down'];
        $second = $this->tick();
        $this->assertTrue($second['heartbeat']['ok']);
        $this->assertSame(1, (int) ($second['work']['findings']['recovered_lost_ack'] ?? 0), 'reconciliation recovered the lost ACK: ' . json_encode($second['work']));
        $this->assertSame(EdgeSyncOutbox::STATE_ACKNOWLEDGED, $this->outbox()->state);
        $this->assertSame(1, $this->cloudIngestions(), 'no duplicate ingestion');
        $this->assertSame(98.0, $this->cloudOnHand(), 'no double stock posting');
        $this->assertTrue($second['work']['clean']);
        $this->assertSame('reconciling', $second['state']['state']);
        $this->assertTrue($second['state']['handback_ready']);
        $this->bridgeFailures = [];

        // The shift is still open → explicit blocker; the operator closes it on the appliance.
        $this->assertSame(['OPEN_SHIFTS'], $this->blockerCodes());
        $this->asEdge(fn () => DB::connection('tenant')->table('shifts')->where('branch_id', $this->branchId)->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $this->userId]));
        $this->assertSame([], $this->blockerCodes());

        // CONTROLLED HANDBACK: fence locally → Cloud acknowledges → standby → Cloud POS re-enabled → warm standby resumes
        // from the Cloud position that now INCLUDES the offline sale (98).
        $this->assertTrue($this->cloudFenced());
        $r = $this->asEdge(fn () => $this->orchestrator()->run('supervisor'));
        $this->assertSame(EdgeHandbackOrchestrator::HANDED_BACK, $r['status'], json_encode($r));
        $this->assertSame('cloud', $r['cloud']['holder']);
        $this->assertSame('standby', $this->edgeState());
        $this->assertFalse($this->cloudFenced(), 'the Cloud POS writes the branch again');
        $this->assertFalse($this->localWriteAllowed(), 'the appliance is a standby again');
        $this->assertSame('online', $r['state']['state']);
        $this->assertStringStartsWith('refreshed:', $r['freshness']['stock'], json_encode($r['freshness']));
        $baseline = $this->asEdge(fn () => DB::connection('tenant')->table('edge_operational_stock_baselines')->where('status', 'accepted')->first());
        $cloudWatermark = $this->asCloud(fn () => app(EdgeBaselineIssuanceService::class)->stockWatermark($this->branchId)['stock_watermark']);
        $this->assertSame($cloudWatermark, $baseline->stock_watermark, 'the warm standby equals the Cloud position after handback');
        $this->assertSame(98.0, $this->asEdge(fn () => (float) DB::connection('tenant')->table('edge_operational_stock_balances')->where('baseline_id', $baseline->id)->sum('quantity_on_hand')));
        $trail = $this->asEdge(fn () => DB::connection('tenant')->table('edge_local_connection_transitions')->orderBy('id')->pluck('to_state')->all());
        $this->assertSame('online', end($trail));
        $this->assertContains('handing_back', $trail);
    }

    public function test_a_failed_handback_leaves_both_sides_fenced_until_retried(): void
    {
        $this->reconnectStable();
        $this->assertSame([], $this->blockerCodes());
        $this->bridgeFailures = ['authority/handback' => 'down'];
        $r = $this->asEdge(fn () => $this->orchestrator()->run('supervisor'));
        $this->assertSame(EdgeHandbackOrchestrator::FAILED, $r['status']);
        $this->assertSame('handing_back', $this->edgeState());
        $this->assertFalse($this->localWriteAllowed(), 'fenced locally');
        $this->assertTrue($this->cloudFenced(), 'and the Cloud is still fenced — a gap, never two writers');
        $this->assertSame('handing_back', $this->asEdge(fn () => app(EdgeConnectionStateMachine::class)->evaluate())['state']);

        $this->bridgeFailures = [];
        $r = $this->asEdge(fn () => $this->orchestrator()->run('supervisor'));
        $this->assertSame(EdgeHandbackOrchestrator::HANDED_BACK, $r['status'], json_encode($r));
        $this->assertSame('standby', $this->edgeState());
        $this->assertFalse($this->cloudFenced());
    }
}
