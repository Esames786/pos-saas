<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Services\Edge\EdgeBaselineCutoverService;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeOperationalBaselineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE-SYNC-ENGINE-1E — controlled operational-baseline CUTOVER.
 *
 * Proves the §V matrix: a config-watermark move puts the binding into CUTOVER_REQUIRED and fences selling;
 * an un-drained outbox (pending / leased / failed_permanent) blocks the cutover; a fully-acknowledged outbox
 * lets it proceed; a tampered / wrong-branch / wrong-epoch / wrong-revision package is refused; acceptance is
 * atomic (old superseded, new accepted, balances swapped, one audit row, watermark advanced exactly once);
 * a replay of the same package is idempotent; the same baseline identity with a different payload conflicts;
 * a failure mid-acceptance rolls back entirely (old baseline stays accepted, selling stays fenced); and
 * accepting a baseline locally produces NO Cloud GL or stock movement.
 */
class EdgeBaselineCutoverMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId = 7;
    private string $device = 'test-device-uuid';
    private int $epoch = 1;
    private string $oldRev = 'rev-N';
    private string $newRev = 'rev-N+1';

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_baseline_cutovers', 'edge_operational_stock_movements', 'edge_operational_stock_balances',
            'edge_operational_stock_baselines', 'edge_sync_outbox', 'edge_local_meta',
            'stock_ledgers', 'stock_balances', 'journal_lines', 'journal_entries',
        ]);
        config(['app.role' => 'branch_server']);
        // Bind the appliance, then move the imported revision to the NEW watermark (a config refresh happened).
        $this->bindEdgeLocalMeta($this->branchId, $this->epoch, deviceUuid: $this->device);
        DB::connection('tenant')->table('edge_local_meta')->update(['source_revision' => $this->newRev]);
        app()->forgetInstance(EdgeBranchContext::class);
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function svc(): EdgeBaselineCutoverService
    {
        return app(EdgeBaselineCutoverService::class);
    }

    /** Seed a STALE accepted baseline (prior revision) + its selling balances, as accept() would have. */
    private function seedStaleAcceptedBaseline(array $items = [['product_id' => 100, 'product_variant_id' => null, 'quantity' => 20]], int $generation = 1): int
    {
        $id = DB::connection('tenant')->table('edge_operational_stock_baselines')->insertGetId([
            'baseline_uuid' => (string) Str::ulid(),
            'branch_id' => $this->branchId, 'device_uuid' => $this->device, 'activation_epoch' => $this->epoch,
            'generation' => $generation, 'source_revision' => $this->oldRev,
            'content_hash' => EdgeOperationalBaselineService::canonicalHash($items),
            'status' => 'accepted',
            'active_binding_key' => EdgeOperationalBaselineService::bindingKey($this->branchId, $this->device, $this->epoch),
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

    private function seedOutbox(string $state): void
    {
        $u = (string) Str::ulid();
        $json = json_encode(['sale_uuid' => $u]);
        EdgeSyncOutbox::create([
            'sale_uuid' => $u, 'envelope_schema_version' => 'edge-sale-envelope-v1',
            'config_revision' => 5, 'activation_epoch' => 1, 'envelope' => $json,
            'content_hash' => hash('sha256', $json), 'state' => $state,
        ]);
    }

    private function package(array $overrides = [], array $items = [['product_id' => 100, 'product_variant_id' => null, 'quantity' => 12]]): array
    {
        return array_merge(
            EdgeBaselineCutoverService::buildPackage($this->branchId, $this->epoch, $this->newRev, $items, ['as_of' => now()->toIso8601String(), 'hash' => 'cloudpos-1']),
            $overrides
        );
    }

    // ── state machine + fence ────────────────────────────────────────────────────

    public function test_a_config_watermark_move_puts_the_binding_in_cutover_required_and_fences_selling(): void
    {
        $this->seedStaleAcceptedBaseline();
        $status = $this->svc()->status();

        $this->assertSame(EdgeBaselineCutoverService::STATE_CUTOVER_REQUIRED, $status['state']);
        $this->assertSame($this->newRev, $status['current_revision']);
        $this->assertSame($this->oldRev, $status['baseline_revision']);
        $this->assertTrue($status['selling_fenced']);
        // With an empty outbox the prior generation is already drained -> cutover is ready.
        $this->assertTrue($status['cutover_ready']);
        // The stale baseline no longer authorizes selling.
        $this->assertNull(app(EdgeOperationalBaselineService::class)->currentAccepted());
    }

    // ── drain rule ───────────────────────────────────────────────────────────────

    public function test_a_pending_sale_blocks_the_cutover(): void
    {
        $this->seedStaleAcceptedBaseline();
        $this->seedOutbox(EdgeSyncOutbox::STATE_PENDING);

        $this->assertFalse($this->svc()->status()['cutover_ready']);
        $this->expectExceptionMessage('CUTOVER_NOT_DRAINED');
        $this->svc()->acceptCutover($this->package(), 'supervisor:test');
    }

    public function test_a_failed_permanent_sale_blocks_the_cutover(): void
    {
        $this->seedStaleAcceptedBaseline();
        $this->seedOutbox(EdgeSyncOutbox::STATE_FAILED_PERMANENT);

        $this->expectExceptionMessage('CUTOVER_NOT_DRAINED');
        $this->svc()->acceptCutover($this->package(), 'supervisor:test');
    }

    public function test_an_acknowledged_outbox_does_not_block_the_cutover(): void
    {
        $old = $this->seedStaleAcceptedBaseline();
        $this->seedOutbox(EdgeSyncOutbox::STATE_ACKNOWLEDGED);

        $new = $this->svc()->acceptCutover($this->package(), 'supervisor:test', 'drained + reconciled');
        $this->assertSame('accepted', $new->status);
        $this->assertSame($this->newRev, $new->source_revision);
        $this->assertSame('superseded', (string) DB::connection('tenant')->table('edge_operational_stock_baselines')->where('id', $old)->value('status'));
    }

    // ── package integrity / binding ──────────────────────────────────────────────

    public function test_a_tampered_package_is_rejected(): void
    {
        $this->seedStaleAcceptedBaseline();
        $pkg = $this->package();
        $pkg['content_hash'] = str_repeat('f', 64); // does not match the items
        $this->expectExceptionMessage('CUTOVER_INTEGRITY');
        $this->svc()->acceptCutover($pkg, 'supervisor:test');
    }

    public function test_a_wrong_branch_package_is_rejected(): void
    {
        $this->seedStaleAcceptedBaseline();
        $this->expectExceptionMessage('CUTOVER_WRONG_BRANCH');
        $this->svc()->acceptCutover($this->package(['branch_id' => 999]), 'supervisor:test');
    }

    public function test_a_wrong_epoch_package_is_rejected(): void
    {
        $this->seedStaleAcceptedBaseline();
        $this->expectExceptionMessage('CUTOVER_WRONG_EPOCH');
        $this->svc()->acceptCutover($this->package(['activation_epoch' => 999]), 'supervisor:test');
    }

    public function test_a_wrong_revision_package_is_rejected(): void
    {
        $this->seedStaleAcceptedBaseline();
        $this->expectExceptionMessage('CUTOVER_REVISION_MISMATCH');
        $this->svc()->acceptCutover($this->package(['source_revision' => 'some-other-rev']), 'supervisor:test');
    }

    // ── atomic acceptance + audit ─────────────────────────────────────────────────

    public function test_acceptance_is_atomic_advances_the_watermark_and_swaps_balances(): void
    {
        $old = $this->seedStaleAcceptedBaseline([['product_id' => 100, 'product_variant_id' => null, 'quantity' => 20]]);
        $pkg = $this->package(items: [['product_id' => 100, 'product_variant_id' => null, 'quantity' => 12], ['product_id' => 200, 'product_variant_id' => null, 'quantity' => 5]]);

        $new = $this->svc()->acceptCutover($pkg, 'supervisor:alice', 'go-live cutover');

        // exactly one accepted baseline for the binding, at the new revision, generation advanced.
        $accepted = DB::connection('tenant')->table('edge_operational_stock_baselines')
            ->where('branch_id', $this->branchId)->where('status', 'accepted')->get();
        $this->assertCount(1, $accepted);
        $this->assertSame($this->newRev, (string) $accepted->first()->source_revision);
        $this->assertSame(2, (int) $accepted->first()->generation);

        // old superseded, active_binding_key freed, its balances cleared; new balances present.
        $oldRow = DB::connection('tenant')->table('edge_operational_stock_baselines')->where('id', $old)->first();
        $this->assertSame('superseded', $oldRow->status);
        $this->assertNull($oldRow->active_binding_key);
        $this->assertNotNull($oldRow->superseded_at);
        $this->assertSame(0, DB::connection('tenant')->table('edge_operational_stock_balances')->where('baseline_id', $old)->count());
        $this->assertSame(2, DB::connection('tenant')->table('edge_operational_stock_balances')->where('baseline_id', $new->id)->count());

        // one immutable audit row with old + new lineage.
        $audit = DB::connection('tenant')->table('edge_baseline_cutovers')->get();
        $this->assertCount(1, $audit);
        $this->assertSame($old, (int) $audit->first()->old_baseline_id);
        $this->assertSame((int) $new->id, (int) $audit->first()->new_baseline_id);
        $this->assertSame('supervisor:alice', $audit->first()->performed_by);
        $this->assertSame('cloudpos-1', $audit->first()->cloud_position_hash);

        // selling resumes: the new baseline authorizes the current revision.
        $this->assertSame(EdgeBaselineCutoverService::STATE_SELLING, $this->svc()->status()['state']);
        $this->assertNotNull(app(EdgeOperationalBaselineService::class)->currentAccepted());
    }

    public function test_replaying_the_same_package_is_idempotent(): void
    {
        $this->seedStaleAcceptedBaseline();
        $pkg = $this->package();

        $first = $this->svc()->acceptCutover($pkg, 'supervisor:test');
        $second = $this->svc()->acceptCutover($pkg, 'supervisor:test');

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame(1, DB::connection('tenant')->table('edge_baseline_cutovers')->count(), 'a replay writes no second audit row');
        $this->assertSame(1, DB::connection('tenant')->table('edge_operational_stock_baselines')->where('status', 'accepted')->count());
    }

    public function test_the_same_baseline_identity_with_a_different_payload_conflicts(): void
    {
        $this->seedStaleAcceptedBaseline();
        $pkg = $this->package();
        $this->svc()->acceptCutover($pkg, 'supervisor:test');

        // same baseline_uuid, different items -> different canonical hash -> conflict.
        $tampered = $this->package(['baseline_uuid' => $pkg['baseline_uuid']], items: [['product_id' => 100, 'product_variant_id' => null, 'quantity' => 999]]);
        $this->expectExceptionMessage('CUTOVER_CONFLICT');
        $this->svc()->acceptCutover($tampered, 'supervisor:test');
    }

    public function test_a_failure_mid_acceptance_rolls_back_and_keeps_the_old_baseline_and_fence(): void
    {
        $old = $this->seedStaleAcceptedBaseline();
        $pkg = $this->package();

        // Force a mid-transaction failure AFTER supersede+delete: a foreign row already owns this baseline_uuid
        // (globally unique), so the new-baseline insert violates the unique index and the whole tx rolls back.
        DB::connection('tenant')->table('edge_operational_stock_baselines')->insert([
            'baseline_uuid' => $pkg['baseline_uuid'],
            'branch_id' => 4242, 'device_uuid' => 'other-device', 'activation_epoch' => 9,
            'generation' => 1, 'source_revision' => 'x', 'content_hash' => str_repeat('0', 64),
            'status' => 'superseded', 'active_binding_key' => null,
            'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->svc()->acceptCutover($pkg, 'supervisor:test');
            $this->fail('the duplicate baseline_uuid must abort the cutover');
        } catch (\Throwable $e) {
            // expected — a unique violation
        }

        // The old baseline is intact and still accepted; its balances survived; selling stays fenced; no audit.
        $oldRow = DB::connection('tenant')->table('edge_operational_stock_baselines')->where('id', $old)->first();
        $this->assertSame('accepted', $oldRow->status);
        $this->assertNotNull($oldRow->active_binding_key);
        $this->assertNull($oldRow->superseded_at);
        $this->assertSame(1, DB::connection('tenant')->table('edge_operational_stock_balances')->where('baseline_id', $old)->count());
        $this->assertSame(0, DB::connection('tenant')->table('edge_baseline_cutovers')->count());
        $this->assertSame(EdgeBaselineCutoverService::STATE_CUTOVER_REQUIRED, $this->svc()->status()['state']);
    }

    public function test_accepting_a_baseline_locally_posts_no_cloud_gl_or_stock(): void
    {
        $this->seedStaleAcceptedBaseline();
        $glBefore = DB::connection('tenant')->table('journal_entries')->count();
        $ledgerBefore = DB::connection('tenant')->table('stock_ledgers')->count();

        $this->svc()->acceptCutover($this->package(), 'supervisor:test');

        $this->assertSame($glBefore, DB::connection('tenant')->table('journal_entries')->count(), 'cutover posts no GL');
        $this->assertSame($ledgerBefore, DB::connection('tenant')->table('stock_ledgers')->count(), 'cutover posts no official stock');
    }
}
