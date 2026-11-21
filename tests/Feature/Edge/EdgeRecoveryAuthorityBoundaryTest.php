<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\EdgeArtifactBuilder;
use App\Services\Edge\EdgeBackupRecoveryAuthority;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * P5B §3 — the Cloud backup recovery authority is hosted by the Cloud ONLY; the appliance ships the client side.
 */
class EdgeRecoveryAuthorityBoundaryTest extends TestCase
{
    public function test_the_authority_is_physically_excluded_and_the_appliance_client_ships(): void
    {
        $plan = EdgeArtifactBuilder::fromConfig()->plan(base_path());
        foreach ([
            'app/Services/Edge/EdgeBackupRecoveryAuthority.php',
            'app/Http/Controllers/Edge/EdgeBackupRecoveryApiController.php',
            'app/Console/Commands/EdgeRecoveryKeyCommand.php',
            'app/Models/Master/EdgeBackupRecoveryKey.php',
            'app/Models/Master/EdgeBackupRecoveryAudit.php',
        ] as $never) {
            $this->assertNotContains($never, $plan, "Cloud recovery authority must stay out of the appliance: {$never}");
        }
        foreach ([
            'app/Services/Edge/EdgeRecoveryKeyClient.php',
            'app/Services/Edge/EdgeRecoveryKeyProvisioner.php',
            'app/Console/Commands/EdgeLocalRecoveryKeyCommand.php',
            'app/Services/Edge/EdgeSigningKeyStore.php',
            'database/migrations/2026_09_14_000001_create_edge_backup_recovery_tables.php',
        ] as $must) {
            $this->assertContains($must, $plan, "the appliance side must ship: {$must}");
        }
    }

    public function test_the_appliance_cli_allowlist_admits_the_provisioning_command_but_never_the_cloud_admin_command(): void
    {
        $allow = (array) config('edge.cli_allowlist');
        $this->assertContains('edge:local:recovery-key', $allow);
        $this->assertNotContains('edge:recovery-key', $allow);
        $this->assertNotContains('edge:update:keygen', $allow);
    }

    public function test_the_recovery_route_is_device_authenticated_and_throttled(): void
    {
        $route = Route::getRoutes()->getByName('edge.api.backup.recovery_keys');
        $this->assertNotNull($route, 'POST /api/edge/backup/recovery-keys must be registered');
        $middleware = $route->gatherMiddleware();
        $this->assertContains('edge.device.auth', $middleware);
        $this->assertContains('throttle:6,1,edge-recovery', $middleware);
        $this->assertSame(['POST'], $route->methods());
    }

    public function test_the_authority_refuses_to_run_on_a_branch_server(): void
    {
        config(['app.role' => 'branch_server']);
        try {
            (new EdgeBackupRecoveryAuthority())->current(1, 1, 'test');
            $this->fail('must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('RECOVERY_AUTHORITY_CLOUD_ONLY', $e->getMessage());
        } finally {
            config(['app.role' => null]);
        }
    }
}
