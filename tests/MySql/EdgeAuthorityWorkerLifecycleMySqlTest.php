<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Services\Edge\EdgeLocalAuthorityWorkerSupervisor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * Q — the supervised authority worker as a REAL appliance process (the Scheduled Task action): boots with the Cloud
 * master dead, ticks against an unreachable Cloud, records the failure, never takes over; one logical instance (a
 * duplicate start exits cleanly, a stale slot is taken over); cooperative stop.
 */
class EdgeAuthorityWorkerLifecycleMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        parent::setUp();
        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
                'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
                'password' => config('database.connections.tenant.password')]
        )]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_local_authority_worker_state', 'edge_local_connection_transitions', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_user_credentials', 'edge_local_meta', 'products', 'categories', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch();
        $user = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'AW' . Str::random(4)]);
        $this->makeTerminal($this->branchId);
        $product = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'worker-device');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'authority_last_ack_at' => now(), 'heartbeat_consecutive_acks' => 1]);
        $this->asBranchServerRuntime();
        $this->acceptTestBaseline([['product_id' => $product, 'product_variant_id' => null, 'quantity' => 10]]);
        $this->seedEdgeCredential($user, $this->branchId, 1);
        config([
            'edge.authority.heartbeat_url' => 'http://127.0.0.1:9/api/edge/authority/heartbeat',
            'edge.sync.device_id' => 'worker-device', 'edge.sync.device_secret' => 'secret', 'edge.sync.connect_timeout' => 1, 'edge.sync.timeout' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    /** The ENV the real Scheduled Task command line runs with (appliance DB path, Cloud master DEAD, secrets from env not argv). */
    private function applianceEnv(): array
    {
        $t = config('database.connections.tenant');

        return array_merge(getenv() ?: [], [
            'APP_ENV' => 'testing',
            'APP_ROLE' => 'branch_server',
            'EDGE_LOCAL_APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
            'EDGE_DB_HOST' => (string) $t['host'],
            'EDGE_DB_PORT' => (string) $t['port'],
            'EDGE_DB_DATABASE' => $this->tenantDb,
            'EDGE_DB_USERNAME' => (string) $t['username'],
            'EDGE_DB_PASSWORD' => (string) ($t['password'] ?? ''),
            'DB_DATABASE' => 'nonexistent_master_authority_worker',
            'EDGE_AUTHORITY_HEARTBEAT_URL' => 'http://127.0.0.1:9/api/edge/authority/heartbeat',
            'EDGE_AUTHORITY_HANDBACK_URL' => 'http://127.0.0.1:9/api/edge/authority/handback',
            'EDGE_SYNC_DEVICE_ID' => 'worker-device',
            'EDGE_SYNC_DEVICE_SECRET' => 'secret-from-env-never-argv',
            'EDGE_SYNC_CONNECT_TIMEOUT' => '1',
            'EDGE_SYNC_TIMEOUT' => '2',
        ]);
    }

    private function spawn(array $args): array
    {
        $cmd = array_merge([PHP_BINARY, base_path('artisan'), 'edge:local:authority-worker'], $args);
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $this->applianceEnv());

        return ['proc' => $proc, 'pipes' => $pipes, 'cmd' => implode(' ', $cmd)];
    }

    private function finish(array $h): array
    {
        $out = trim(stream_get_contents($h['pipes'][1]));
        $err = trim(stream_get_contents($h['pipes'][2]) ?: '');
        fclose($h['pipes'][1]);
        fclose($h['pipes'][2]);
        $code = proc_close($h['proc']);

        return ['code' => $code, 'out' => $out !== '' ? $out : $err];
    }

    private function workerRow(): ?object
    {
        return DB::connection('tenant')->table(EdgeLocalAuthorityWorkerSupervisor::TABLE)->first();
    }

    public function test_real_supervised_tick_records_a_failed_heartbeat_and_never_takes_over(): void
    {
        $res = $this->finish($this->spawn(['--once']));
        $this->assertSame(0, $res['code'], $res['out']);
        $this->assertStringContainsString('tick 1: miss', $res['out'], $res['out']);
        $this->assertStringNotContainsString('secret-from-env-never-argv', $this->spawn(['--stop', '--stop-wait=1'])['cmd'], 'no secret on the command line');
        $meta = EdgeLocalMeta::on('tenant')->firstOrFail();
        $this->assertSame(1, (int) $meta->heartbeat_consecutive_failures, 'the failure was recorded');
        $this->assertSame('standby', (string) $meta->authority_state, 'one failed heartbeat NEVER triggers a takeover');
        $this->assertSame('online', (string) $meta->connection_state, 'one failed heartbeat is a blip');
        $row = $this->workerRow();
        $this->assertSame('stopped', $row->state);
        $this->assertNotNull($row->heartbeat_at);
        $this->assertStringContainsString('miss online', (string) $row->last_tick_outcome);
        $this->assertNotNull($row->stopped_at);
    }

    public function test_duplicate_worker_exits_cleanly_and_a_stale_slot_is_taken_over(): void
    {
        DB::connection('tenant')->table(EdgeLocalAuthorityWorkerSupervisor::TABLE)->insert([
            'singleton_guard' => 1, 'state' => 'running', 'worker_uuid' => 'live-worker', 'started_at' => now(), 'heartbeat_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(0, Artisan::call('edge:local:authority-worker', ['--once' => true]));
        $this->assertStringContainsString('already RUNNING', Artisan::output());
        $this->assertSame('live-worker', $this->workerRow()->worker_uuid, 'the live worker keeps the slot');
        $this->assertNull($this->workerRow()->last_tick_at, 'the refused duplicate did not tick');
        $this->assertSame(0, (int) EdgeLocalMeta::on('tenant')->value('heartbeat_consecutive_failures'), 'no heartbeat was sent by the duplicate');

        DB::connection('tenant')->table(EdgeLocalAuthorityWorkerSupervisor::TABLE)->update(['heartbeat_at' => now()->subSeconds(EdgeLocalAuthorityWorkerSupervisor::HEARTBEAT_STALE_SECONDS + 5)]);
        $this->assertSame(0, Artisan::call('edge:local:authority-worker', ['--once' => true]));
        $this->assertNotSame('live-worker', $this->workerRow()->worker_uuid, 'the stale slot was taken over by a supervised restart');
        $this->assertNotNull($this->workerRow()->last_tick_at);
        $this->assertSame(1, (int) EdgeLocalMeta::on('tenant')->value('heartbeat_consecutive_failures'));
    }

    public function test_cooperative_stop_ends_a_running_worker(): void
    {
        $h = $this->spawn(['--interval=5', '--max-ticks=20']);
        $deadline = microtime(true) + 25;
        while (microtime(true) < $deadline && (($this->workerRow()?->state ?? 'stopped') !== 'running')) {
            usleep(250_000);
        }
        $this->assertSame('running', $this->workerRow()->state, 'the worker claimed the slot');
        $stop = $this->finish($this->spawn(['--stop', '--stop-wait=20']));
        $this->assertSame(0, $stop['code'], $stop['out']);
        $this->assertStringContainsString('stopped gracefully', $stop['out'], $stop['out']);
        $res = $this->finish($h);
        $this->assertSame(0, $res['code'], $res['out']);
        $this->assertStringContainsString('cooperative stop requested', $res['out']);
        $this->assertSame('stopped', $this->workerRow()->state);
        $this->assertSame('standby', (string) EdgeLocalMeta::on('tenant')->value('authority_state'));
    }
}
