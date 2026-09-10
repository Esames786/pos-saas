<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgeTestDatabases;
use Tests\MySql\Support\TenantFixtures;

/**
 * Q — the CONNECTION STATE MACHINE under a real partition, with INDEPENDENT processes and INDEPENDENT databases, and a
 * PROCESS RESTART at every state: every appliance command below is a fresh OS process that must re-derive the same
 * connection state from the persisted facts (`edge:connection` compares persisted vs derived), and at no moment may the
 * Cloud fence and the appliance fence both allow a write.
 *
 *   ONLINE → (WAN dies; ticks over the real transport) ONLINE (blip) → UNSTABLE → UNSTABLE → LOST → (Cloud lease expires:
 *   Cloud fenced, appliance still refuses) → (skew margin) PREPARING_LOCAL → supervised takeover → LOCAL_ACTIVE →
 *   (WAN back) CONNECTION_RESTORED — still the writer → RECONCILING → handback blocked until reconciled → handback over a
 *   dead wire fails → HANDING_BACK fenced on both sides → Cloud acknowledges → standby → ONLINE.
 */
class EdgeConnectionPartitionTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const DEVICE = 'partition-q-device';
    private const TTL = 20;
    private const SKEW = 8;

    private static bool $provisioned = false;

    private int $cloudBranchId;
    private int $cloudOtherBranchId;
    private string $cloudDb;
    private string $edgeDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cloudDb = (string) config('database.connections.tenant.database');
        $this->edgeDb = EdgeTestDatabases::local('authority_q');
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_branch_authority_leases', 'terminals', 'branches', 'users']);
        $this->cloudBranchId = $this->makeBranch(['name' => 'Cloud Branch A']);
        $this->cloudOtherBranchId = $this->makeBranch(['name' => 'Cloud Branch B']);
        $this->provisionApplianceDb();
    }

    protected function tearDown(): void
    {
        $this->useDb($this->cloudDb);
        parent::tearDown();
    }

    private function provisionApplianceDb(): void
    {
        $c = config('database.connections.tenant');
        $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['username'], $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if (! self::$provisioned) {
            $pdo->exec('DROP DATABASE IF EXISTS `' . $this->edgeDb . '`');
            $pdo->exec('CREATE DATABASE `' . $this->edgeDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $this->useDb($this->edgeDb);
            Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]);
            Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
            self::$provisioned = true;
        }
        $this->useDb($this->edgeDb);
        config(['app.role' => 'branch_server']);
        $this->cleanTenant(['edge_local_connection_transitions', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_user_credentials', 'edge_local_meta', 'products', 'categories', 'terminals', 'branches', 'users']);
        $branch = $this->makeBranch(['name' => 'Appliance Branch']);
        $user = $this->makeUser(['default_branch_id' => $branch, 'employee_code' => 'PQ' . Str::random(4)]);
        $this->makeTerminal($branch);
        $product = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $this->bindEdgeLocalMeta($branch, 1, deviceUuid: self::DEVICE);
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema')]);
        $this->acceptTestBaseline([['product_id' => $product, 'product_variant_id' => null, 'quantity' => 10]]);
        // The freshness worker's work, carried by hand here (the real pull is proven over HTTP elsewhere): the accepted
        // baseline EQUALS the watermark the Cloud will advertise.
        DB::table('edge_operational_stock_baselines')->where('status', 'accepted')->update(['stock_watermark' => 'sw:cloud-1', 'cloud_as_of' => now()]);
        $this->seedEdgeCredential($user, $branch, 1);
        config(['app.role' => 'cloud']);
        $this->useDb($this->cloudDb);
    }

    private function useDb(string $db): void
    {
        config(['database.connections.tenant.database' => $db]);
        DB::purge('tenant');
        DB::setDefaultConnection('tenant');
    }

    private function side(string $role, string $db, array $args): string
    {
        $cmd = array_merge([PHP_BINARY, base_path('tests/MySql/Support/edge_authority_worker.php')], array_map('strval', $args));
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv() ?: [], [
            'ROLE' => $role, 'EDGE_WORKER_DB' => $db, 'EDGE_TEST_TENANT_DB' => $this->cloudDb, 'EDGE_DEVICE_UUID' => self::DEVICE,
            'EDGE_AUTHORITY_TTL' => (string) self::TTL, 'EDGE_AUTHORITY_SKEW_MARGIN' => (string) self::SKEW,
            'EDGE_AUTHORITY_UNSTABLE_AFTER' => '2', 'EDGE_AUTHORITY_LOST_AFTER' => '4', 'EDGE_AUTHORITY_HANDBACK_MIN_ACKS' => '2',
            'APP_ENV' => 'testing',
        ]));
        $out = trim(stream_get_contents($pipes[1]));
        $err = trim(stream_get_contents($pipes[2]) ?: '');
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return $out !== '' ? $out : 'STDERR:' . $err;
    }

    private function cloud(array $args): string
    {
        return $this->side('cloud', $this->cloudDb, $args);
    }

    private function edge(array $args): string
    {
        return $this->side('branch_server', $this->edgeDb, $args);
    }

    private function okJson(string $out): array
    {
        $this->assertStringStartsWith('OK:', $out, "expected OK json (got: {$out})");

        return json_decode(substr($out, 3), true) ?? [];
    }

    /** RESTART PROOF: a fresh process must re-derive exactly the persisted connection state. */
    private function assertRestartRecovers(string $expected, string $where): void
    {
        $c = $this->okJson($this->edge(['edge:connection']));
        $this->assertSame($expected, $c['persisted'], "persisted state at {$where}");
        $this->assertSame($expected, $c['derived'], "a restarted process re-derives the same state at {$where}");
    }

    /** SINGLE WRITER: never both. */
    private function assertNoSplitBrain(string $where): void
    {
        $cloudWrites = str_starts_with($this->cloud(['cloud:fence', $this->cloudBranchId]), 'OK:');
        $edgeWrites = str_starts_with($this->edge(['edge:sale-check']), 'OK:');
        $this->assertFalse($cloudWrites && $edgeWrites, "two writers at {$where}");
    }

    private function waitUntil(callable $done, float $maxSeconds, string $what): void
    {
        $start = microtime(true);
        while (! $done()) {
            if (microtime(true) - $start > $maxSeconds) {
                $this->fail("Timed out after {$maxSeconds}s waiting for: {$what}");
            }
            usleep(500_000);
        }
    }

    public function test_connection_states_survive_restarts_and_never_create_two_writers(): void
    {
        $b = $this->cloudBranchId;

        // ── ONLINE: the Cloud holds the lease; the appliance acknowledged (and knows the advertised revision/watermark). ──
        $this->assertStringStartsWith('OK:holder=cloud:fenced=0', $this->cloud(['cloud:heartbeat', $b, self::DEVICE, 1, 'standby']));
        $ackAt = microtime(true);
        $this->assertStringContainsString(':conn=online', $this->edge(['edge:ack', self::TTL, 'cloud', 1, 'sw:cloud-1']));
        $this->assertRestartRecovers('online', 'online');
        $this->assertNoSplitBrain('online');
        $this->assertSame('OK:cloud-write-allowed', $this->cloud(['cloud:fence', $b]));

        // ── THE WAN DIES: real ticks (heartbeat over the real transport → unreachable). Each tick is a fresh process. ──
        $expected = ['online', 'connection_unstable', 'connection_unstable', 'connection_lost'];
        foreach ($expected as $i => $state) {
            $t = $this->okJson($this->edge(['edge:tick']));
            $this->assertFalse($t['hb']);
            $this->assertSame($state, $t['conn'], 'tick ' . ($i + 1));
            $this->assertRestartRecovers($state, 'tick ' . ($i + 1));
            $this->assertStringStartsWith('ERR:', $this->edge(['edge:sale-check']), 'no local mutation while the lease is live');
        }
        $this->assertLessThan(self::TTL - 3, microtime(true) - $ackAt, 'test pacing: the failures were recorded inside the lease');

        // ── The Cloud lease expires (Cloud clock): the Cloud fences itself while the appliance is still inside its skew margin. ──
        $this->waitUntil(fn () => str_starts_with($this->cloud(['cloud:fence', $b]), 'ERR:'), self::TTL + 5, 'Cloud fence at expiry');
        $this->assertLessThan(self::TTL + self::SKEW - 2, microtime(true) - $ackAt, 'test pacing');
        $t = $this->okJson($this->edge(['edge:tick']));
        $this->assertSame('connection_lost', $t['conn'], 'not yet lapsed on the appliance clock');
        $this->assertStringStartsWith('ERR:', $this->edge(['edge:sale-check']), 'fenced on both sides — a gap, never an overlap');
        $this->assertNoSplitBrain('cloud expired, appliance in margin');

        // ── The skew margin passes: PREPARING_LOCAL. Nothing automatic. Supervisor confirms → LOCAL_ACTIVE. ──
        $this->waitUntil(fn () => $this->okJson($this->edge(['edge:tick']))['conn'] === 'preparing_local', self::SKEW + 6, 'preparing_local');
        $this->assertRestartRecovers('preparing_local', 'preparing_local');
        $this->assertStringStartsWith('ERR:', $this->edge(['edge:sale-check']));
        $this->assertStringStartsWith('ERR:', $this->edge(['edge:takeover']), 'no automatic activation');
        $this->assertStringStartsWith('OK:state=local_active:stale_accepted=0', $this->edge(['edge:takeover', 'confirm']));
        $this->assertRestartRecovers('local_active', 'local_active');
        $this->assertSame('OK:edge-write-allowed', $this->edge(['edge:sale-check']));
        $this->assertStringStartsWith('ERR:', $this->cloud(['cloud:fence', $b]), 'a mobile/other Internet client on the Cloud POS is refused');
        $this->assertSame('OK:cloud-write-allowed', $this->cloud(['cloud:fence', $this->cloudOtherBranchId]), 'the other branch continues');
        $this->assertNoSplitBrain('local_active');
        $this->assertSame('LOCAL MODE ACTIVE', $this->okJson($this->edge(['edge:connection']))['label']);

        // ── WAN RESTORED: acknowledged heartbeats resume (the Cloud learns holder=edge). The appliance REMAINS the writer. ──
        $this->assertStringStartsWith('OK:holder=edge:fenced=1', $this->cloud(['cloud:heartbeat', $b, self::DEVICE, 2, 'local_active']));
        $this->assertStringContainsString(':conn=connection_restored', $this->edge(['edge:ack', self::TTL, 'edge']));
        $this->assertRestartRecovers('connection_restored', 'connection_restored');
        $this->assertSame('OK:edge-write-allowed', $this->edge(['edge:sale-check']), 'reconnect never switches the writer');
        $this->assertStringStartsWith('ERR:', $this->cloud(['cloud:fence', $b]), 'the Cloud stays fenced');
        $this->assertNoSplitBrain('connection_restored');
        $this->assertStringContainsString(':conn=reconciling', $this->edge(['edge:ack', self::TTL, 'edge']));
        $this->assertRestartRecovers('reconciling', 'reconciling');
        $this->assertSame('SYNCHRONIZING', $this->okJson($this->edge(['edge:connection']))['label']);

        // ── Handback needs a clean reconciliation; until then it is BLOCKED with the reason (nothing changes). ──
        $a = $this->okJson($this->edge(['edge:handback-assess']));
        $this->assertFalse($a['ready']);
        $this->assertContains('RECONCILIATION_NOT_CLEAN', $a['blockers']);
        $r = $this->okJson($this->edge(['edge:handback-run']));
        $this->assertSame('blocked', $r['status']);
        $this->assertSame('local_active', $r['authority_state']);
        $this->assertSame('OK:edge-write-allowed', $this->edge(['edge:sale-check']));
        // The worker's reconciliation pass comes back clean (carried by hand — the real pass is proven over HTTP elsewhere).
        $this->assertSame('OK:reconciled', $this->edge(['edge:mark-reconciled']));
        $this->assertTrue($this->okJson($this->edge(['edge:handback-assess']))['ready']);

        // ── The controlled handback over a DEAD wire: fenced locally (handing_back), the Cloud never acknowledged → both fenced. ──
        $r = $this->okJson($this->edge(['edge:handback-run']));
        $this->assertSame('failed', $r['status'], json_encode($r));
        $this->assertSame('handing_back', $r['authority_state']);
        $this->assertRestartRecovers('handing_back', 'handing_back');
        $this->assertSame('RETURNING TO ONLINE', $this->okJson($this->edge(['edge:connection']))['label']);
        $this->assertStringStartsWith('ERR:', $this->edge(['edge:sale-check']), 'fenced locally');
        $this->assertStringStartsWith('ERR:', $this->cloud(['cloud:fence', $b]), 'Cloud still fenced (holder edge)');
        $this->assertNoSplitBrain('handing_back');

        // ── The wire carries the handback: the Cloud acknowledges (clean) → Cloud writes; the appliance records the ack → standby → ONLINE. ──
        $this->assertSame('OK:holder=cloud', $this->cloud(['cloud:handback', $b, self::DEVICE, 0, 0]));
        $this->assertSame('OK:cloud-write-allowed', $this->cloud(['cloud:fence', $b]));
        $this->useDb($this->edgeDb);
        DB::table('edge_local_meta')->update(['authority_state' => 'standby', 'authority_last_ack_at' => now(), 'authority_cloud_holder_seen' => 'cloud', 'heartbeat_consecutive_failures' => 0]);
        $this->useDb($this->cloudDb);
        // (The appliance's own handback path persists this transition itself — proven over HTTP; the test wire evaluates once.)
        $this->assertSame('ONLINE', $this->okJson($this->edge(['edge:connection']))['label']);
        $this->assertRestartRecovers('online', 'online again');
        $this->assertStringStartsWith('ERR:', $this->edge(['edge:sale-check']), 'standby refuses local mutation');
        $this->assertNoSplitBrain('online again');

        // The audit trail on the appliance holds the whole path.
        $this->useDb($this->edgeDb);
        $trail = DB::table('edge_local_connection_transitions')->orderBy('id')->pluck('to_state')->all();
        $this->useDb($this->cloudDb);
        $this->assertSame(['online', 'connection_unstable', 'connection_lost', 'preparing_local', 'local_active', 'connection_restored', 'reconciling', 'handing_back', 'online'], $trail, 'transitions are recorded only on change, in order');
    }
}
