<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Services\Edge\EdgeSyncOutboxService;
use App\Services\Edge\EdgeSyncReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE-SYNC-ENGINE-1E — reconcile the LOCAL outbox against the CLOUD ingestion truth.
 *
 * Proves: a lost ACK (local pending, Cloud applied SAME hash) is safely recovered to acknowledged through the
 * normal owner-guarded authority WITHOUT reposting; a divergent Cloud hash is classified and REFUSED (never
 * overwritten); a permanently-failed local row that Cloud actually applied is surfaced but not silently
 * un-terminated; Cloud orphans and local-ack/cloud-missing divergences are surfaced; and a live foreign lease
 * blocks recovery (a stale actor cannot bypass ownership).
 */
class EdgeSyncReconciliationMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_sync_outbox']);
        config(['app.role' => 'branch_server']);
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function seedRow(string $state = EdgeSyncOutbox::STATE_PENDING, array $extra = []): EdgeSyncOutbox
    {
        $saleUuid = (string) Str::ulid();
        $env = ['envelope_schema_version' => 'edge-sale-envelope-v1', 'sale_uuid' => $saleUuid, 'lines' => []];
        $json = json_encode($env);

        return EdgeSyncOutbox::create(array_merge([
            'sale_uuid' => $saleUuid, 'envelope_schema_version' => 'edge-sale-envelope-v1',
            'config_revision' => 5, 'activation_epoch' => 1, 'envelope' => $json,
            'content_hash' => hash('sha256', $json), 'state' => $state,
        ], $extra));
    }

    private function cloudApplied(EdgeSyncOutbox $row, array $overrides = []): array
    {
        return array_merge([
            'status' => 'applied', 'sale_uuid' => $row->sale_uuid, 'content_hash' => $row->content_hash,
            'ingestion_uuid' => (string) Str::ulid(), 'official_sale_no' => 'SO-CLOUD-1',
            'activation_epoch' => 1, 'config_revision' => 5,
        ], $overrides);
    }

    private function svc(): EdgeSyncReconciliationService
    {
        return app(EdgeSyncReconciliationService::class);
    }

    private function findingFor(array $findings, string $saleUuid): array
    {
        foreach ($findings as $f) {
            if ($f['sale_uuid'] === $saleUuid) {
                return $f;
            }
        }
        $this->fail("no reconciliation finding for {$saleUuid}");
    }

    // ── classification ───────────────────────────────────────────────────────────

    public function test_reconcile_classifies_every_local_and_cloud_row(): void
    {
        $inSync = $this->seedRow(EdgeSyncOutbox::STATE_ACKNOWLEDGED);
        $lostAck = $this->seedRow(EdgeSyncOutbox::STATE_PENDING);
        $pending = $this->seedRow(EdgeSyncOutbox::STATE_PENDING);
        $divergent = $this->seedRow(EdgeSyncOutbox::STATE_PENDING);
        $terminalApplied = $this->seedRow(EdgeSyncOutbox::STATE_FAILED_PERMANENT);
        $localAckCloudGone = $this->seedRow(EdgeSyncOutbox::STATE_ACKNOWLEDGED);

        $orphanUuid = (string) Str::ulid();
        $cloud = [
            $inSync->sale_uuid => $this->cloudApplied($inSync),
            $lostAck->sale_uuid => $this->cloudApplied($lostAck),
            $divergent->sale_uuid => $this->cloudApplied($divergent, ['content_hash' => str_repeat('a', 64)]),
            $terminalApplied->sale_uuid => $this->cloudApplied($terminalApplied),
            $orphanUuid => ['status' => 'applied', 'content_hash' => str_repeat('b', 64)],
            // $pending and $localAckCloudGone deliberately absent from the Cloud snapshot.
        ];

        $findings = $this->svc()->reconcile($cloud);

        $this->assertSame(EdgeSyncReconciliationService::IN_SYNC, $this->findingFor($findings, $inSync->sale_uuid)['classification']);
        $this->assertSame(EdgeSyncReconciliationService::RECOVERABLE_LOST_ACK, $this->findingFor($findings, $lostAck->sale_uuid)['classification']);
        $this->assertSame(EdgeSyncReconciliationService::PENDING_UNSENT, $this->findingFor($findings, $pending->sale_uuid)['classification']);
        $this->assertSame(EdgeSyncReconciliationService::HASH_DIVERGENCE, $this->findingFor($findings, $divergent->sale_uuid)['classification']);
        $this->assertSame(EdgeSyncReconciliationService::TERMINAL_LOCAL_CLOUD_APPLIED, $this->findingFor($findings, $terminalApplied->sale_uuid)['classification']);
        $this->assertSame(EdgeSyncReconciliationService::LOCAL_ACK_CLOUD_MISSING, $this->findingFor($findings, $localAckCloudGone->sale_uuid)['classification']);
        $this->assertSame(EdgeSyncReconciliationService::CLOUD_ORPHAN, $this->findingFor($findings, $orphanUuid)['classification']);
    }

    // ── safe lost-ACK recovery (no repost) ───────────────────────────────────────

    public function test_recover_lost_ack_acknowledges_a_pending_row_from_clouds_own_ack(): void
    {
        $row = $this->seedRow(EdgeSyncOutbox::STATE_PENDING);
        $ack = $this->cloudApplied($row);

        $this->assertSame('acknowledged', $this->svc()->recoverLostAck($row->sale_uuid, $ack));

        $fresh = $row->fresh();
        $this->assertSame(EdgeSyncOutbox::STATE_ACKNOWLEDGED, $fresh->state);
        $this->assertSame($ack['ingestion_uuid'], $fresh->ack_ingestion_uuid);
        $this->assertNull($fresh->lease_owner);
    }

    public function test_recover_lost_ack_is_idempotent_on_an_already_acknowledged_row(): void
    {
        $row = $this->seedRow(EdgeSyncOutbox::STATE_ACKNOWLEDGED);
        $this->assertSame('acknowledged', $this->svc()->recoverLostAck($row->sale_uuid, $this->cloudApplied($row)));
        $this->assertSame(EdgeSyncOutbox::STATE_ACKNOWLEDGED, $row->fresh()->state);
    }

    public function test_recover_refuses_a_divergent_hash_and_mutates_nothing(): void
    {
        $row = $this->seedRow(EdgeSyncOutbox::STATE_PENDING);
        $ack = $this->cloudApplied($row, ['content_hash' => str_repeat('c', 64)]);

        try {
            $this->svc()->recoverLostAck($row->sale_uuid, $ack);
            $this->fail('a divergent hash must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('RECONCILE_HASH_DIVERGENCE', $e->getMessage());
        }
        $this->assertSame(EdgeSyncOutbox::STATE_PENDING, $row->fresh()->state);
    }

    public function test_recover_refuses_a_permanently_failed_row_without_supervisor_requeue(): void
    {
        $row = $this->seedRow(EdgeSyncOutbox::STATE_FAILED_PERMANENT);

        try {
            $this->svc()->recoverLostAck($row->sale_uuid, $this->cloudApplied($row));
            $this->fail('a failed_permanent row must not be silently un-terminated');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('RECONCILE_TERMINAL', $e->getMessage());
        }
        $this->assertSame(EdgeSyncOutbox::STATE_FAILED_PERMANENT, $row->fresh()->state);
    }

    public function test_recover_refuses_when_cloud_has_not_applied(): void
    {
        $row = $this->seedRow(EdgeSyncOutbox::STATE_PENDING);
        $ack = $this->cloudApplied($row, ['status' => 'conflict']);

        try {
            $this->svc()->recoverLostAck($row->sale_uuid, $ack);
            $this->fail('recovery requires an applied Cloud ACK');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('RECONCILE_NOT_APPLIED', $e->getMessage());
        }
        $this->assertSame(EdgeSyncOutbox::STATE_PENDING, $row->fresh()->state);
    }

    public function test_recover_cannot_bypass_a_live_foreign_lease(): void
    {
        $row = $this->seedRow(EdgeSyncOutbox::STATE_PENDING);
        // A live worker holds a NON-expired lease on this exact row.
        $leased = app(EdgeSyncOutboxService::class)->leaseSpecific($row->sale_uuid, 'worker-live');
        $this->assertNotNull($leased);

        try {
            $this->svc()->recoverLostAck($row->sale_uuid, $this->cloudApplied($row));
            $this->fail('recovery must not seize a row under a live lease');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('RECONCILE_UNLEASABLE', $e->getMessage());
        }
        $fresh = $row->fresh();
        $this->assertSame(EdgeSyncOutbox::STATE_LEASED, $fresh->state);
        $this->assertStringStartsWith('worker-live', (string) $fresh->lease_owner);
    }
}
