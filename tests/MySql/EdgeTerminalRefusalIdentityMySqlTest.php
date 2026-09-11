<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeAuthorityTick;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeHandbackOrchestrator;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeReturnEnvelopeBuilder;
use App\Services\Edge\EdgeSyncOutboxService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeCloudBridgeFixture;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * POST-F2 RELIABILITY CLOSURE — TERMINAL REFUSAL ACK IDENTITY across the SALE and SALES-RETURN ingestion families
 * (supplier finance already proved it in F2), over the real device-authenticated transport bridged into the Cloud:
 *
 *   same event uuid + hash, terminal business refusal  → the Cloud answers `refused` naming the envelope → the
 *                                                        appliance parks the row as failed_permanent and never retries it
 *   retryable transport / server failure (5xx)         → stays retryable (released, attempts counted)
 *   applied truth + lost ACK                           → still the reconciliation path (recovered once, nothing re-posted)
 *   a verdict about a DIFFERENT envelope               → never decides this row (EdgeSyncSenderMySqlTest)
 *
 * APPLIED / already_applied semantics are unchanged.
 */
class EdgeTerminalRefusalIdentityMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeCloudBridgeFixture;

    private const CONFIG_TABLES = ['branches', 'users', 'categories', 'units', 'products', 'terminals', 'payment_methods'];

    private int $branchId;
    private int $userId;
    private int $terminalId;
    private int $burgerId;
    private int $ghostId;      // exists ONLY on the appliance — the Cloud can never resolve it
    private int $cashMethodId;

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
        $this->branchId = $this->makeBranch(['name' => 'Verdict Branch', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'TV' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $conn = DB::connection('tenant');
        $pc = $conn->table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $cat = $this->makeCategory();
        $this->burgerId = $this->makeProduct($cat, ['name' => 'Burger', 'unit_id' => $pc, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $accountId = $conn->table('accounts')->where('code', '1000')->value('id') ?? $conn->table('accounts')->value('id');
        $cbId = $conn->table('cash_bank_accounts')->insertGetId(['code' => 'TILL', 'name' => 'Till', 'account_type' => 'cash', 'account_id' => $accountId, 'current_balance' => 0, 'is_active' => 1, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('payment_methods')->where('id', $this->cashMethodId)->update(['cash_bank_account_id' => $cbId]);
        $batchId = $conn->table('inventory_batches')->insertGetId(['batch_key' => "b-{$this->branchId}-{$this->burgerId}", 'branch_id' => $this->branchId, 'product_id' => $this->burgerId, 'batch_no' => 'B1', 'received_date' => now()->toDateString(), 'unit_cost' => 40, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('stock_balances')->insert(['balance_key' => "{$this->branchId}-{$this->burgerId}-0-{$batchId}", 'branch_id' => $this->branchId, 'product_id' => $this->burgerId, 'inventory_batch_id' => $batchId, 'quantity_on_hand' => 100, 'average_cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
        $this->registerCloudTenantAndDevice($this->branchId, 1);

        // ── the appliance ──
        $this->mirrorConfigToAppliance(self::CONFIG_TABLES);
        $this->asEdge(function () use ($cat, $pc) {
            $this->cleanTenant([
                'edge_returnable_sale_lines', 'edge_returnable_sales', 'edge_local_connection_transitions', 'edge_baseline_cutovers', 'edge_sync_outbox',
                'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_user_credentials', 'edge_local_meta',
                'sales_return_lines', 'sales_returns', 'sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts', 'manager_approvals',
            ]);
            $this->bindEdgeLocalMeta($this->branchId, 1, $this->cloudTenantId, $this->cloudDeviceUuid, 1);
            DB::connection('tenant')->table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'tenant_code' => $this->bridgeTenantCode]);
            $this->seedEdgeCredential($this->userId, $this->branchId, 1);
            // A product the Cloud does not know (never mirrored the other way): a sale of it can never be accepted.
            $this->ghostId = $this->makeProduct($cat, ['name' => 'Ghost Service', 'unit_id' => $pc, 'inventory_consumption_method' => 'none', 'is_stock_tracked' => 0, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
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

    private function tick(): array
    {
        return $this->asEdge(fn () => app()->make(EdgeAuthorityTick::class)->run('test-worker'));
    }

    private function goLocal(): void
    {
        $this->bridgeFailures = ['authority/heartbeat' => 'down', 'sync/returns' => 'down', 'sync/sales' => 'down', 'sync/supplier-finance' => 'down', 'sync/reconcile' => 'down',
            'returnable/refresh' => 'down', 'supplier-finance/refresh' => 'down', 'sync/baseline' => 'down', 'config/refresh' => 'down'];
        for ($i = 0; $i < 4; $i++) {
            $this->tick();
        }
        Carbon::setTestNow(now()->addSeconds(71));
        $this->assertSame('preparing_local', $this->tick()['state']['state']);
        $this->asEdge(fn () => app(EdgeAuthorityService::class)->takeOver(true, 'supervisor'));
        $this->assertSame('local_active', $this->asEdge(fn () => app(EdgeAuthorityService::class)->state()));
        $this->asEdge(fn () => app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->userId, 500.0));
    }

    private function localSale(int $productId, int $qty, float $amount): string
    {
        return (string) $this->asEdge(function () use ($productId, $qty, $amount) {
            $user = User::on('tenant')->find($this->userId);
            Auth::guard('tenant')->setUser($user);
            Auth::shouldUse('tenant');
            $sale = app(EdgeLocalPosService::class)->completePaidSale(['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(), 'lines' => [['product_id' => $productId, 'quantity' => $qty]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => $amount]]], $user, $this->terminalId);

            return $sale->sale_uuid;
        });
    }

    /** A return event that names a Cloud sale which does not exist — the Cloud can never accept it (ORIGINAL_SALE_UNKNOWN). */
    private function queueOrphanReturnEvent(): string
    {
        return (string) $this->asEdge(function () {
            $uuid = (string) Str::ulid();
            $envelope = [
                'envelope_schema_version' => EdgeReturnEnvelopeBuilder::SCHEMA, 'event_type' => 'sales_return', 'return_uuid' => $uuid,
                'tenant_id' => $this->cloudTenantId, 'tenant_code' => $this->bridgeTenantCode, 'branch_id' => $this->branchId, 'device_public_uuid' => $this->cloudDeviceUuid,
                'activation_epoch' => 1, 'config_revision' => 1,
                'original' => ['kind' => 'cloud', 'cloud_sales_order_id' => 999999, 'sale_uuid' => null, 'sale_no' => 'SO-GONE'],
                'return_no' => 'SR-X', 'return_date' => now()->toIso8601String(), 'business_date' => now()->toDateString(), 'reason' => 'orphan',
                'refund_method' => 'cash', 'refund_amount' => 100.0,
                'lines' => [['return_line_uuid' => (string) Str::ulid(), 'cloud_sales_order_line_id' => 999999, 'line_uuid' => null, 'product_id' => $this->burgerId, 'product_variant_id' => null,
                    'quantity' => 1.0, 'unit_code' => 'pc', 'unit_price' => 100.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'line_total' => 100.0]],
                'totals' => ['subtotal' => 100.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'delivery_charge_amount' => 0.0, 'grand_total' => 100.0],
                'approval' => null, 'actor' => ['user_id' => $this->userId, 'employee_code' => null, 'terminal_id' => $this->terminalId, 'shift_id' => null],
                'freshness' => ['returnable_cache_watermark' => null, 'returnable_cache_as_of' => null], 'created_at' => now()->toIso8601String(),
            ];
            $envelope['content_hash'] = hash('sha256', app(EdgeBootstrapService::class)->canonicalJson($envelope));
            app(EdgeSyncOutboxService::class)->createForReturn($envelope);

            return $uuid;
        });
    }

    private function row(string $uuid): object
    {
        return $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $uuid)->first());
    }

    public function test_terminal_sale_and_return_refusals_are_parked_as_failed_permanent_with_the_envelope_identity(): void
    {
        $this->assertSame('refreshed:initial', $this->tick()['work']['stock']);
        $this->goLocal();
        $ghostSale = $this->localSale($this->ghostId, 1, 50.0);     // the Cloud cannot resolve the product → PRODUCT_UNRESOLVED (terminal)
        $goodSale = $this->localSale($this->burgerId, 2, 200.0);    // a perfectly good sale
        $orphanReturn = $this->queueOrphanReturnEvent();           // ORIGINAL_SALE_UNKNOWN (terminal)
        $this->assertSame(['pending', 'pending', 'pending'], [$this->row($ghostSale)->state, $this->row($goodSale)->state, $this->row($orphanReturn)->state]);

        // WAN back: one drain tick classifies every verdict from the envelope identity the Cloud echoes.
        $this->bridgeFailures = [];
        $t = $this->tick();
        $this->assertSame(2, (int) ($t['work']['outcomes']['terminal'] ?? 0), json_encode($t['work']));
        $this->assertSame(1, (int) ($t['work']['outcomes']['acknowledged'] ?? 0), json_encode($t['work']));
        $ghost = $this->row($ghostSale);
        $good = $this->row($goodSale);
        $orphan = $this->row($orphanReturn);
        $this->assertSame('failed_permanent', $ghost->state, 'SALE_TERMINAL_REFUSAL → failed_permanent');
        $this->assertStringContainsString('PRODUCT_UNRESOLVED', (string) $ghost->last_error);
        $this->assertSame('acknowledged', $good->state, 'APPLIED semantics unchanged');
        $this->assertSame('failed_permanent', $orphan->state, 'RETURN_TERMINAL_REFUSAL → failed_permanent');
        $this->assertStringContainsString('ORIGINAL_SALE_UNKNOWN', (string) $orphan->last_error);

        // The Cloud registry holds the refusals WITH the envelope identity, and posted nothing for them.
        $this->asCloud(function () use ($ghostSale, $goodSale, $orphanReturn, $ghost, $orphan) {
            $c = DB::connection('tenant');
            $saleRow = $c->table('edge_inbound_sale_ingestions')->where('sale_uuid', $ghostSale)->first();
            $this->assertSame('refused', $saleRow->status);
            $this->assertSame('PRODUCT_UNRESOLVED', $saleRow->failure_code);
            $this->assertSame($ghost->content_hash, json_decode((string) $saleRow->ack_payload, true)['content_hash'], 'the refusal names the envelope it answers');
            $this->assertSame(1, $c->table('sales_orders')->count(), 'only the good sale exists on the Cloud');
            $this->assertSame($goodSale, $c->table('sales_orders')->value('sale_uuid'));
            $retRow = $c->table('edge_inbound_return_ingestions')->where('return_uuid', $orphanReturn)->first();
            $this->assertSame('refused', $retRow->status);
            $this->assertSame('ORIGINAL_SALE_UNKNOWN', $retRow->failure_code);
            $this->assertSame($orphan->content_hash, json_decode((string) $retRow->ack_payload, true)['content_hash']);
            $this->assertSame(0, $c->table('sales_returns')->count());
        });

        // Parked rows are never re-sent: a later tick finds nothing to send and changes nothing.
        $attempts = [(int) $ghost->attempts, (int) $orphan->attempts];
        $t2 = $this->tick();
        $this->assertSame(1, (int) ($t2['work']['outcomes']['idle'] ?? 0), json_encode($t2['work']));
        $this->assertSame($attempts, [(int) $this->row($ghostSale)->attempts, (int) $this->row($orphanReturn)->attempts]);
        $this->assertSame('failed_permanent', $this->row($ghostSale)->state);

        // A permanent failure is a supervisor matter: it blocks the controlled handback explicitly.
        $codes = array_column($this->asEdge(fn () => app(EdgeHandbackOrchestrator::class)->assess())['blockers'], 'code');
        $this->assertContains('PERMANENT_SYNC_FAILURE', $codes, json_encode($codes));
        $this->assertNotContains('OUTBOX_PENDING', $codes, 'nothing is pending — the verdicts were terminal, not retried');
    }

    public function test_retryable_failures_stay_retryable_and_a_lost_ack_is_still_recovered_by_reconciliation(): void
    {
        $this->tick();
        $this->goLocal();
        $sale = $this->localSale($this->burgerId, 1, 100.0);

        // A Cloud 500 (server failure) is transient: released for a bounded retry, never parked, never acknowledged.
        $this->bridgeFailures = ['sync/sales' => 'error'];
        $t = $this->tick();
        $this->assertSame(1, (int) ($t['work']['outcomes']['retry'] ?? 0), json_encode($t['work']));
        $row = $this->row($sale);
        $this->assertSame('pending', $row->state);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertStringContainsString('HTTP 500', (string) $row->last_error);
        $this->assertSame(0, $this->asCloud(fn () => DB::connection('tenant')->table('sales_orders')->count()));

        // Healthy: applied exactly once, acknowledged.
        $this->bridgeFailures = [];
        $this->tick();
        $this->assertSame('acknowledged', $this->row($sale)->state);
        $this->assertSame(1, $this->asCloud(fn () => DB::connection('tenant')->table('sales_orders')->count()));

        // LOST ACK: the row goes back to pending while the sale wire is down → reconciliation recovers the ACK, nothing re-posted.
        $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $sale)->update(['state' => EdgeSyncOutbox::STATE_PENDING, 'acknowledged_at' => null, 'ack_ingestion_uuid' => null, 'ack_payload' => null]));
        $this->bridgeFailures = ['sync/sales' => 'down'];
        $t2 = $this->tick();
        $this->assertSame(1, (int) ($t2['work']['findings']['recovered_lost_ack'] ?? 0), json_encode($t2['work']));
        $this->assertSame('acknowledged', $this->row($sale)->state);
        $this->assertSame(1, $this->asCloud(fn () => DB::connection('tenant')->table('sales_orders')->count()), 'ONE sale');
        $this->assertSame(1, $this->asCloud(fn () => DB::connection('tenant')->table('edge_inbound_sale_ingestions')->where('status', 'applied')->count()));
    }
}
