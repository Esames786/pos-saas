<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Product;
use App\Services\Edge\BranchOperatingModeService;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeAuthorityTick;
use App\Services\Edge\EdgeBaselineIssuanceService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeCloudBridgeFixture;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * Q — WARM STANDBY FRESHNESS, proven end to end with real code on both ends and TWO databases (see
 * EdgeCloudBridgeFixture).
 *
 * The owner's expectation: Cloud stock 100 → online sale −5 → 95 → online sale −3 → 92; the WAN breaks; the appliance
 * takes over from a provably current 92 — not the morning's 100 — with a freshness watermark recorded at takeover. No
 * dual writes: the standby only PULLS from the Cloud's official position while the Cloud is the writer. When freshness
 * cannot be proven (a refresh failed, config moved) the takeover FAILS CLOSED unless a supervisor accepts it with an
 * audited reason.
 */
class EdgeStandbyFreshnessHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeCloudBridgeFixture;

    private const CONFIG_TABLES = ['branches', 'users', 'categories', 'products', 'terminals', 'payment_methods'];

    private int $branchId;
    private int $otherBranchId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        $this->provisionTwoDatabases();

        // ── the Cloud's truth ──
        $this->cleanTenant(['edge_branch_authority_leases', 'stock_ledgers', 'stock_balances', 'inventory_batches', 'payment_methods', 'products', 'categories', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['name' => 'Fresh Branch', 'allow_negative_stock' => 0]);
        $this->otherBranchId = $this->makeBranch(['name' => 'Other Branch']);
        $user = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'FR' . Str::random(4)]);
        $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['name' => 'Widget', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'default_selling_price' => 100]);
        $this->makePaymentMethod(['method_type' => 'cash']);
        $conn = DB::connection('tenant');
        $batchId = $conn->table('inventory_batches')->insertGetId(['batch_key' => "b-{$this->branchId}-{$this->productId}", 'branch_id' => $this->branchId, 'product_id' => $this->productId, 'batch_no' => 'B1', 'received_date' => now()->toDateString(), 'unit_cost' => 40, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('stock_balances')->insert(['balance_key' => "{$this->branchId}-{$this->productId}-0-{$batchId}", 'branch_id' => $this->branchId, 'product_id' => $this->productId, 'inventory_batch_id' => $batchId, 'quantity_on_hand' => 100, 'average_cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
        $this->registerCloudTenantAndDevice($this->branchId, 1);

        // ── the appliance: mirrored config, bound, credentialed, no baseline yet ──
        $this->mirrorConfigToAppliance(self::CONFIG_TABLES);
        $this->asEdge(function () use ($user) {
            $this->cleanTenant(['edge_local_connection_transitions', 'edge_baseline_cutovers', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_user_credentials', 'edge_local_meta']);
            $this->bindEdgeLocalMeta($this->branchId, 1, $this->cloudTenantId, $this->cloudDeviceUuid, 1);
            DB::connection('tenant')->table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'tenant_code' => $this->bridgeTenantCode]);
            $this->seedEdgeCredential($user, $this->branchId, 1);
        });
        $this->configureApplianceCloudUrls();
        config([
            'edge.authority.ttl_seconds' => 60, 'edge.authority.skew_margin_seconds' => 10,
            'edge.authority.unstable_after_failures' => 2, 'edge.authority.lost_after_failures' => 4,
        ]);
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

    private function authority(): EdgeAuthorityService
    {
        return app()->make(EdgeAuthorityService::class);
    }

    private function acceptedBaseline(): ?object
    {
        return $this->asEdge(fn () => DB::connection('tenant')->table('edge_operational_stock_baselines')->where('status', 'accepted')->first());
    }

    private function operationalOnHand(): float
    {
        return $this->asEdge(function () {
            $b = DB::connection('tenant')->table('edge_operational_stock_baselines')->where('status', 'accepted')->first();

            return $b ? (float) DB::connection('tenant')->table('edge_operational_stock_balances')->where('baseline_id', $b->id)->where('product_id', $this->productId)->sum('quantity_on_hand') : -1.0;
        });
    }

    private function cloudWatermark(): string
    {
        return $this->asCloud(fn () => app(EdgeBaselineIssuanceService::class)->stockWatermark($this->branchId)['stock_watermark']);
    }

    /** An ONLINE sale posted by the Cloud through the official inventory path (FEFO out). */
    private function cloudSells(float $qty): void
    {
        $this->asCloud(function () use ($qty) {
            app(InventoryService::class)->postOutFefo(Branch::on('tenant')->find($this->branchId), Product::on('tenant')->find($this->productId), null, $qty, 'sale', 'test', null, 'ONLINE-' . $qty);
        });
    }

    private function cloudFenced(int $branchId): bool
    {
        return $this->asCloud(function () use ($branchId) {
            try {
                app(BranchOperatingModeService::class)->assertSaleMutationAllowed(Branch::on('tenant')->find($branchId));

                return false;
            } catch (\App\Exceptions\BranchLocalEdgeException $e) {
                return true;
            }
        });
    }

    private function edgeMeta(): object
    {
        return $this->asEdge(fn () => DB::connection('tenant')->table('edge_local_meta')->first());
    }

    public function test_the_standby_follows_cloud_stock_and_the_takeover_starts_from_the_current_position(): void
    {
        // ONLINE, warm standby, no baseline yet: the first acknowledged heartbeat advertises the Cloud position (100) and
        // the worker pulls the INITIAL baseline from official stock — into the appliance's own database.
        $r = $this->tick();
        $this->assertTrue($r['heartbeat']['ok'], 'heartbeat: ' . json_encode($r['heartbeat']));
        $this->assertSame('online', $r['state']['state']);
        $this->assertSame('standby_freshness', $r['work']['kind']);
        $this->assertSame('refreshed:initial', $r['work']['stock'], json_encode($r['work']));
        $this->assertSame(100.0, $this->operationalOnHand());
        $this->assertSame($this->cloudWatermark(), $this->acceptedBaseline()->stock_watermark, 'the standby equals the Cloud position');
        $this->assertTrue($this->asEdge(fn () => $this->authority()->freshness()['ok']));

        // Two ONLINE sales on the Cloud: 100 → 95 → 92. The next heartbeat advertises the moved watermark; the standby
        // refreshes at the SAME config revision (a new generation, audited as a standby refresh).
        $this->cloudSells(5);
        $this->cloudSells(3);
        $this->assertNotSame($this->cloudWatermark(), $this->acceptedBaseline()->stock_watermark, 'the standby is behind until the worker runs');
        $r = $this->tick();
        $this->assertSame('refreshed:standby', $r['work']['stock'], json_encode($r['work']));
        $this->assertSame(92.0, $this->operationalOnHand(), 'the standby now sells from 92, not the morning 100');
        $this->assertSame($this->cloudWatermark(), $this->acceptedBaseline()->stock_watermark);
        $this->assertSame('standby_refresh', $this->acceptedBaseline()->freshness_kind);
        $this->assertSame(2, (int) $this->acceptedBaseline()->generation);
        $this->assertSame(1, $this->asEdge(fn () => DB::connection('tenant')->table('edge_baseline_cutovers')->where('reason', 'like', 'standby refresh%')->count()));
        $r = $this->tick();
        $this->assertSame('current', $r['work']['stock'], 'nothing moved → nothing pulled');

        // THE WAN BREAKS. Failures are recorded, never acted on: ONLINE (blip) → UNSTABLE → UNSTABLE → LOST.
        $this->bridgeFailures = ['authority/heartbeat' => 'down'];
        $seen = [];
        for ($i = 0; $i < 4; $i++) {
            $r = $this->tick();
            $this->assertFalse($r['heartbeat']['ok']);
            $this->assertSame('none', $r['work']['kind'], 'no refresh is attempted while the Cloud is unreachable');
            $seen[] = $r['state']['state'];
        }
        $this->assertSame(['online', 'connection_unstable', 'connection_unstable', 'connection_lost'], $seen);
        $this->assertSame('standby', $this->asEdge(fn () => $this->authority()->state()));
        $this->assertFalse($this->cloudFenced($this->branchId), 'inside the lease the Cloud still writes');

        // The lease lapses (TTL 60 + skew 10 on the appliance clock; the Cloud's own TTL fences it first).
        Carbon::setTestNow(now()->addSeconds(71));
        $r = $this->tick();
        $this->assertSame('preparing_local', $r['state']['state']);
        $this->assertTrue($this->cloudFenced($this->branchId), 'the Cloud fenced itself at lease expiry');
        $this->assertFalse($this->cloudFenced($this->otherBranchId), 'another branch of the same tenant is untouched');
        $gates = $this->asEdge(fn () => $this->authority()->gates());
        $this->assertSame([], array_keys(array_filter($gates, fn ($ok) => ! $ok)), 'every gate incl. STANDBY_FRESH_ENOUGH passes: ' . json_encode($gates));

        // Supervised takeover: local selling starts from 92, and the freshness proof is recorded.
        $res = $this->asEdge(fn () => $this->authority()->takeOver(true, 'supervisor'));
        $this->assertFalse($res['stale_accepted']);
        $this->assertSame('local_active', $this->asEdge(fn () => $this->authority()->state()));
        $this->assertSame(92.0, $this->operationalOnHand());
        $proof = json_decode((string) $this->edgeMeta()->authority_takeover_freshness, true);
        $this->assertTrue($proof['fresh']);
        $this->assertSame($this->acceptedBaseline()->stock_watermark, $proof['stock_watermark_accepted']);
        $this->assertSame($proof['stock_watermark_advertised'], $proof['stock_watermark_accepted']);
        $this->assertSame(1, $proof['config_revision_applied']);
        $this->assertSame(1, $proof['config_revision_advertised']);
        $this->asEdge(fn () => $this->authority()->assertLocalMutationAllowed());
    }

    public function test_a_stock_refresh_that_failed_before_the_cut_makes_the_takeover_fail_closed(): void
    {
        $this->tick(); // initial baseline at 100
        $this->cloudSells(8); // 92 on the Cloud
        // The Cloud advertises the new position but the baseline pull fails (flaky WAN) — the standby stays at 100.
        $this->bridgeFailures = ['sync/baseline' => 'error'];
        $r = $this->tick();
        $this->assertTrue($r['heartbeat']['ok'], json_encode($r['heartbeat']));
        $this->assertStringStartsWith('error:', $r['work']['stock']);
        $this->assertSame(100.0, $this->operationalOnHand());
        $f = $this->asEdge(fn () => $this->authority()->freshness());
        $this->assertFalse($f['ok']);
        $this->assertFalse($f['stock_ok']);

        // Then the WAN dies completely and the lease lapses.
        $this->bridgeFailures = ['authority/heartbeat' => 'down', 'sync/baseline' => 'down'];
        for ($i = 0; $i < 4; $i++) {
            $this->tick();
        }
        Carbon::setTestNow(now()->addSeconds(71));
        $this->assertSame('preparing_local', $this->tick()['state']['state']);
        $gates = $this->asEdge(fn () => $this->authority()->gates());
        $this->assertTrue($gates['AUTHORITY_TAKEOVER_SAFE']);
        $this->assertFalse($gates['STANDBY_FRESH_ENOUGH']);
        try {
            $this->asEdge(fn () => $this->authority()->takeOver(true, 'supervisor'));
            $this->fail('an unprovable standby must fail closed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('STANDBY_FRESH_ENOUGH', $e->getMessage());
            $this->assertStringContainsString('does not equal the last advertised Cloud position', $e->getMessage());
        }
        $this->assertSame('standby', $this->asEdge(fn () => $this->authority()->state()));
        // Only a supervisor's audited decision proceeds.
        $res = $this->asEdge(fn () => $this->authority()->takeOver(true, 'supervisor', true, 'shelf counted by the manager: 92 confirmed'));
        $this->assertTrue($res['stale_accepted']);
        $proof = json_decode((string) $this->edgeMeta()->authority_takeover_freshness, true);
        $this->assertFalse($proof['fresh']);
        $this->assertTrue($proof['stale_accepted']);
        $this->assertSame('shelf counted by the manager: 92 confirmed', $proof['stale_reason']);
    }

    public function test_a_config_change_on_the_cloud_is_pulled_by_the_standby_and_stale_config_refuses_takeover(): void
    {
        $this->tick(); // revision 1 applied == advertised; baseline at revision 1
        // The Cloud changes the product (config watermark moves → revision 2). The config refresh endpoint is down.
        $this->asCloud(fn () => DB::connection('tenant')->table('products')->where('id', $this->productId)->update(['name' => 'Widget Deluxe', 'default_selling_price' => 120, 'updated_at' => now()->addSecond()]));
        $this->bridgeFailures = ['config/refresh' => 'error'];
        $r = $this->tick();
        $this->assertTrue($r['heartbeat']['ok'], json_encode($r['heartbeat']));
        $this->assertStringStartsWith('error:', $r['work']['config'], json_encode($r['work']));
        $f = $this->asEdge(fn () => $this->authority()->freshness());
        $this->assertFalse($f['config_ok']);
        $this->assertSame(2, $f['facts']['config_revision_advertised']);
        $this->assertSame(1, $f['facts']['config_revision_applied']);
        $this->assertSame(100.0, $this->asEdge(fn () => (float) DB::connection('tenant')->table('products')->where('id', $this->productId)->value('default_selling_price')), 'the appliance still has the old price');

        // The endpoint recovers: the same tick pulls the config (real applier, into the appliance DB) and then cuts the
        // baseline over to the new revision — the standby is fresh again.
        $this->bridgeFailures = [];
        $r = $this->tick();
        $this->assertSame('refreshed:1->2', $r['work']['config'], json_encode($r['work']));
        $this->assertSame('refreshed:cutover', $r['work']['stock'], json_encode($r['work']));
        $meta = $this->edgeMeta();
        $this->assertSame(2, (int) $meta->last_applied_config_revision);
        $this->assertSame(120.0, $this->asEdge(fn () => (float) DB::connection('tenant')->table('products')->where('id', $this->productId)->value('default_selling_price')), 'the price the cashier will charge offline is the Cloud price');
        $this->assertSame('Widget Deluxe', $this->asEdge(fn () => (string) DB::connection('tenant')->table('products')->where('id', $this->productId)->value('name')));
        $this->assertSame((string) $meta->source_revision, (string) $this->acceptedBaseline()->source_revision);
        $this->assertTrue($this->asEdge(fn () => $this->authority()->freshness()['ok']));
    }
}
