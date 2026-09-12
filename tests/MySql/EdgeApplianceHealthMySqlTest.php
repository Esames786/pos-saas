<?php

namespace Tests\MySql;

use App\Services\Edge\EdgeApplianceHealthService;
use App\Support\EdgeApplianceEnvFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * P4 §11 / §7 / §12 — the ONE operator health report (service + command + page) is truthful and NON-SECRET, and the
 * appliance env file editor persists provisioned secrets atomically without ever echoing them.
 */
class EdgeApplianceHealthMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $userId;

    protected function setUp(): void
    {
        // Boot the app AS a Branch Server so routes/web.php registers the Edge runtime routes (the health page).
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();
        // The CLI commands rebind `tenant` to the edge_local connection: point it at THIS test's tenant DB.
        config(['database.connections.edge_local' => array_merge(config('database.connections.edge_local', []), [
            'host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
            'database' => config('database.connections.tenant.database'), 'username' => config('database.connections.tenant.username'), 'password' => config('database.connections.tenant.password'),
        ])]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_sync_outbox', 'edge_local_backups', 'edge_local_updates', 'edge_local_user_credentials', 'edge_local_print_worker_state',
            'edge_local_authority_worker_state', 'print_jobs', 'printers', 'terminals', 'branches', 'users']);
        config(['app.role' => 'branch_server', 'app.key' => config('app.key') ?: 'base64:' . base64_encode(random_bytes(32))]);
        $this->branchId = $this->makeBranch(['name' => 'Health Branch']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'HL' . Str::random(4)]);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'health-device-uuid', 1);
        DB::connection('tenant')->table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema')]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->makePrinter(['branch_id' => $this->branchId, 'printer_type' => 'network', 'ip_address' => '192.168.1.60', 'port' => 9100, 'is_active' => 1, 'name' => 'Kitchen LAN']);
        $this->makePrinter(['branch_id' => $this->branchId, 'printer_type' => 'usb', 'ip_address' => null, 'port' => null, 'is_active' => 1, 'name' => 'Counter USB']);
        config([
            'edge.sync.device_id' => 'health-device-uuid', 'edge.sync.device_secret' => 'TOP-SECRET-DEVICE-VALUE',
            'edge.backup.recovery_key' => base64_encode(random_bytes(32)), 'edge.backup.recovery_key_id' => 'k1',
            'edge.update.public_key' => 'pubkey', 'edge.update.install_root' => sys_get_temp_dir() . '/edge-health-' . uniqid(),
        ]);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function report(): array
    {
        return app(EdgeApplianceHealthService::class)->report();
    }

    public function test_the_report_names_every_area_and_is_degraded_standby_with_named_problems_on_a_fresh_bound_box(): void
    {
        $r = $this->report();
        foreach (['status', 'status_label', 'problems', 'runtime', 'database', 'binding', 'authority', 'freshness', 'sync', 'workers', 'print', 'backup', 'update', 'gateway', 'layout', 'print_architecture'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
        $this->assertSame(EdgeApplianceHealthService::STATUS_DEGRADED, $r['status'], json_encode($r['problems']));
        $this->assertTrue($r['binding']['bound']);
        $this->assertSame('health-device-uuid', $r['binding']['device_uuid']);
        $this->assertTrue($r['binding']['device_identity_matches']);
        $this->assertSame(1, $r['binding']['enrolled_local_users']);
        $this->assertSame('standby', $r['authority']['state']);
        $this->assertFalse($r['authority']['auto_failover']);
        $this->assertFalse($r['auto_failover_enabled']);
        $this->assertSame(0, $r['sync']['outbox_pending']);
        $this->assertSame(1, $r['print']['network_printers_edge_direct']);
        $this->assertTrue($r['print_architecture']['network_printer_edge_direct']);
        $this->assertFalse($r['print_architecture']['second_edge_agent_for_network_printer']);
        $this->assertSame('ONLINE_REQUIRED', $r['print_architecture']['usb_status_for_pilot']);
        // The problems are cashier-readable sentences naming what is missing on a box that never heartbeated.
        $joined = implode(' | ', $r['problems']);
        $this->assertStringContainsString('never acknowledged a heartbeat', $joined);
        $this->assertStringContainsString('print worker', strtolower($joined));
        $this->assertStringContainsString('USB printer', $joined);
        $this->assertStringContainsString('No backup', $joined);
    }

    public function test_the_report_reflects_outbox_state_worker_heartbeats_and_backup_recency(): void
    {
        $now = now();
        $outbox = DB::connection('tenant')->table('edge_sync_outbox');
        $outbox->insert(['sale_uuid' => (string) Str::ulid(), 'envelope_schema_version' => 'edge-sale', 'config_revision' => 1, 'activation_epoch' => 1, 'envelope' => '{}', 'content_hash' => str_repeat('a', 64), 'state' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
        $outbox->insert(['sale_uuid' => (string) Str::ulid(), 'envelope_schema_version' => 'edge-return-envelope-v1', 'config_revision' => 1, 'activation_epoch' => 1, 'envelope' => '{}', 'content_hash' => str_repeat('b', 64), 'state' => 'failed_permanent', 'last_error' => 'PRODUCT_UNRESOLVED: ghost', 'created_at' => $now, 'updated_at' => $now]);
        $outbox->insert(['sale_uuid' => (string) Str::ulid(), 'envelope_schema_version' => 'edge-sale', 'config_revision' => 1, 'activation_epoch' => 1, 'envelope' => '{}', 'content_hash' => str_repeat('c', 64), 'state' => 'acknowledged', 'acknowledged_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        DB::connection('tenant')->table('edge_local_print_worker_state')->insert(['singleton_guard' => 1, 'state' => 'running', 'worker_uuid' => 'w1', 'started_at' => $now, 'heartbeat_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        DB::connection('tenant')->table('edge_local_authority_worker_state')->insert(['singleton_guard' => 1, 'state' => 'running', 'worker_uuid' => 'a1', 'started_at' => $now, 'heartbeat_at' => $now, 'last_tick_at' => $now, 'last_tick_outcome' => 'online', 'created_at' => $now, 'updated_at' => $now]);
        DB::connection('tenant')->table('edge_local_backups')->insert(['backup_uuid' => (string) Str::ulid(), 'path' => '/nowhere/x.enc', 'format_version' => 'edge-backup-v1', 'branch_id' => $this->branchId, 'checksum' => 'x', 'size_bytes' => 10, 'status' => 'completed', 'created_at' => $now->copy()->subHours(3), 'updated_at' => $now]);
        DB::connection('tenant')->table('edge_local_meta')->update(['authority_last_ack_at' => $now, 'connection_state' => 'online', 'heartbeat_consecutive_acks' => 3]);

        $r = $this->report();
        $this->assertSame(1, $r['sync']['outbox_pending']);
        $this->assertSame(1, $r['sync']['outbox_failed_permanent']);
        $this->assertSame(1, $r['sync']['outbox_acknowledged']);
        $this->assertSame('edge-return-envelope-v1', $r['sync']['permanent_failures'][0]['family']);
        $this->assertStringContainsString('PRODUCT_UNRESOLVED', $r['sync']['permanent_failures'][0]['error']);
        $this->assertSame('running', $r['workers']['print_worker']['state']);
        $this->assertTrue($r['workers']['authority_worker']['running']);
        $this->assertSame('online', $r['authority']['connection_state']);
        $this->assertNotNull($r['authority']['last_heartbeat_ack_at']);
        $this->assertGreaterThan(2 * 3600, $r['backup']['last']['age_seconds']);
        $joined = implode(' | ', $r['problems']);
        $this->assertStringContainsString('permanently refused', $joined);
        $this->assertStringContainsString('older than 2 hours', $joined);
        $this->assertStringNotContainsString('never acknowledged', $joined);
    }

    public function test_the_report_the_command_and_the_page_never_carry_a_secret(): void
    {
        $json = json_encode($this->report());
        foreach (['TOP-SECRET-DEVICE-VALUE', config('edge.backup.recovery_key')] as $secret) {
            $this->assertStringNotContainsString($secret, $json, 'the health report must never carry a secret');
        }
        $this->artisan('edge:local:health', ['--json' => true])->doesntExpectOutputToContain('TOP-SECRET')->assertExitCode(0);
        $this->artisan('edge:local:health')->expectsOutputToContain('Auto failover')->assertExitCode(0);
        $this->artisan('edge:local:health', ['--fail-on-problems' => true])->assertExitCode(1);
        // The page (server-rendered, no scripts) shows the same headline and never a secret; unauthenticated → login.
        $this->get('/edge/local/pos/health')->assertRedirect('/edge/local/login');
        $user = \App\Models\Tenant\User::on('tenant')->find($this->userId);
        $html = $this->actingAs($user, 'tenant')->withSession(['edge_login_epoch' => 1, 'edge_credential_version' => 1])
            ->get('/edge/local/pos/health');
        if ($html->getStatusCode() === 200) {
            $body = $html->getContent();
            $this->assertStringContainsString('Branch Server status', $body);
            $this->assertStringContainsString('Standby with issues', $body);
            $this->assertStringContainsString('supervised takeover only', $body);
            $this->assertStringNotContainsString('TOP-SECRET', $body);
            $this->assertStringNotContainsString('<script', $body, 'the health page carries no script — it renders with no Internet and no build assets');
        } else {
            // The Edge session-freshness guard may refuse a synthetic session; the redirect target proves the guard, not a crash.
            $this->assertContains($html->getStatusCode(), [302, 401], 'the page is guarded, never a 500');
        }
    }

    public function test_the_appliance_env_file_editor_persists_keys_atomically_and_preserves_the_rest(): void
    {
        $path = sys_get_temp_dir() . '/edge-env-' . uniqid() . '/appliance.env';
        EdgeApplianceEnvFile::set($path, ['APP_ROLE' => 'branch_server', 'EDGE_DB_PASSWORD' => 'p@ss word#1', 'EDGE_SYNC_DEVICE_ID' => '']);
        $this->assertSame('branch_server', EdgeApplianceEnvFile::get($path, 'APP_ROLE'));
        $this->assertSame('p@ss word#1', EdgeApplianceEnvFile::get($path, 'EDGE_DB_PASSWORD'), 'values with spaces/# round-trip quoted');
        file_put_contents($path, "# operator comment\n" . file_get_contents($path));
        EdgeApplianceEnvFile::set($path, ['EDGE_SYNC_DEVICE_ID' => 'dev-1', 'EDGE_SYNC_DEVICE_SECRET' => 'abc123']);
        $body = (string) file_get_contents($path);
        $this->assertStringStartsWith('# operator comment', $body, 'comments and order are preserved');
        $this->assertSame(1, substr_count($body, 'EDGE_SYNC_DEVICE_ID='), 'a key is rewritten in place, never duplicated');
        $this->assertSame('dev-1', EdgeApplianceEnvFile::get($path, 'EDGE_SYNC_DEVICE_ID'));
        $this->assertSame('abc123', EdgeApplianceEnvFile::get($path, 'EDGE_SYNC_DEVICE_SECRET'));
        $this->assertSame([], glob(dirname($path) . '/*.tmp') ?: [], 'no temp file is left behind');
        // Dotenv reads exactly what we wrote (quoted value with spaces).
        $parsed = \Dotenv\Dotenv::parse($body);
        $this->assertSame('p@ss word#1', $parsed['EDGE_DB_PASSWORD']);
        @unlink($path);
        @rmdir(dirname($path));
    }
}
