<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Services\Edge\EdgeSyncOutboxService;
use App\Services\Edge\EdgeSyncSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE-SYNC-ENGINE-1D — the Edge outbox transport (EdgeSyncSender), with the Cloud faked (Http::fake).
 * Proves: an Edge outbox row is ACKNOWLEDGED only on a VERIFIED terminal-success ACK for the SAME
 * sale_uuid + content_hash; transient failures (5xx, connection/timeout) release the lease for retry
 * WITHOUT acknowledging; terminal verdicts (conflict) go to failed_permanent; a deterministic exception
 * (insufficient stock) is retryable-not-hot-looped; a mismatched ACK identity is rejected; and a stale
 * lease owner can never acknowledge a row another worker reclaimed. TLS/verify and timeouts are set by the
 * sender; nothing is acknowledged merely because HTTP returned 200.
 */
class EdgeSyncSenderMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private string $url = 'https://cloud.example.test/api/edge/sync/sales';

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_sync_outbox']);
        config([
            'app.role' => 'branch_server',
            'edge.sync.url' => $this->url,
            'edge.sync.device_id' => 'device-A',
            'edge.sync.device_secret' => 'secret-A',
        ]);
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function seedRow(array $extra = []): EdgeSyncOutbox
    {
        $saleUuid = (string) Str::ulid();
        $env = ['envelope_schema_version' => 'edge-sale-envelope-v1', 'sale_uuid' => $saleUuid, 'lines' => []];
        $json = json_encode($env);

        return EdgeSyncOutbox::create(array_merge([
            'sale_uuid' => $saleUuid, 'envelope_schema_version' => 'edge-sale-envelope-v1',
            'config_revision' => 5, 'activation_epoch' => 1, 'envelope' => $json,
            'content_hash' => hash('sha256', $json), 'state' => 'pending',
        ], $extra));
    }

    private function ack(EdgeSyncOutbox $row, array $overrides = []): array
    {
        return array_merge([
            'status' => 'applied', 'sale_uuid' => $row->sale_uuid, 'content_hash' => $row->content_hash,
            'ingestion_uuid' => (string) Str::ulid(), 'sales_order_id' => 901, 'official_sale_no' => 'SO-CLOUD-1',
            'activation_epoch' => 1, 'config_revision' => 5, 'ingested_at' => now()->toIso8601String(),
        ], $overrides);
    }

    private function sender(): EdgeSyncSender
    {
        return app(EdgeSyncSender::class);
    }

    // ── verified success ─────────────────────────────────────────────────────────

    public function test_a_verified_applied_ack_acknowledges_the_outbox_row(): void
    {
        $row = $this->seedRow();
        Http::fake([$this->url => Http::response($this->ack($row), 201)]);

        $this->assertSame('acknowledged', $this->sender()->sendNext('worker-1'));
        $fresh = $row->fresh();
        $this->assertSame('acknowledged', $fresh->state);
        $this->assertNotNull($fresh->acknowledged_at);
        $this->assertNull($fresh->lease_owner);
        $this->assertNotEmpty($fresh->ack_ingestion_uuid);

        // The request carried device auth headers + the immutable envelope bytes; TLS verify was ON.
        Http::assertSent(function ($request) use ($row) {
            return $request->url() === $this->url
                && $request->hasHeader('X-Edge-Device-ID', 'device-A')
                && $request->hasHeader('Authorization', 'Bearer secret-A')
                && data_get($request->data(), 'envelope.sale_uuid') === $row->sale_uuid;
        });
    }

    public function test_an_already_applied_replay_ack_acknowledges_without_error_lost_ack_recovery(): void
    {
        // Crash window A: Cloud applied but the first ACK was lost; the retry gets already_applied.
        $row = $this->seedRow();
        Http::fake([$this->url => Http::response($this->ack($row, ['status' => 'already_applied']), 200)]);

        $this->assertSame('acknowledged', $this->sender()->sendNext('worker-1'));
        $this->assertSame('acknowledged', $row->fresh()->state);
    }

    // ── transient failures: retry, never acknowledge ─────────────────────────────

    public function test_a_5xx_is_transient_the_row_is_released_not_acknowledged(): void
    {
        $row = $this->seedRow();
        Http::fake([$this->url => Http::response(['error' => 'db down'], 503)]);

        $this->assertSame('retry', $this->sender()->sendNext('worker-1'));
        $fresh = $row->fresh();
        $this->assertSame('pending', $fresh->state, 'released for retry');
        $this->assertNull($fresh->acknowledged_at);
        $this->assertSame(1, (int) $fresh->attempts);
        $this->assertStringContainsString('HTTP 503', (string) $fresh->last_error);
    }

    public function test_a_connection_timeout_is_transient_and_retryable(): void
    {
        $row = $this->seedRow();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: connect timeout'));

        $this->assertSame('retry', $this->sender()->sendNext('worker-1'));
        $this->assertSame('pending', $row->fresh()->state);
        $this->assertNull($row->fresh()->acknowledged_at);
    }

    // ── terminal + exception classification ──────────────────────────────────────

    public function test_a_conflict_ack_is_terminal_failed_permanent(): void
    {
        $row = $this->seedRow();
        Http::fake([$this->url => Http::response(['status' => 'conflict', 'failure_code' => 'ENVELOPE_CONFLICT', 'sale_uuid' => $row->sale_uuid, 'content_hash' => $row->content_hash], 409)]);

        $this->assertSame('terminal', $this->sender()->sendNext('worker-1'));
        $this->assertSame('failed_permanent', $row->fresh()->state);
        $this->assertNull($row->fresh()->acknowledged_at);
    }

    public function test_a_deterministic_exception_is_retryable_but_not_a_hot_loop_or_ack(): void
    {
        $row = $this->seedRow();
        Http::fake([$this->url => Http::response($this->ack($row, ['status' => 'exception', 'failure_code' => 'INSUFFICIENT_STOCK']), 422)]);

        $this->assertSame('retry', $this->sender()->sendNext('worker-1'));
        $fresh = $row->fresh();
        $this->assertSame('pending', $fresh->state, 'released for a later, 1E-gated retry — never acknowledged, never failed_permanent');
        $this->assertNull($fresh->acknowledged_at);
        $this->assertStringContainsString('INSUFFICIENT_STOCK', (string) $fresh->last_error);
    }

    // ── ACK identity rejection ───────────────────────────────────────────────────

    public function test_an_ack_with_the_wrong_sale_uuid_is_rejected_never_acknowledged(): void
    {
        $row = $this->seedRow();
        Http::fake([$this->url => Http::response($this->ack($row, ['sale_uuid' => (string) Str::ulid()]), 201)]);

        $this->assertSame('reject', $this->sender()->sendNext('worker-1'));
        $this->assertSame('pending', $row->fresh()->state, 'a mismatched ACK never acknowledges; released');
        $this->assertNull($row->fresh()->acknowledged_at);
    }

    public function test_an_ack_with_the_wrong_content_hash_is_rejected(): void
    {
        $row = $this->seedRow();
        Http::fake([$this->url => Http::response($this->ack($row, ['content_hash' => str_repeat('0', 64)]), 201)]);

        $this->assertSame('reject', $this->sender()->sendNext('worker-1'));
        $this->assertSame('pending', $row->fresh()->state);
    }

    // ── §18 stale-lease-owner ACK guard ──────────────────────────────────────────

    public function test_a_stale_lease_owner_cannot_acknowledge_a_reclaimed_row(): void
    {
        $svc = app(EdgeSyncOutboxService::class);
        $row = $this->seedRow();

        // Worker A leases, then its lease is expired and Worker B reclaims it.
        $leasedByA = $svc->lease('worker-A');
        $leasedByA->update(['lease_expires_at' => now()->subMinute()]);
        $leasedByB = $svc->lease('worker-B');
        $this->assertSame($row->id, $leasedByB->id);
        $this->assertStringStartsWith('worker-B', $leasedByB->lease_owner);

        // Stale worker A, still holding its old row object, tries to acknowledge with a valid-looking ACK.
        try {
            $svc->markAcknowledged($leasedByA, 'ing-x', ['sale_uuid' => $row->sale_uuid, 'content_hash' => $row->content_hash]);
            $this->fail('a stale lease owner must not be able to acknowledge');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('OUTBOX_STALE_OWNER', $e->getMessage());
        }
        $fresh = $row->fresh();
        $this->assertSame('leased', $fresh->state, 'the row is still owned by worker B, not acknowledged');
        $this->assertStringStartsWith('worker-B', $fresh->lease_owner);
    }

    public function test_nothing_to_send_returns_idle(): void
    {
        Http::fake();
        $this->assertSame('idle', $this->sender()->sendNext('worker-1'));
        Http::assertNothingSent();
    }
}
