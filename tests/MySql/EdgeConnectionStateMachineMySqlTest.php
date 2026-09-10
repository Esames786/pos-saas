<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Edge\EdgeSyncOutbox;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeConnectionStateMachine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * Q — the CONNECTION STATE MACHINE on the appliance: deterministic derivation from persisted facts, persisted and
 * audited transitions, restart safety (a fresh evaluator re-derives the same state), single-failure tolerance, the
 * lease-lapse boundary, freshness-gated takeover, and the reconnect path that keeps the appliance the writer.
 * The Cloud is unreachable throughout (real transport against a closed port): acknowledgements are carried by hand.
 */
class EdgeConnectionStateMachineMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_local_connection_transitions', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_user_credentials', 'edge_local_meta', 'products', 'categories', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch();
        $user = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'SM' . Str::random(4)]);
        $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'sm-device');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema')]);
        $this->asBranchServerRuntime();
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 10]]);
        $this->seedEdgeCredential($user, $this->branchId, 1);
        config([
            'edge.authority.heartbeat_url' => 'http://127.0.0.1:9/api/edge/authority/heartbeat',
            'edge.authority.handback_url' => 'http://127.0.0.1:9/api/edge/authority/handback',
            'edge.authority.ttl_seconds' => 60, 'edge.authority.skew_margin_seconds' => 10,
            'edge.authority.unstable_after_failures' => 2, 'edge.authority.lost_after_failures' => 4,
            'edge.authority.handback_min_consecutive_acks' => 2,
            'edge.sync.device_id' => 'sm-device', 'edge.sync.device_secret' => 'secret', 'edge.sync.connect_timeout' => 1, 'edge.sync.timeout' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function meta(): EdgeLocalMeta
    {
        return EdgeLocalMeta::on('tenant')->firstOrFail();
    }

    /** What a successful wire delivers: the appliance records an acknowledged beat with the Cloud's advertisement. */
    private function ack(string $holder = 'cloud', ?int $revision = null, ?string $watermark = null): void
    {
        $m = $this->meta();
        $m->forceFill([
            'authority_heartbeat_seq' => (int) $m->authority_heartbeat_seq + 1, 'authority_last_ack_at' => now(), 'authority_lease_ttl_seconds' => 60,
            'heartbeat_consecutive_failures' => 0, 'heartbeat_consecutive_acks' => (int) $m->heartbeat_consecutive_acks + 1,
            'authority_cloud_holder_seen' => $holder,
            'standby_config_revision_seen' => $revision ?? $m->standby_config_revision_seen,
            'standby_stock_watermark_seen' => $watermark ?? $m->standby_stock_watermark_seen,
        ])->save();
    }

    /** A fresh evaluator = what a restarted process sees (services are transient; every fact is in the DB). */
    private function machine(): EdgeConnectionStateMachine
    {
        return app()->make(EdgeConnectionStateMachine::class);
    }

    private function authority(): EdgeAuthorityService
    {
        return app()->make(EdgeAuthorityService::class);
    }

    private function localWriteAllowed(): bool
    {
        try {
            $this->authority()->assertLocalMutationAllowed();

            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    public function test_online_to_preparing_local_is_deterministic_and_one_failure_is_a_blip(): void
    {
        $this->ack('cloud', 1, 'sw:cloud-1');
        $this->assertSame('online', $this->machine()->evaluate()['state']);
        $this->assertSame('ONLINE', $this->authority()->cashierState()['label']);

        // 1 failed heartbeat (real transport, closed port) → still ONLINE.
        $this->assertFalse($this->authority()->heartbeat()['ok']);
        $this->assertSame('online', $this->machine()->evaluate()['state'], 'a single failed heartbeat is a blip');
        $this->assertSame(1, (int) $this->meta()->heartbeat_consecutive_failures);
        // 2–3 → UNSTABLE, 4 → LOST — and the appliance still refuses local mutation throughout.
        $this->authority()->heartbeat();
        $this->assertSame('connection_unstable', $this->machine()->evaluate()['state']);
        $this->assertSame('INTERNET CONNECTION UNSTABLE', $this->authority()->cashierState()['label']);
        $this->authority()->heartbeat();
        $this->assertSame('connection_unstable', $this->machine()->evaluate()['state']);
        $this->authority()->heartbeat();
        $this->assertSame('connection_lost', $this->machine()->evaluate()['state']);
        $this->assertSame('INTERNET CONNECTION LOST', $this->authority()->cashierState()['label']);
        $this->assertFalse($this->localWriteAllowed(), 'multiple failures inside the lease: Edge still refuses mutation');
        $this->assertFalse($this->authority()->gates()['AUTHORITY_TAKEOVER_SAFE']);

        // The lease lapses on the appliance clock (TTL + skew margin) → PREPARING_LOCAL. Nothing automatic happens.
        Carbon::setTestNow(now()->addSeconds(60 + 10 + 1));
        $this->assertSame('preparing_local', $this->machine()->evaluate()['state']);
        $this->assertSame('PREPARING LOCAL MODE', $this->authority()->cashierState()['label']);
        $this->assertFalse($this->localWriteAllowed(), 'preparing is not active');
        $gates = $this->authority()->gates();
        $this->assertTrue($gates['AUTHORITY_TAKEOVER_SAFE']);
        $this->assertFalse($gates['STANDBY_FRESH_ENOUGH'], 'the accepted baseline carries no watermark equal to the advertised one');
        try {
            $this->authority()->takeOver(true, 'supervisor');
            $this->fail('takeover must fail closed on freshness');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('STANDBY_FRESH_ENOUGH', $e->getMessage());
        }
        // Stale acceptance needs a supervisor REASON; then it is audited.
        try {
            $this->authority()->takeOver(true, 'supervisor', true, '');
            $this->fail('accept-stale without a reason must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }
        $this->assertSame('preparing_local', $this->machine()->evaluate()['state']);

        // Bring the standby provably fresh (as the freshness worker would have): baseline equals the advertised watermark.
        DB::table('edge_operational_stock_baselines')->where('status', 'accepted')->update(['stock_watermark' => 'sw:cloud-1', 'cloud_as_of' => now()->subMinute()]);
        $this->assertTrue($this->authority()->gates()['STANDBY_FRESH_ENOUGH']);
        try {
            $this->authority()->takeOver(false, 'supervisor');
            $this->fail('no automatic activation: confirmation is required');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('confirmation', $e->getMessage());
        }
        $r = $this->authority()->takeOver(true, 'supervisor');
        $this->assertFalse($r['stale_accepted']);
        $this->assertSame('local_active', $this->machine()->evaluate()['state']);
        $this->assertSame('LOCAL MODE ACTIVE', $this->authority()->cashierState()['label']);
        $this->assertTrue($this->localWriteAllowed());
        $freshness = json_decode((string) $this->meta()->authority_takeover_freshness, true);
        $this->assertTrue($freshness['fresh']);
        $this->assertSame('sw:cloud-1', $freshness['stock_watermark_accepted']);
        $this->assertSame(1, $freshness['config_revision_applied']);

        // The audit trail holds the whole path, in order.
        $trail = DB::table('edge_local_connection_transitions')->orderBy('id')->pluck('to_state')->all();
        $this->assertSame(['online', 'connection_unstable', 'connection_lost', 'preparing_local', 'local_active'], $trail);
    }

    public function test_stale_standby_takeover_requires_an_audited_supervisor_decision(): void
    {
        $this->ack('cloud', 1, 'sw:cloud-2');
        for ($i = 0; $i < 4; $i++) {
            $this->authority()->heartbeat();
        }
        Carbon::setTestNow(now()->addSeconds(71));
        $this->assertSame('preparing_local', $this->machine()->evaluate()['state']);
        // The baseline was issued BEFORE the last ack and does not equal the advertised position → not provable.
        DB::table('edge_operational_stock_baselines')->where('status', 'accepted')->update(['stock_watermark' => 'sw:cloud-1', 'cloud_as_of' => now()->subMinutes(30)]);
        $this->assertFalse($this->authority()->freshness()['ok']);
        $r = $this->authority()->takeOver(true, 'supervisor', true, 'Internet down 20 minutes; counted the shelf, position confirmed by the manager');
        $this->assertTrue($r['stale_accepted']);
        $this->assertStringContainsString('STALE STANDBY ACCEPTED', (string) $this->meta()->authority_state_reason);
        $freshness = json_decode((string) $this->meta()->authority_takeover_freshness, true);
        $this->assertFalse($freshness['fresh']);
        $this->assertTrue($freshness['stale_accepted']);
        $this->assertNotEmpty($freshness['reasons']);
        $this->assertSame('sw:cloud-2', $freshness['stock_watermark_advertised']);
    }

    public function test_stale_config_refuses_takeover(): void
    {
        $this->ack('cloud', 7, null); // Cloud advertises revision 7; the appliance applied revision 1
        DB::table('edge_operational_stock_baselines')->where('status', 'accepted')->update(['cloud_as_of' => now()]);
        for ($i = 0; $i < 4; $i++) {
            $this->authority()->heartbeat();
        }
        Carbon::setTestNow(now()->addSeconds(71));
        $f = $this->authority()->freshness();
        $this->assertFalse($f['config_ok']);
        $this->assertStringContainsString('config revision 1 applied, Cloud advertised 7', implode(' ', $f['reasons']));
        try {
            $this->authority()->takeOver(true, 'supervisor');
            $this->fail('stale config must refuse takeover');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('STANDBY_FRESH_ENOUGH', $e->getMessage());
        }
        $this->assertSame('standby', $this->authority()->state());
    }

    public function test_reconnect_keeps_the_appliance_the_writer_until_the_controlled_handback(): void
    {
        // LOCAL, WAN down.
        $this->ack('cloud', 1, 'sw:x');
        DB::table('edge_operational_stock_baselines')->where('status', 'accepted')->update(['stock_watermark' => 'sw:x']);
        for ($i = 0; $i < 4; $i++) {
            $this->authority()->heartbeat();
        }
        Carbon::setTestNow(now()->addSeconds(71));
        $this->authority()->takeOver(true, 'supervisor');
        $this->assertSame('local_active', $this->machine()->evaluate()['state']);
        $this->authority()->heartbeat(); // still unreachable
        $this->assertSame('local_active', $this->machine()->evaluate()['state']);
        $this->assertTrue($this->localWriteAllowed());

        // WAN restored: one acknowledged beat (Cloud reports holder edge) → CONNECTION_RESTORED; the appliance REMAINS the writer.
        $this->ack('edge');
        $st = $this->machine()->evaluate();
        $this->assertSame('connection_restored', $st['state']);
        $this->assertSame('CONNECTION RESTORED', $st['label']);
        $this->assertTrue($this->localWriteAllowed(), 'reconnect never switches the writer');
        $this->assertSame('local_active', $this->authority()->state());

        // Stable + pending offline sale → SYNCING (the till shows the pending count).
        DB::table('edge_sync_outbox')->insert([
            'sale_uuid' => (string) Str::ulid(), 'config_revision' => 1, 'activation_epoch' => 1,
            'envelope_schema_version' => 'edge-sale-envelope-v1', 'content_hash' => str_repeat('a', 64), 'envelope' => json_encode(['x' => 1]), 'state' => EdgeSyncOutbox::STATE_PENDING,
            'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ack('edge');
        $st = $this->machine()->evaluate();
        $this->assertSame('syncing', $st['state']);
        $this->assertSame('SYNCHRONIZING', $st['label']);
        $this->assertSame(1, $st['pending']);
        $this->assertTrue($this->localWriteAllowed());

        // Drained but not yet reconciled → RECONCILING; reconciled clean → ready for handback (still the writer).
        DB::table('edge_sync_outbox')->update(['state' => EdgeSyncOutbox::STATE_ACKNOWLEDGED, 'acknowledged_at' => now()]);
        $st = $this->machine()->evaluate();
        $this->assertSame('reconciling', $st['state']);
        $this->assertFalse($st['handback_ready']);
        $this->meta()->forceFill(['reconcile_clean_at' => now()])->save();
        $st = $this->machine()->evaluate();
        $this->assertSame('reconciling', $st['state']);
        $this->assertTrue($st['handback_ready']);
        $this->assertTrue($this->localWriteAllowed(), 'even when clean, the writer changes only through the controlled handback');

        // A new WAN failure during reconciliation drops back to LOCAL_ACTIVE and invalidates the clean mark.
        $this->authority()->heartbeat();
        $this->assertSame('local_active', $this->machine()->evaluate()['state']);
        $this->assertNull($this->meta()->reconcile_clean_at);

        // Handing back: fenced on BOTH sides until the Cloud acknowledges.
        $this->meta()->forceFill(['authority_state' => 'handing_back'])->save();
        $st = $this->machine()->evaluate();
        $this->assertSame('handing_back', $st['state']);
        $this->assertSame('RETURNING TO ONLINE', $st['label']);
        $this->assertFalse($this->localWriteAllowed());
        // Acknowledged handback → standby → ONLINE.
        $this->meta()->forceFill(['authority_state' => 'standby', 'heartbeat_consecutive_failures' => 0])->save();
        $this->assertSame('online', $this->machine()->evaluate()['state']);
    }

    public function test_restart_recovers_the_same_state_and_never_two_writers(): void
    {
        $this->ack('cloud', 1, 'sw:x');
        DB::table('edge_operational_stock_baselines')->where('status', 'accepted')->update(['stock_watermark' => 'sw:x']);
        $expectWriter = function (bool $edgeWrites, string $where) {
            $this->assertSame($edgeWrites, $this->localWriteAllowed(), "local writer at {$where}");
        };
        // Every state below is reached, then "restarted": a brand-new evaluator must re-derive exactly the persisted state.
        $checkpoints = [];
        $snapshot = function (string $where) use (&$checkpoints) {
            $fresh = app()->make(EdgeConnectionStateMachine::class);
            $persisted = $fresh->persisted()['state'];
            $derived = $fresh->derive()['state'];
            $this->assertSame($persisted, $derived, "restart at {$where}: derived state must equal the persisted one");
            $checkpoints[$where] = $persisted;
        };
        for ($i = 0; $i < 4; $i++) {
            $this->authority()->heartbeat();
        }
        $this->machine()->evaluate();
        $snapshot('connection_lost');
        $expectWriter(false, 'connection_lost');
        Carbon::setTestNow(now()->addSeconds(71));
        $this->machine()->evaluate();
        $snapshot('preparing_local');
        $expectWriter(false, 'preparing_local');
        $this->authority()->takeOver(true, 'supervisor');
        $this->machine()->evaluate();
        $snapshot('local_active');
        $expectWriter(true, 'local_active');
        $this->ack('edge');
        $this->ack('edge');
        DB::table('edge_sync_outbox')->insert([
            'sale_uuid' => (string) Str::ulid(), 'config_revision' => 1, 'activation_epoch' => 1,
            'envelope_schema_version' => 'edge-sale-envelope-v1', 'content_hash' => str_repeat('b', 64), 'envelope' => json_encode(['x' => 1]), 'state' => EdgeSyncOutbox::STATE_PENDING,
            'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->machine()->evaluate();
        $snapshot('syncing');
        $expectWriter(true, 'syncing');
        $this->meta()->forceFill(['authority_state' => 'handing_back'])->save();
        $this->machine()->evaluate();
        $snapshot('handing_back');
        $expectWriter(false, 'handing_back');
        $this->assertSame(['connection_lost' => 'connection_lost', 'preparing_local' => 'preparing_local', 'local_active' => 'local_active', 'syncing' => 'syncing', 'handing_back' => 'handing_back'], $checkpoints);
    }
}
