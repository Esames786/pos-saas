<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * P0 BRANCH AUTHORITY LEASE — the PARTITION proof, with INDEPENDENT processes and INDEPENDENT databases.
 *
 * The Cloud is one OS process on the Cloud tenant database; the appliance is another OS process on its own
 * appliance database (provisioned here). The only "wire" between them is what this test carries by hand (a
 * heartbeat the Cloud accepted → an ack the appliance recorded); a partition is the absence of that carry.
 * TTL 20s (the service floors the TTL at 15s — a production guard this test respects), appliance skew margin 8s,
 * real wall-clock waiting with polling — no test-time travel on either side.
 *
 * Proven, in order: Cloud healthy → Cloud writes, Edge cannot · partition begins → no unsafe overlap · Cloud lease
 * becomes invalid → Cloud branch mutation fenced (before the appliance may take over) · only after the safe expiry
 * (TTL + margin on the APPLIANCE clock) and a supervisor confirmation → Edge local mutation allowed · a mobile/other
 * Internet client on the Cloud POS for the same branch → refused · a different branch → continues · network flaps →
 * authority does not bounce · stale/replayed heartbeat → refused · a recent ack (clock skew shape) → takeover fails
 * closed · the appliance's own handback over a dead wire stays fenced · Cloud handback only when clean.
 */
class EdgeAuthorityPartitionTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const DEVICE = 'partition-device-0001';
    private const TTL = 20;
    private const SKEW = 8;

    private static bool $provisioned = false;

    private int $cloudBranchId;
    private int $cloudOtherBranchId;
    private string $cloudDb;
    /** PLATFORM TEST-ISOLATION: the appliance DB name comes from the one resolver (per-worktree), never a literal. */
    private string $edgeDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cloudDb = (string) config('database.connections.tenant.database');
        $this->edgeDb = \Tests\MySql\Support\EdgeTestDatabases::local('authority');
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

    /** A genuinely separate appliance database, bootstrapped like a real box (binding, cashier, baseline, schema). */
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
        $this->cleanTenant(['edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_user_credentials', 'edge_local_meta', 'products', 'categories', 'terminals', 'branches', 'users']);
        $branch = $this->makeBranch(['name' => 'Appliance Branch']);
        $user = $this->makeUser(['default_branch_id' => $branch, 'employee_code' => 'PART' . Str::random(4)]);
        $this->makeTerminal($branch);
        $product = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $this->bindEdgeLocalMeta($branch, 1, deviceUuid: self::DEVICE);
        // A real import stamps the schema the box speaks; the readiness gates compare it to this build.
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema')]);
        $this->acceptTestBaseline([['product_id' => $product, 'product_variant_id' => null, 'quantity' => 10]]);
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

    /** Run ONE side of the partition as an independent OS process; returns its single output line. */
    private function side(string $role, string $db, array $args): string
    {
        $cmd = array_merge([PHP_BINARY, base_path('tests/MySql/Support/edge_authority_worker.php')], array_map('strval', $args));
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv() ?: [], [
            'ROLE' => $role,
            'EDGE_WORKER_DB' => $db,
            'EDGE_TEST_TENANT_DB' => $this->cloudDb,
            'EDGE_DEVICE_UUID' => self::DEVICE,
            'EDGE_AUTHORITY_TTL' => (string) self::TTL,
            'EDGE_AUTHORITY_SKEW_MARGIN' => (string) self::SKEW,
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

    private function assertRefused(string $out, string $why): void
    {
        $this->assertStringStartsWith('ERR:', $out, "{$why} (got: {$out})");
    }

    private function edgeGates(): array
    {
        return json_decode(substr($this->edge(['edge:gates']), 3), true);
    }

    /** Poll a side until $done says so; returns seconds waited. Fails the test past $maxSeconds. */
    private function waitUntil(callable $done, float $maxSeconds, string $what): float
    {
        $start = microtime(true);
        while (true) {
            if ($done()) {
                return microtime(true) - $start;
            }
            if (microtime(true) - $start > $maxSeconds) {
                $this->fail("Timed out after {$maxSeconds}s waiting for: {$what}");
            }
            usleep(500_000);
        }
    }

    public function test_partition_single_writer_no_split_brain(): void
    {
        $b = $this->cloudBranchId;

        // ── 1. CLOUD HEALTHY: heartbeat accepted → Cloud holds; Cloud writes, the appliance cannot. ──
        $this->assertStringStartsWith('OK:holder=cloud:fenced=0', $this->cloud(['cloud:heartbeat', $b, self::DEVICE, 1, 'standby']));
        $this->assertSame('OK:cloud-write-allowed', $this->cloud(['cloud:fence', $b]));
        $this->assertStringStartsWith('OK:acked', $this->edge(['edge:ack', self::TTL]));   // the wire delivered the ack (with the Cloud's TTL)
        $this->assertRefused($this->edge(['edge:sale-check']), 'STANDBY appliance must refuse local mutation while the Cloud holds the lease');
        // A recent ack (the clock-skew shape: the Cloud lease is still live on the appliance's own clock) → takeover fails closed even when confirmed.
        $this->assertRefused($this->edge(['edge:takeover', 'confirm']), 'takeover with a live lease must fail closed');
        $gates = $this->edgeGates();
        $this->assertFalse($gates['gates']['AUTHORITY_TAKEOVER_SAFE'], 'not safe while the lease is live locally');
        foreach (['LOCAL_DB_HEALTHY', 'CONFIG_COMPATIBLE', 'SCHEMA_COMPATIBLE', 'BRANCH_BINDING_VALID', 'LOCAL_USERS_READY', 'STOCK_AUTHORITY_READY', 'ENTITLEMENT_VALID'] as $gate) {
            $this->assertTrue($gates['gates'][$gate], "gate {$gate} is genuinely green on this box: " . json_encode($gates));
        }

        // The last heartbeat that got through (the Cloud's lease clock and the appliance's ack clock start here).
        $heartbeatAt = microtime(true);
        $this->assertStringStartsWith('OK:holder=cloud:fenced=0', $this->cloud(['cloud:heartbeat', $b, self::DEVICE, 2, 'standby']));
        $this->assertStringStartsWith('OK:acked', $this->edge(['edge:ack', self::TTL]));

        // ── 2. PARTITION BEGINS: the appliance's heartbeat cannot reach the Cloud (real transport, unreachable). ──
        $this->assertStringStartsWith('OK:failure-recorded:state=standby', $this->edge(['edge:heartbeat-fail']), 'one failed request is recorded, never a takeover');
        // Neither side creates an unsafe overlap: the Cloud still holds (lease live), the appliance still refuses.
        $this->assertSame('OK:cloud-write-allowed', $this->cloud(['cloud:fence', $b]));
        $this->assertRefused($this->edge(['edge:sale-check']), 'no local writes while the Cloud lease is live');
        $this->assertLessThan(self::TTL - 5, microtime(true) - $heartbeatAt, 'the healthy-partition checks ran well inside the lease');

        // ── 3. CLOUD LEASE BECOMES INVALID (TTL on the Cloud clock): the Cloud fences ITSELF … ──
        $this->waitUntil(fn () => str_starts_with($this->cloud(['cloud:fence', $b]), 'ERR:'), self::TTL + 5, 'the Cloud to fence the branch at lease expiry');
        $sinceHeartbeat = microtime(true) - $heartbeatAt;
        $this->assertGreaterThanOrEqual(self::TTL - 1, $sinceHeartbeat, 'the Cloud did not fence before its lease lapsed');
        // … while the appliance, whose safe boundary is TTL + skew margin, still refuses: fenced on BOTH sides, no overlap.
        $this->assertLessThan(self::TTL + self::SKEW - 2, $sinceHeartbeat, 'test pacing: still inside the appliance skew margin');
        $this->assertRefused($this->edge(['edge:sale-check']), 'appliance must NOT write before its own safe boundary');
        $this->assertRefused($this->edge(['edge:takeover', 'confirm']), 'takeover before the safe boundary fails closed');
        $this->assertFalse($this->edgeGates()['lapsed'], 'the appliance does not yet consider the lease lapsed');

        // ── 4. ONLY AFTER THE SAFE EXPIRY: supervisor-confirmed takeover; local mutation becomes allowed. ──
        $this->waitUntil(fn () => $this->edgeGates()['lapsed'] === true, self::SKEW + 6, 'the appliance to see the lease lapse on its own clock');
        $this->assertGreaterThanOrEqual(self::TTL + self::SKEW - 1, microtime(true) - $heartbeatAt, 'the appliance waited the full TTL + skew margin');
        $gates = $this->edgeGates();
        $this->assertTrue($gates['can'], 'all gates pass after the safe boundary: ' . json_encode($gates));
        $this->assertRefused($this->edge(['edge:takeover']), 'pilot posture: no takeover without supervisor confirmation');
        $this->assertRefused($this->edge(['edge:sale-check']), 'still standby until the takeover');
        $this->assertSame('OK:state=local_active', $this->edge(['edge:takeover', 'confirm']));
        $this->assertSame('OK:edge-write-allowed', $this->edge(['edge:sale-check']));
        $this->assertSame('LOCAL MODE ACTIVE', json_decode(substr($this->edge(['edge:state']), 3), true)['label']);

        // ── 5. A mobile/other Internet client tries the Cloud POS for the same branch → refused. Another branch → normal. ──
        $this->assertRefused($this->cloud(['cloud:fence', $b]), 'Cloud POS for the branch the appliance owns is refused');
        $this->assertSame('OK:cloud-write-allowed', $this->cloud(['cloud:fence', $this->cloudOtherBranchId]), 'a different branch of the same tenant continues normally');

        // ── 6. NETWORK FLAPS: heartbeats resume with the appliance asserting local_active → holder edge, no bounce. ──
        $this->assertStringStartsWith('OK:holder=edge:fenced=1', $this->cloud(['cloud:heartbeat', $b, self::DEVICE, 3, 'local_active']));
        $this->assertRefused($this->cloud(['cloud:fence', $b]), 'a resumed heartbeat never re-grants the Cloud while the appliance holds');
        $this->assertStringStartsWith('OK:holder=edge', $this->cloud(['cloud:heartbeat', $b, self::DEVICE, 4, 'standby']), 'even a standby report cannot bounce authority without handback');
        // Stale / replayed heartbeat → refused, authority untouched.
        $this->assertStringContainsString('STALE_HEARTBEAT', $this->cloud(['cloud:heartbeat', $b, self::DEVICE, 3, 'local_active']));
        $this->assertRefused($this->cloud(['cloud:fence', $b]), 'still fenced after the replay');
        $this->assertSame('OK:edge-write-allowed', $this->edge(['edge:sale-check']));

        // ── 7. HANDBACK. The appliance's own handback over a dead wire: stays fenced on BOTH sides, never flips to standby. ──
        $this->assertRefused($this->edge(['edge:handback']), 'handback without the Cloud acknowledgement must fail');
        $this->useDb($this->edgeDb);
        $this->assertSame('handing_back', (string) DB::table('edge_local_meta')->value('authority_state'), 'the appliance is fenced (handing_back) until the Cloud acknowledges');
        $this->useDb($this->cloudDb);
        $this->assertRefused($this->edge(['edge:sale-check']), 'no local writes while handing back');
        $this->assertRefused($this->cloud(['cloud:fence', $b]), 'and the Cloud is still fenced too — a fenced gap, never an overlap');
        // The Cloud side: refused while the sync is not clean; accepted when clean → Cloud writes again.
        $this->assertStringContainsString('HANDBACK_NOT_CLEAN', $this->cloud(['cloud:handback', $b, self::DEVICE, 1, 0]));
        $this->assertRefused($this->cloud(['cloud:fence', $b]), 'a refused handback leaves the Cloud fenced');
        $this->assertSame('OK:holder=cloud', $this->cloud(['cloud:handback', $b, self::DEVICE, 0, 0]));
        $this->assertSame('OK:cloud-write-allowed', $this->cloud(['cloud:fence', $b]));
        // The wire carries the acknowledgement to the appliance (what EdgeAuthorityService::handback records on a 200):
        $this->useDb($this->edgeDb);
        DB::table('edge_local_meta')->update(['authority_state' => 'standby', 'authority_last_ack_at' => now(), 'authority_state_reason' => 'handed back to Cloud (test wire)']);
        $this->useDb($this->cloudDb);
        $this->assertRefused($this->edge(['edge:sale-check']), 'back in standby the appliance refuses local mutation again');
        $this->assertSame('ONLINE', json_decode(substr($this->edge(['edge:state']), 3), true)['label']);
    }
}
