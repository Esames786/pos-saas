<?php

namespace Tests\MySql;

use App\Services\Edge\EdgeSupervisionPlan;
use App\Services\Edge\EdgeWorkerBootstrap;
use Illuminate\Support\Facades\DB;

/**
 * OFFLINE EDGE PRODUCTIZATION (J) — Windows worker supervision, contract tests.
 *
 * Proves the deterministic supervision plan: branch_server-only; every scheduled task runs an EDGE-allowlisted
 * command (never a Cloud command); least-privilege identity (never SYSTEM, non-elevated); one logical instance
 * per worker; boot start + bounded restart; a bounded DB-startup wait; and NO secret on any command line. Also
 * scans the generated PowerShell task installers for the SYSTEM refusal + secret-freeness. Physical Windows
 * boot/reboot/crash certification remains separate (PHYSICAL_WINDOWS_CERTIFIED=no).
 */
class EdgeSupervisionMySqlTest extends MySqlTenantTestCase
{
    private string $php = 'C:\\Program Files\\Bingoo Edge\\php\\php.exe';
    private string $root = 'C:\\Program Files\\Bingoo Edge\\app';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.role' => 'branch_server']);
    }

    protected function tearDown(): void
    {
        config(['app.role' => null]);
        parent::tearDown();
    }

    private function plan(): array
    {
        return app(EdgeSupervisionPlan::class)->tasks($this->php, $this->root);
    }

    public function test_plan_is_deterministic_and_branch_server_only(): void
    {
        $this->assertEquals($this->plan(), $this->plan(), 'the plan is deterministic');

        config(['app.role' => null]); // cloud
        $this->expectExceptionMessage('SUPERVISION_NOT_BRANCH_SERVER');
        app(EdgeSupervisionPlan::class)->tasks($this->php, $this->root);
    }

    public function test_every_task_runs_an_edge_allowlisted_command_and_no_cloud_command(): void
    {
        $commands = array_column($this->plan(), 'artisan_command');
        $this->assertContains('edge:local:print-worker', $commands);
        $this->assertContains('edge:local:sync-send', $commands);
        $this->assertContains('edge:local:backup', $commands);
        $this->assertContains('edge:local:authority-worker', $commands, 'Q: the heartbeat/state-machine/freshness worker is a supervised appliance responsibility');
        $this->assertContains('edge:local:serve', $commands, 'P4: the web runtime is a supervised appliance responsibility');
        foreach ($commands as $c) {
            $this->assertTrue(\App\Support\EdgeConsoleBoundary::isAllowed($c), "{$c} must be Edge-allowlisted");
        }
        // No destructive/Cloud command is ever scheduled.
        $this->assertNotContains('migrate:fresh', $commands);
        $this->assertNotContains('tenant:provision', $commands);
    }

    public function test_least_privilege_identity_and_boot_restart_policy(): void
    {
        foreach ($this->plan() as $t) {
            $this->assertSame('NT AUTHORITY\\LOCAL SERVICE', $t['principal']);
            $this->assertNotSame('NT AUTHORITY\\SYSTEM', $t['principal']);
            $this->assertSame('limited', $t['run_level'], 'non-elevated');
            $this->assertSame($this->root, $t['working_directory']);
            $this->assertSame($this->php, $t['executable']);
            $this->assertSame('at_startup', $t['trigger']);
            $this->assertSame(999, $t['restart_count']);
            $this->assertTrue($t['startup_db_retry']);
            $this->assertSame('cooperative', $t['stop']);
        }
    }

    public function test_one_logical_instance_per_worker(): void
    {
        $byName = collect($this->plan())->keyBy('name');
        $this->assertSame(EdgeSupervisionPlan::SINGLETON_HEARTBEAT, $byName['BingooEdgePrintWorker']['singleton']);
        $this->assertSame(EdgeSupervisionPlan::SINGLETON_OUTBOX_LEASE, $byName['BingooEdgeSyncSender']['singleton']);
        $this->assertSame(EdgeSupervisionPlan::SINGLETON_BACKUP_LOCK, $byName['BingooEdgeBackup']['singleton']);
        $this->assertSame(2, $byName['BingooEdgeSyncSender']['repeat_minutes']);
        $this->assertSame(60, $byName['BingooEdgeBackup']['repeat_minutes']);
        $this->assertSame('continuous', $byName['BingooEdgePrintWorker']['kind']);
        // Q — ONE logical authority worker: continuous, single instance, no overlapping runs.
        $this->assertSame(EdgeSupervisionPlan::SINGLETON_AUTHORITY_WORKER, $byName['BingooEdgeAuthorityWorker']['singleton']);
        $this->assertSame('continuous', $byName['BingooEdgeAuthorityWorker']['kind']);
        $this->assertCount(1, array_filter($this->plan(), fn ($t) => $t['artisan_command'] === 'edge:local:authority-worker'), 'exactly one heartbeat schedule');
        $this->assertCount(1, array_filter($this->plan(), fn ($t) => $t['artisan_command'] === 'edge:local:print-worker'), 'exactly one print worker (never two agents on one printer)');
    }

    /** P4 §2 — the web runtime: N loopback backends, one listen port each, fronted by the ONE non-artisan gateway process. */
    public function test_p4_web_backends_are_loopback_only_one_port_each_and_the_gateway_owns_the_lan_listener(): void
    {
        config(['edge.web.workers' => 2, 'edge.web.port_base' => 8090, 'edge.gateway.https_port' => 443, 'edge.gateway.http_port' => 80]);
        $web = array_values(array_filter($this->plan(), fn ($t) => $t['artisan_command'] === 'edge:local:serve'));
        $this->assertCount(2, $web);
        $this->assertSame(['BingooEdgeWeb1', 'BingooEdgeWeb2'], array_column($web, 'name'));
        $this->assertSame(['127.0.0.1:8090', '127.0.0.1:8091'], array_column($web, 'listen'), 'backends bind LOOPBACK only');
        $this->assertCount(2, array_unique(array_column($web, 'listen')), 'one port per backend — the OS makes it a singleton');
        foreach ($web as $i => $t) {
            $this->assertSame(EdgeSupervisionPlan::SINGLETON_LISTEN_PORT, $t['singleton']);
            $this->assertSame('continuous', $t['kind']);
            $this->assertStringContainsString('--worker=' . ($i + 1), $t['arguments']);
            $this->assertSame('NT AUTHORITY\\LOCAL SERVICE', $t['principal']);
        }
        $plan = app(EdgeSupervisionPlan::class);
        $gw = $plan->gateway('C:\\Program Files\\Bingoo Edge\\gateway\\nginx.exe', 'C:\\ProgramData\\BingooEdge');
        $this->assertSame(EdgeSupervisionPlan::GATEWAY_TASK, $gw['name']);
        $this->assertSame('gateway', $gw['kind_of_process']);
        $this->assertSame('0.0.0.0:443', $gw['listen'], 'the gateway is the ONLY LAN listener');
        $this->assertSame('0.0.0.0:80', $gw['redirect_listen']);
        $this->assertSame('NT AUTHORITY\\LOCAL SERVICE', $gw['principal']);
        $this->assertSame('limited', $gw['run_level']);
        $this->assertSame(999, $gw['restart_count']);
        $this->assertStringContainsString('nginx.conf', $gw['arguments']);
        $this->assertStringNotContainsString('secret', strtolower($gw['arguments']));
        // The rendered gateway config: TLS with the data-root certificate, proxy to every backend, 80 → 443 only.
        $conf = $plan->renderGatewayConfig('C:\\ProgramData\\BingooEdge', 'C:\\Program Files\\Bingoo Edge');
        $this->assertStringContainsString('listen 443 ssl http2;', $conf);
        $this->assertStringContainsString('return 301 https://', $conf);
        $this->assertStringContainsString('server 127.0.0.1:8090', $conf);
        $this->assertStringContainsString('server 127.0.0.1:8091', $conf);
        $this->assertStringContainsString('ssl_certificate     "C:/ProgramData/BingooEdge/certs/server.crt"', $conf);
        $this->assertStringContainsString('X-Forwarded-Proto https', $conf);
        $this->assertStringNotContainsString('listen 8090', $conf, 'the gateway never exposes a backend port on the LAN');
        // Every task is an artisan task; the gateway is the ONE exception and is described separately.
        foreach ($this->plan() as $t) {
            $this->assertSame('artisan', $t['kind_of_process']);
        }
    }

    /** P4 §2 — the serve command refuses a LAN bind (plain HTTP is never the normal mode) and resolves ports from the plan. */
    public function test_p4_serve_command_binds_loopback_only_and_refuses_a_lan_bind(): void
    {
        config(['edge.web.port_base' => 8090, 'app.key' => config('app.key') ?: 'base64:' . base64_encode(random_bytes(32))]);
        $this->artisan('edge:local:serve', ['--worker' => 2, '--check' => true])->expectsOutputToContain('http://127.0.0.1:8091')->assertExitCode(0);
        $this->artisan('edge:local:serve', ['--worker' => 1, '--host' => '0.0.0.0', '--check' => true])->expectsOutputToContain('Refusing a non-loopback bind')->assertExitCode(1);
    }

    /** P4 §2 — the installer consumes the plan as JSON; a Cloud host gets nothing. */
    public function test_p4_service_plan_command_emits_the_plan_and_renders_the_gateway_config(): void
    {
        $dataRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-plan-' . uniqid();
        $this->artisan('edge:local:service-plan', ['--php' => $this->php, '--app-root' => $this->root, '--data-root' => $dataRoot, '--write-gateway-config' => true, '--json' => true])->assertExitCode(0);
        $this->assertFileExists($dataRoot . DIRECTORY_SEPARATOR . 'gateway' . DIRECTORY_SEPARATOR . 'nginx.conf');
        $this->assertDirectoryExists($dataRoot . DIRECTORY_SEPARATOR . 'gateway' . DIRECTORY_SEPARATOR . 'logs');
        config(['app.role' => null]);
        $this->artisan('edge:local:service-plan', ['--data-root' => $dataRoot, '--json' => true])->assertExitCode(1);
        config(['app.role' => 'branch_server']);
        @unlink($dataRoot . '/gateway/nginx.conf');
        foreach (['logs', 'temp', 'client_body_temp', 'proxy_temp', 'fastcgi_temp', 'uwsgi_temp', 'scgi_temp'] as $d) {
            @rmdir($dataRoot . '/gateway/' . $d);
        }
        @rmdir($dataRoot . '/gateway');
        @rmdir($dataRoot);
    }

    public function test_no_secret_ever_appears_on_a_command_line(): void
    {
        config(['edge.sync.device_secret' => 'TOP-SECRET-DEVICE', 'edge.backup.recovery_key' => 'TOP-SECRET-KEY']);
        foreach ($this->plan() as $t) {
            $this->assertStringNotContainsString('TOP-SECRET-DEVICE', $t['arguments']);
            $this->assertStringNotContainsString('TOP-SECRET-KEY', $t['arguments']);
            // arguments are only the artisan entrypoint + command name.
            $this->assertStringContainsString($t['artisan_command'], $t['arguments']);
            $this->assertStringContainsString('artisan', $t['arguments']);
        }
    }

    public function test_bounded_db_startup_wait_returns_ready_when_the_db_answers(): void
    {
        // The tenant DB is up in the test → ready on the first try; the call is bounded (never spins).
        $this->assertTrue(EdgeWorkerBootstrap::awaitDatabase(1, 0));
    }

    public function test_generated_task_installers_refuse_system_and_carry_no_secret(): void
    {
        foreach (['Install-EdgePrintWorkerTask.ps1', 'Install-EdgeSyncSenderTask.ps1', 'Install-EdgeBackupTask.ps1', 'Register-EdgeServices.ps1'] as $script) {
            $body = (string) file_get_contents(base_path('scripts/edge/' . $script));
            $this->assertStringContainsString('Refusing to register', $body, "{$script} must refuse SYSTEM");
            $this->assertStringContainsString('SYSTEM', $body, "{$script} must guard against the SYSTEM principal");
            // No secret / password LITERAL is embedded (a bare '-Password there' mention in prose is fine;
            // an actual quoted value would be a leak).
            $this->assertDoesNotMatchRegularExpression('/-Password\s+["\']\S/', $body, "{$script} must not embed a password literal");
            $this->assertStringNotContainsString('device_secret', $body);
        }
    }
}
