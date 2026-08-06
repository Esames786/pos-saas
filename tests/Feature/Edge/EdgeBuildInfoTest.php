<?php

namespace Tests\Feature\Edge;

use App\Http\Controllers\Edge\EdgeRuntimeController;
use App\Services\Edge\EdgeBuildInfoService;
use Tests\TestCase;

/**
 * EDGE-RUNTIME-BOUNDARY-1 (L/M) — build-info + health/readiness surface. Proves the exposed fields
 * are the intended non-secret facts and that no secret ever leaks into the response.
 */
class EdgeBuildInfoTest extends TestCase
{
    public function test_build_info_exposes_only_non_secret_facts(): void
    {
        $info = app(EdgeBuildInfoService::class)->info();

        foreach (['product', 'runtime_mode', 'edge_app_version', 'bootstrap_schema', 'sync_protocol', 'php_version'] as $key) {
            $this->assertArrayHasKey($key, $info);
        }
        $this->assertSame('edge-bootstrap-v3', $info['bootstrap_schema']);

        // No secret-ish keys, and no secret values anywhere in the payload.
        $blob = strtolower(json_encode($info));
        foreach (['app_key', 'password', 'secret', 'bearer', 'private', 'db_password', 'token'] as $needle) {
            $this->assertStringNotContainsString($needle, $blob, "build-info must not expose '$needle'");
        }
    }

    public function test_schema_and_protocol_compatibility_checks(): void
    {
        $svc = app(EdgeBuildInfoService::class);
        $this->assertTrue($svc->supportsBootstrapSchema('edge-bootstrap-v3'));
        $this->assertFalse($svc->supportsBootstrapSchema('edge-bootstrap-v2'));
        $this->assertFalse($svc->supportsBootstrapSchema(''));
        $this->assertTrue($svc->supportsSyncProtocol('edge-sync-v0'));
        $this->assertFalse($svc->supportsSyncProtocol('edge-sync-v1'));
    }

    public function test_health_and_ready_endpoints(): void
    {
        $controller = app(EdgeRuntimeController::class);

        $health = json_decode($controller->health()->getContent(), true);
        $this->assertSame('ok', $health['status']);
        $this->assertIsInt($health['server_epoch_ms']);
        $this->assertArrayHasKey('edge_app_version', $health);

        config(['app.role' => 'branch_server']);
        $ready = json_decode($controller->ready()->getContent(), true);
        $this->assertTrue($ready['ready']);
        $this->assertSame([], $ready['problems']);
        // Later-sprint checks are honestly reported as not yet implemented (nothing faked).
        $this->assertSame('implemented', $ready['checks']['runtime_boundary']);
        $this->assertSame('not_implemented', $ready['checks']['local_database']);
        $this->assertSame('not_implemented', $ready['checks']['local_auth']);
    }
}
