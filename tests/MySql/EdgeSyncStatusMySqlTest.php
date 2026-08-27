<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Services\Edge\EdgeSyncFailureClassifier;
use App\Services\Edge\EdgeSyncOutboxService;
use App\Services\Edge\EdgeSyncStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE-SYNC-ENGINE-1E — the operator sync-status surface + safe operator actions (§D–§H, §U).
 *
 * Proves: the status snapshot reports outbox depth / oldest-pending / last-failure in business terms and the
 * baseline-cutover state, exposing NO secret; the queue drill-down carries business context and Cloud
 * acknowledgement identity; "Retry now" frees a stuck (lease-expired) row but refuses a terminal row;
 * the supervisor requeue re-queues a REQUEUEABLE operational failure (with audit) yet REFUSES a hash-conflict /
 * identity terminal verdict; and the immutable envelope cannot be edited.
 */
class EdgeSyncStatusMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId = 9;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_sync_outbox', 'edge_operational_stock_baselines', 'edge_local_meta']);
        config(['app.role' => 'branch_server']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function seedRow(string $state, array $extra = []): EdgeSyncOutbox
    {
        $u = (string) Str::ulid();
        $env = array_merge(['sale_uuid' => $u, 'sale_no' => 'LOCAL-77', 'business_date' => '2026-08-27'], $extra['env'] ?? []);
        unset($extra['env']);
        $json = json_encode($env);

        return EdgeSyncOutbox::create(array_merge([
            'sale_uuid' => $u, 'envelope_schema_version' => 'edge-sale-envelope-v1',
            'config_revision' => 5, 'activation_epoch' => 1, 'envelope' => $json,
            'content_hash' => hash('sha256', $json), 'state' => $state,
        ], $extra));
    }

    private function statusSvc(): EdgeSyncStatusService
    {
        return app(EdgeSyncStatusService::class);
    }

    private function outbox(): EdgeSyncOutboxService
    {
        return app(EdgeSyncOutboxService::class);
    }

    // ── status snapshot ──────────────────────────────────────────────────────────

    public function test_snapshot_reports_counts_last_failure_and_exposes_no_secret(): void
    {
        config(['edge.sync.device_secret' => 'super-secret-value']);
        $this->seedRow(EdgeSyncOutbox::STATE_PENDING);
        $this->seedRow(EdgeSyncOutbox::STATE_ACKNOWLEDGED, ['acknowledged_at' => now()->subMinutes(3)]);
        $this->seedRow(EdgeSyncOutbox::STATE_FAILED_PERMANENT, ['last_error' => 'ENVELOPE_CONFLICT: hash differs']);

        $snap = $this->statusSvc()->snapshot();

        $this->assertTrue($snap['bound']);
        $this->assertSame($this->branchId, $snap['branch_id']);
        $this->assertSame(1, $snap['outbox']['pending']);
        $this->assertSame(1, $snap['outbox']['acknowledged']);
        $this->assertSame(1, $snap['outbox']['failed_permanent']);
        $this->assertSame(EdgeSyncFailureClassifier::CLASS_CONFLICT, $snap['last_failure']['class']);
        $this->assertNotNull($snap['oldest_pending_at']);

        // No secret anywhere in the surface.
        $this->assertArrayNotHasKey('device_secret', $snap);
        $this->assertStringNotContainsString('super-secret-value', json_encode($snap));
    }

    public function test_queue_drilldown_carries_business_context_and_cloud_identity(): void
    {
        $this->seedRow(EdgeSyncOutbox::STATE_ACKNOWLEDGED, [
            'acknowledged_at' => now(), 'ack_ingestion_uuid' => 'ING-1',
            'ack_payload' => ['official_sale_no' => 'SO-CLOUD-9', 'sale_uuid' => 'x', 'content_hash' => 'y'],
        ]);

        $rows = $this->statusSvc()->queue(10);
        $this->assertCount(1, $rows);
        $this->assertSame('LOCAL-77', $rows[0]['local_reference']);
        $this->assertSame('2026-08-27', $rows[0]['business_date']);
        $this->assertSame('SO-CLOUD-9', $rows[0]['cloud_official_sale_no']);
        $this->assertSame('ING-1', $rows[0]['cloud_ingestion_uuid']);
        $this->assertSame(12, strlen($rows[0]['content_hash_short']));
    }

    // ── Retry now (§G) ───────────────────────────────────────────────────────────

    public function test_retry_now_releases_a_lease_expired_row_to_pending(): void
    {
        $row = $this->seedRow(EdgeSyncOutbox::STATE_LEASED, ['lease_owner' => 'dead-worker', 'lease_expires_at' => now()->subMinute()]);
        $this->assertSame(EdgeSyncOutbox::STATE_PENDING, $this->outbox()->retryNow($row));
        $fresh = $row->fresh();
        $this->assertSame(EdgeSyncOutbox::STATE_PENDING, $fresh->state);
        $this->assertNull($fresh->lease_owner);
    }

    public function test_retry_now_refuses_a_failed_permanent_row(): void
    {
        $row = $this->seedRow(EdgeSyncOutbox::STATE_FAILED_PERMANENT, ['last_error' => 'ENVELOPE_CONFLICT']);
        $this->expectExceptionMessage('OUTBOX_RETRY_TERMINAL');
        $this->outbox()->retryNow($row);
    }

    // ── Supervisor requeue (§H) ──────────────────────────────────────────────────

    public function test_requeue_re_queues_a_requeueable_operational_failure_with_audit(): void
    {
        $row = $this->seedRow(EdgeSyncOutbox::STATE_FAILED_PERMANENT, ['last_error' => 'INSUFFICIENT_STOCK for product 12']);
        $this->outbox()->requeueFailedPermanent($row, 'supervisor:bob', 'stock corrected under authorization');

        $fresh = $row->fresh();
        $this->assertSame(EdgeSyncOutbox::STATE_PENDING, $fresh->state);
        $this->assertSame(1, (int) $fresh->requeue_count);
        $this->assertSame('supervisor:bob', $fresh->last_requeued_by);
        $this->assertNotNull($fresh->last_requeued_at);
    }

    public function test_requeue_refuses_a_hash_conflict_terminal_verdict(): void
    {
        $row = $this->seedRow(EdgeSyncOutbox::STATE_FAILED_PERMANENT, ['last_error' => 'ENVELOPE_CONFLICT: same uuid, different hash']);
        try {
            $this->outbox()->requeueFailedPermanent($row, 'supervisor:bob', 'please retry');
            $this->fail('a hash conflict must never be blindly requeued');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('OUTBOX_REQUEUE_REFUSED', $e->getMessage());
        }
        $this->assertSame(EdgeSyncOutbox::STATE_FAILED_PERMANENT, $row->fresh()->state);
    }

    public function test_requeue_refuses_an_identity_terminal_verdict(): void
    {
        $row = $this->seedRow(EdgeSyncOutbox::STATE_FAILED_PERMANENT, ['last_error' => 'WRONG_BRANCH']);
        $this->expectExceptionMessage('OUTBOX_REQUEUE_REFUSED');
        $this->outbox()->requeueFailedPermanent($row, 'supervisor:bob', 'resolved');
    }

    public function test_requeue_requires_a_reason(): void
    {
        $row = $this->seedRow(EdgeSyncOutbox::STATE_FAILED_PERMANENT, ['last_error' => 'INSUFFICIENT_STOCK']);
        $this->expectExceptionMessage('OUTBOX_REQUEUE_REASON');
        $this->outbox()->requeueFailedPermanent($row, 'supervisor:bob', '   ');
    }

    // ── immutable envelope (§U) ──────────────────────────────────────────────────

    public function test_the_immutable_envelope_cannot_be_edited(): void
    {
        $row = $this->seedRow(EdgeSyncOutbox::STATE_PENDING);
        $this->expectExceptionMessage('is immutable');
        $row->update(['envelope' => json_encode(['sale_uuid' => 'tampered'])]);
    }

    // ── classifier (§F) ──────────────────────────────────────────────────────────

    public function test_failure_classifier_maps_codes_to_business_classes(): void
    {
        $this->assertSame(EdgeSyncFailureClassifier::CLASS_NONE, EdgeSyncFailureClassifier::classify(null)['class']);
        $this->assertSame(EdgeSyncFailureClassifier::CLASS_TRANSIENT, EdgeSyncFailureClassifier::classify('HTTP 503 service unavailable')['class']);
        $this->assertSame(EdgeSyncFailureClassifier::CLASS_CONFLICT, EdgeSyncFailureClassifier::classify('ENVELOPE_CONFLICT: ...')['class']);
        $this->assertSame(EdgeSyncFailureClassifier::CLASS_IDENTITY_SECURITY, EdgeSyncFailureClassifier::classify('STALE_ACTIVATION')['class']);
        $this->assertSame(EdgeSyncFailureClassifier::CLASS_INSUFFICIENT_STOCK, EdgeSyncFailureClassifier::classify('INSUFFICIENT_STOCK')['class']);
        $this->assertTrue(EdgeSyncFailureClassifier::classify('INSUFFICIENT_STOCK')['requeueable']);
        $this->assertFalse(EdgeSyncFailureClassifier::classify('ENVELOPE_CONFLICT')['requeueable']);
    }
}
