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
        foreach (['Install-EdgePrintWorkerTask.ps1', 'Install-EdgeSyncSenderTask.ps1', 'Install-EdgeBackupTask.ps1'] as $script) {
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
