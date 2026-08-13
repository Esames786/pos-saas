<?php

namespace Tests\Feature\Edge;

use App\Support\EdgeConsoleBoundary;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * EDGE-LOCAL-RUNTIME-1 — DB-agnostic boundary/guard/census/readiness proofs (fast suite).
 * The provisioning/import behaviours that need a real InnoDB engine live in the authoritative MySQL
 * suite (tests/MySql).
 */
class EdgeLocalRuntimeBoundaryTest extends TestCase
{
    private function asBranchServer(): void
    {
        config(['app.role' => 'branch_server']);
    }

    // ---- C. Local DB safety guard -------------------------------------------------------------

    public function test_edge_local_target_is_safe_only_for_branch_server_loopback_edge_db(): void
    {
        $this->asBranchServer();
        config([
            'database.connections.edge_local.host' => '127.0.0.1',
            'database.connections.edge_local.database' => 'bingoo_edge_local',
        ]);
        $this->assertNull(EdgeLocalDatabase::unsafeReason());
        $this->assertTrue(EdgeLocalDatabase::isSafeTarget());
    }

    public function test_edge_local_refuses_cloud_and_tenant_database_names(): void
    {
        $this->asBranchServer();
        config(['database.connections.edge_local.host' => '127.0.0.1']);

        foreach (['pos_saas_master', 'pos_tenant_demo', 'pos_tenant_retaildemo', 'mysql', ''] as $bad) {
            config(['database.connections.edge_local.database' => $bad]);
            $this->assertNotNull(EdgeLocalDatabase::unsafeReason(), "[$bad] must be refused as an Edge-local target.");
        }
    }

    public function test_edge_local_refuses_remote_host_without_test_override(): void
    {
        $this->asBranchServer();
        config([
            'database.connections.edge_local.host' => '10.0.0.5',
            'database.connections.edge_local.database' => 'bingoo_edge_local',
        ]);
        $this->assertNotNull(EdgeLocalDatabase::unsafeReason(), 'A non-loopback host must be refused without the explicit test override.');
    }

    public function test_edge_local_refused_on_cloud_runtime(): void
    {
        config([
            'app.role' => 'cloud',
            'database.connections.edge_local.host' => '127.0.0.1',
            'database.connections.edge_local.database' => 'bingoo_edge_local',
        ]);
        $this->assertNotNull(EdgeLocalDatabase::unsafeReason(), 'A cloud runtime must never target an Edge-local DB.');
    }

    public function test_edge_test_database_name_is_accepted(): void
    {
        $this->asBranchServer();
        config([
            'database.connections.edge_local.host' => '127.0.0.1',
            // PLATFORM TEST-ISOLATION: the resolver is the one owner of the Edge-local test DB name;
            // this also proves the env-resolved name passes the runtime safety rules end-to-end.
            'database.connections.edge_local.database' => \Tests\MySql\Support\EdgeTestDatabases::local(),
        ]);
        $this->assertTrue(EdgeLocalDatabase::isSafeTarget(), 'The dedicated Edge test DB must be a valid target.');
    }

    // ---- E. CLI boundary (default deny) -------------------------------------------------------

    public function test_branch_server_denies_cloud_only_commands(): void
    {
        $this->asBranchServer();
        foreach (['system:reset', 'demo:reset-all', 'demo:reset', 'tenants:provision-demo', 'tenants:backup', 'migrate', 'migrate:fresh', 'db:wipe'] as $cmd) {
            $this->assertFalse(EdgeConsoleBoundary::isAllowed($cmd), "[$cmd] must be denied on a Branch Server.");
        }
    }

    public function test_branch_server_allows_edge_local_and_cache_commands(): void
    {
        $this->asBranchServer();
        foreach (['edge:local:db-init', 'edge:local:bootstrap-import', 'edge:local:status', 'config:cache', 'route:cache', 'optimize:clear'] as $cmd) {
            $this->assertTrue(EdgeConsoleBoundary::isAllowed($cmd), "[$cmd] must be allowed on a Branch Server.");
        }
    }

    public function test_cloud_allows_all_commands(): void
    {
        config(['app.role' => 'cloud']);
        foreach (['system:reset', 'demo:reset-all', 'migrate'] as $cmd) {
            $this->assertTrue(EdgeConsoleBoundary::isAllowed($cmd), 'Cloud must not restrict commands.');
        }
    }

    public function test_cli_census_denies_every_destructive_command_on_branch_server(): void
    {
        $this->asBranchServer();
        $all = array_keys(app(\Illuminate\Contracts\Console\Kernel::class)->all());
        $denied = EdgeConsoleBoundary::deniedFrom($all);

        foreach (['system:reset', 'demo:reset-all', 'tenants:provision-demo', 'tenants:backup', 'migrate'] as $mustDeny) {
            if (in_array($mustDeny, $all, true)) {
                $this->assertContains($mustDeny, $denied, "[$mustDeny] must appear in the denied census.");
            }
        }
        foreach (['edge:local:db-init', 'edge:local:status'] as $mustAllow) {
            $this->assertNotContains($mustAllow, $denied, "[$mustAllow] must NOT be denied.");
        }
    }

    // ---- E. Scheduler census ------------------------------------------------------------------

    public function test_branch_server_schedules_no_cloud_jobs(): void
    {
        // Re-evaluate routes/console.php with a fresh Schedule under each role and enumerate events.
        $branchEvents = $this->scheduledCommandsUnderRole('branch_server');
        $this->assertSame([], $branchEvents, 'A Branch Server must schedule no Cloud jobs: ' . implode(', ', $branchEvents));

        $cloudEvents = $this->scheduledCommandsUnderRole('cloud');
        $this->assertNotEmpty($cloudEvents, 'Cloud must still schedule its jobs (control).');
    }

    /** @return array<int,string> */
    private function scheduledCommandsUnderRole(string $role): array
    {
        config(['app.role' => $role]);
        $schedule = new Schedule();
        $this->app->instance(Schedule::class, $schedule);
        // The Schedule facade caches its resolved instance from bootstrap; clear it so routes/console.php
        // schedules into OUR fresh instance under the role we are testing.
        \Illuminate\Support\Facades\Schedule::clearResolvedInstances();

        require base_path('routes/console.php');

        return collect($schedule->events())
            ->map(fn ($e) => $e->command ?? '')
            ->filter()
            // Drop the framework's own artisan binary prefix so we compare command tails.
            ->map(fn ($c) => trim(preg_replace('/^.*artisan[\'"]?\s*/', '', $c)))
            ->values()
            ->all();
    }

    // ---- F. App-key fail-closed ---------------------------------------------------------------

    public function test_branch_server_without_local_app_key_fails_boot(): void
    {
        $this->asBranchServer();
        config([
            'app.key' => '',
            'edge.app_version' => '0.1.0-edge',
            'edge.bootstrap_schema' => 'edge-bootstrap-v4',
            'edge.artifact_format_version' => '1',
        ]);
        $problems = EdgeRuntime::bootProblems();
        $this->assertNotEmpty($problems);
        $this->assertTrue(
            collect($problems)->contains(fn ($p) => str_contains($p, 'EDGE_LOCAL_APP_KEY')),
            'Missing local app key must be a fail-closed boot problem.'
        );
    }

    // ---- S + L. Readiness never claims selling readiness --------------------------------------

    public function test_readiness_never_reports_operational_stock_ready(): void
    {
        $this->asBranchServer();
        $report = app(\App\Services\Edge\EdgeLocalReadiness::class)->report();
        $this->assertSame('not_ready', $report['operational_stock']);
        $this->assertFalse($report['activation_ready']);
        // EDGE-LOCAL-POS-1: local_pos is now a truthful runtime state; with no binding/auth it must
        // be the fail-closed floor — and never a selling claim (activation_ready above stays false).
        $this->assertSame('not_ready', $report['local_pos']);
    }
}
