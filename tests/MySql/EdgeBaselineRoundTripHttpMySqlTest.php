<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\SalesOrder;
use App\Services\Edge\EdgeBaselineClient;
use App\Services\Edge\EdgeBaselineCutoverService;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeOperationalBaselineService;
use App\Services\Edge\EdgeSyncReconciliationService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * PRODUCTIZATION GATE 0 — the full cutover round trip over the REAL authenticated boundary, plus the Cloud
 * baseline issuance authority and Edge-side package validation.
 *
 * Round trip: accepted baseline N -> local paid sale under N -> authenticated sync -> Cloud official FEFO/
 * GL -> local ACK (drain) -> config watermark N->N+1 -> selling fenced -> real Cloud baseline request ->
 * Cloud computes the POST-SALE official position -> package transported -> Edge validates + atomically
 * accepts -> baseline N+1 active -> selling resumes, at the exact expected quantity, with no split brain,
 * no dropped sale, and no duplicate official movement.
 */
class EdgeBaselineRoundTripHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const TENANT_CODE = 'edgebaseline';
    private const EPOCH = 2;
    private const REV_N = 'rev-N';
    private const REV_N1 = 'rev-N+1';

    private string $ingestUri;
    private string $reconcileUri;
    private string $baselineUri;
    private string $secret = 'baseline-device-secret';
    private int $tenantId;
    private string $deviceUuid;
    private int $branchId;
    private int $productId;
    private int $userId;
    private int $terminalId;
    private int $cashMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        $base = 'http://' . config('tenancy.central_domain') . '/api/edge/sync/';
        $this->ingestUri = $base . 'sales';
        $this->reconcileUri = $base . 'reconcile';
        $this->baselineUri = $base . 'baseline';
        $this->deviceUuid = (string) Str::uuid();

        $this->seedTenantRegistration();
        $this->seedTenantData();
        $this->seedDevice();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('edge_devices')->where('public_uuid', $this->deviceUuid)->delete();
            $m->table('edge_branch_activations')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
            $m->table('tenants')->where('tenant_code', self::TENANT_CODE)->delete();
        } catch (\Throwable $e) {
        }
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function seedTenantRegistration(): void
    {
        $m = DB::connection('master');
        $m->table('edge_devices')->where('public_uuid', $this->deviceUuid)->delete();
        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenants')->where('tenant_code', self::TENANT_CODE)->delete();

        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => self::TENANT_CODE, 'business_name' => 'Edge Baseline', 'owner_name' => 'Owner',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c = config('database.connections.tenant');
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant', 'db_host' => $c['host'], 'db_port' => (int) $c['port'],
            'db_database' => $this->tenantDb, 'db_username' => $c['username'], 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedTenantData(): void
    {
        DB::setDefaultConnection('tenant');
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        $this->cleanTenant([
            'edge_baseline_cutovers', 'edge_operational_stock_movements', 'edge_operational_stock_balances',
            'edge_operational_stock_baselines', 'edge_sync_outbox', 'edge_local_meta',
            'edge_inbound_sale_ingestions', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'accounts', 'cash_bank_accounts', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories',
            'terminals', 'branches', 'users',
        ]);
        (new DefaultChartOfAccountsSeeder())->run();

        $this->branchId = $this->makeBranch(['sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);

        $conn = DB::connection('tenant');
        $batchId = $conn->table('inventory_batches')->insertGetId(['batch_key' => "b-{$this->branchId}-{$this->productId}", 'branch_id' => $this->branchId, 'product_id' => $this->productId, 'batch_no' => 'B1', 'received_date' => now()->toDateString(), 'unit_cost' => 40, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('stock_balances')->insert(['balance_key' => "{$this->branchId}-{$this->productId}-0-{$batchId}", 'branch_id' => $this->branchId, 'product_id' => $this->productId, 'inventory_batch_id' => $batchId, 'quantity_on_hand' => 50, 'average_cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
        $accountId = $conn->table('accounts')->where('code', '1000')->value('id') ?? $conn->table('accounts')->value('id');
        $cbId = $conn->table('cash_bank_accounts')->insertGetId(['code' => 'TILL', 'name' => 'Till', 'account_type' => 'cash', 'account_id' => $accountId, 'current_balance' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('payment_methods')->where('id', $this->cashMethodId)->update(['cash_bank_account_id' => $cbId]);

        // Edge binding at revision N, and an accepted operational baseline N with the full official on-hand.
        // NB: the runtime role stays cloud/null here — the Cloud api/edge/* endpoints are fenced off on a
        // branch_server. Edge-side service calls are wrapped in asEdge() to flip the role only for them.
        $this->bindEdgeLocalMeta($this->branchId, self::EPOCH, tenantId: $this->tenantId, deviceUuid: $this->deviceUuid);
        DB::connection('tenant')->table('edge_local_meta')->update(['source_revision' => self::REV_N]);
        app()->forgetInstance(EdgeBranchContext::class);
        $this->seedAcceptedBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50]], self::REV_N);

        config(['app.role' => null]);
        DB::setDefaultConnection('master');
    }

    private function seedAcceptedBaseline(array $items, string $revision): int
    {
        $id = DB::connection('tenant')->table('edge_operational_stock_baselines')->insertGetId([
            'baseline_uuid' => (string) Str::ulid(), 'branch_id' => $this->branchId, 'device_uuid' => $this->deviceUuid,
            'activation_epoch' => self::EPOCH, 'generation' => 1, 'source_revision' => $revision,
            'content_hash' => EdgeOperationalBaselineService::canonicalHash($items), 'status' => 'accepted',
            'active_binding_key' => EdgeOperationalBaselineService::bindingKey($this->branchId, $this->deviceUuid, self::EPOCH),
            'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (EdgeOperationalBaselineService::canonicalizeItems($items) as $it) {
            DB::connection('tenant')->table('edge_operational_stock_balances')->insert([
                'balance_key' => $id . '-' . $it['product_id'] . '-' . ($it['product_variant_id'] ?: 0),
                'baseline_id' => $id, 'branch_id' => $this->branchId, 'product_id' => $it['product_id'],
                'product_variant_id' => $it['product_variant_id'], 'quantity_on_hand' => $it['quantity'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function seedDevice(): void
    {
        $m = DB::connection('master');
        $m->table('edge_devices')->insert([
            'public_uuid' => $this->deviceUuid, 'tenant_id' => $this->tenantId, 'branch_id' => $this->branchId,
            'installation_uuid' => (string) Str::uuid(), 'device_name' => 'baseline', 'device_secret_hash' => hash('sha256', $this->secret),
            'status' => 'active', 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('edge_branch_activations')->insert([
            'tenant_id' => $this->tenantId, 'branch_id' => $this->branchId, 'generation' => self::EPOCH,
            'device_public_uuid' => $this->deviceUuid, 'reason' => 'initial', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function envelope(array $overrides = []): array
    {
        $line = ['line_uuid' => (string) Str::ulid(), 'line_kind' => 'standard', 'product_id' => $this->productId, 'product_variant_id' => null, 'combo_id' => null, 'product_name' => 'Widget', 'quantity' => 2.0, 'unit_price' => 100.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'line_total' => 200.0, 'modifiers' => []];
        $payment = ['payment_uuid' => (string) Str::ulid(), 'payment_method_id' => $this->cashMethodId, 'method_type' => 'cash', 'amount' => 200.0, 'tendered_amount' => 200.0, 'change_amount' => 0.0, 'transaction_ref' => null, 'paid_at' => now()->toIso8601String()];
        $env = array_merge([
            'envelope_schema_version' => 'edge-sale-envelope-v1', 'tenant_id' => $this->tenantId, 'tenant_code' => self::TENANT_CODE,
            'branch_id' => $this->branchId, 'device_public_uuid' => $this->deviceUuid, 'activation_epoch' => self::EPOCH,
            'config_revision' => 5, 'config_schema_version' => 'edge-config-v1', 'sale_uuid' => (string) Str::ulid(),
            'sale_no' => 'SO-EDGE-' . Str::random(6), 'client_uuid' => (string) Str::uuid(), 'business_date' => now()->toDateString(),
            'sale_date' => now()->toIso8601String(), 'completed_at' => now()->toIso8601String(), 'created_at' => now()->toIso8601String(),
            'order_type' => 'takeaway', 'order_source' => 'pos', 'vehicle_number' => null, 'terminal_id' => $this->terminalId,
            'terminal_code' => 'T1', 'user_id' => $this->userId, 'employee_code' => 'E1', 'restaurant_waiter_id' => null,
            'shift' => ['shift_uuid' => (string) Str::ulid(), 'business_date' => now()->toDateString(), 'opened_at' => now()->toIso8601String(), 'terminal_id' => $this->terminalId, 'opened_by_user_id' => $this->userId],
            'table_session' => null, 'kot_events' => [], 'customer' => ['kind' => 'walk_in', 'name' => null, 'phone' => null],
            'totals' => ['subtotal' => 200.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'service_charge_amount' => 0.0, 'tip_amount' => 0.0, 'grand_total' => 200.0, 'paid_amount' => 200.0, 'change_amount' => 0.0],
            'lines' => [$line], 'payments' => [$payment],
            'operational_stock' => ['posted' => true, 'baseline_uuid' => (string) Str::ulid()],
            'local_state' => ['edge_sync_state' => 'pending', 'edge_activation_epoch' => self::EPOCH, 'inventory_posted' => false, 'is_draft' => false],
        ], $overrides);
        unset($env['content_hash']);
        $env['content_hash'] = hash('sha256', app(EdgeBootstrapService::class)->canonicalJson($env));

        return $env;
    }

    private function headers(?string $secret = null): array
    {
        return ['X-Edge-Device-ID' => $this->deviceUuid, 'Authorization' => 'Bearer ' . ($secret ?? $this->secret)];
    }

    private function cutover(): EdgeBaselineCutoverService
    {
        return app(EdgeBaselineCutoverService::class);
    }

    /** Run an Edge-side service call under the branch_server runtime, then restore cloud/null role for HTTP. */
    private function asEdge(callable $fn): mixed
    {
        config(['app.role' => 'branch_server']);
        app()->forgetInstance(EdgeBranchContext::class);
        try {
            return $fn();
        } finally {
            config(['app.role' => null]);
        }
    }

    // ── Cloud issuance from official stock ────────────────────────────────────────

    public function test_cloud_issues_a_baseline_from_official_stock_not_edge_provisional(): void
    {
        $res = $this->postJson($this->baselineUri, ['source_revision' => self::REV_N1, 'activation_epoch' => self::EPOCH], $this->headers());
        $res->assertStatus(200)->assertJsonPath('status', 'issued');

        $pkg = $res->json('package');
        $this->assertSame($this->branchId, $pkg['branch_id']);
        $this->assertSame(self::REV_N1, $pkg['source_revision']);
        // Official on-hand is 50 (no sale yet) — computed from stock_balances, not the operational baseline.
        $this->assertCount(1, $pkg['items']);
        $this->assertSame($this->productId, $pkg['items'][0]['product_id']);
        $this->assertEqualsWithDelta(50.0, (float) $pkg['items'][0]['quantity'], 0.001);
    }

    public function test_baseline_issuance_refuses_an_epoch_the_device_was_never_activated_for(): void
    {
        $this->postJson($this->baselineUri, ['source_revision' => self::REV_N1, 'activation_epoch' => 999], $this->headers())
            ->assertStatus(403)->assertJsonPath('failure_code', 'BASELINE_EPOCH_INVALID');
    }

    public function test_baseline_issuance_requires_authentication(): void
    {
        $this->postJson($this->baselineUri, ['source_revision' => self::REV_N1, 'activation_epoch' => self::EPOCH])->assertStatus(401);
    }

    // ── Edge-side package validation (fail closed) ───────────────────────────────

    public function test_edge_rejects_an_unknown_product_in_a_package(): void
    {
        $client = app(EdgeBaselineClient::class);
        $pkg = EdgeBaselineCutoverService::buildPackage($this->branchId, self::EPOCH, self::REV_N1, [['product_id' => 999999, 'product_variant_id' => null, 'quantity' => 5]]);
        $this->expectExceptionMessage('BASELINE_UNKNOWN_PRODUCT');
        $client->assertResolvable($pkg);
    }

    public function test_edge_rejects_an_impossible_quantity(): void
    {
        $client = app(EdgeBaselineClient::class);
        $pkg = EdgeBaselineCutoverService::buildPackage($this->branchId, self::EPOCH, self::REV_N1, [['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => -3]]);
        $this->expectExceptionMessage('BASELINE_IMPOSSIBLE_QUANTITY');
        $client->assertResolvable($pkg);
    }

    // ── the full round trip ──────────────────────────────────────────────────────

    public function test_full_cutover_round_trip_resumes_selling_at_the_exact_post_sale_quantity(): void
    {
        // 1-2. accepted baseline N already seeded (qty 50). A local paid sale of 2 under N.
        $env = $this->envelope();
        EdgeSyncOutbox::on('tenant')->create([
            'sale_uuid' => $env['sale_uuid'], 'envelope_schema_version' => 'edge-sale-envelope-v1',
            'config_revision' => 5, 'activation_epoch' => self::EPOCH, 'envelope' => json_encode($env),
            'content_hash' => $env['content_hash'], 'state' => EdgeSyncOutbox::STATE_PENDING,
        ]);

        // 3-5. authenticated sync -> Cloud official sale + FEFO (50 -> 48) + GL.
        $sync = $this->postJson($this->ingestUri, ['envelope' => $env], $this->headers());
        $sync->assertStatus(201)->assertJsonPath('status', 'applied');
        $this->assertSame(1, SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->count());

        // 6. local ACK (drain) — recover through the reconciliation authority from Cloud's own ACK, no repost.
        $recon = $this->postJson($this->reconcileUri, ['sale_uuids' => [$env['sale_uuid']]], $this->headers());
        DB::setDefaultConnection('tenant');
        app(EdgeSyncReconciliationService::class)->recoverLostAck($env['sale_uuid'], $recon->json('statuses.' . $env['sale_uuid']));
        $this->assertSame(EdgeSyncOutbox::STATE_ACKNOWLEDGED, EdgeSyncOutbox::on('tenant')->where('sale_uuid', $env['sale_uuid'])->value('state'));

        // 7-8. config watermark moves N -> N+1; selling fences (the accepted baseline is now stale).
        DB::connection('tenant')->table('edge_local_meta')->update(['source_revision' => self::REV_N1]);
        [$status, $currentAccepted] = $this->asEdge(fn () => [
            $this->cutover()->status(),
            app(EdgeOperationalBaselineService::class)->currentAccepted(),
        ]);
        $this->assertSame(EdgeBaselineCutoverService::STATE_CUTOVER_REQUIRED, $status['state']);
        $this->assertTrue($status['selling_fenced']);
        $this->assertTrue($status['drain']['drained'], 'the prior generation is fully acknowledged');
        $this->assertNull($currentAccepted);

        // 9-11. real Cloud baseline request at the new watermark -> Cloud computes the POST-SALE official position.
        DB::setDefaultConnection('master');
        $issued = $this->postJson($this->baselineUri, ['source_revision' => self::REV_N1, 'activation_epoch' => self::EPOCH], $this->headers());
        $issued->assertStatus(200);
        $package = $issued->json('package');
        $this->assertEqualsWithDelta(48.0, (float) $package['items'][0]['quantity'], 0.001, 'Cloud position accounts for the ingested sale');

        // 12-14. Edge validates + atomically accepts -> baseline N+1 active -> selling resumes.
        DB::setDefaultConnection('tenant');
        app(EdgeBaselineClient::class)->assertResolvable($package);
        $newBaseline = $this->asEdge(fn () => $this->cutover()->acceptCutover($package, 'supervisor:roundtrip', 'go-live cutover after drain'));

        $this->assertSame('accepted', $newBaseline->status);
        $this->assertSame(self::REV_N1, $newBaseline->source_revision);
        $this->assertSame(2, (int) $newBaseline->generation);

        // exactly one accepted baseline; selling resumes at the exact post-sale quantity 48.
        $accepted = DB::connection('tenant')->table('edge_operational_stock_baselines')->where('branch_id', $this->branchId)->where('status', 'accepted')->get();
        $this->assertCount(1, $accepted, 'no split brain — exactly one accepted baseline');
        $qty = DB::connection('tenant')->table('edge_operational_stock_balances')->where('baseline_id', $newBaseline->id)->where('product_id', $this->productId)->value('quantity_on_hand');
        $this->assertEqualsWithDelta(48.0, (float) $qty, 0.001);
        $this->assertSame(EdgeBaselineCutoverService::STATE_SELLING, $this->asEdge(fn () => $this->cutover()->status())['state']);

        // no dropped sale, no duplicate official movement.
        $this->assertSame(1, SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->count());
        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order_paid')->count());
        $this->assertSame(1, DB::connection('tenant')->table('edge_baseline_cutovers')->count());
    }
}
