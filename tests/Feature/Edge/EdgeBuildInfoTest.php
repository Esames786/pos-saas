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
        $this->assertSame('edge-bootstrap-v5', $info['bootstrap_schema']);
        $this->assertSame('edge-config-v1', $info['config_schema']);
        $this->assertContains('config_refresh', $info['capabilities']);
        $this->assertStringStartsWith('edge-local-schema@', $info['edge_schema_version']);

        // No secret-ish keys, and no secret values anywhere in the payload.
        $blob = strtolower(json_encode($info));
        foreach (['app_key', 'password', 'secret', 'bearer', 'private', 'db_password', 'token'] as $needle) {
            $this->assertStringNotContainsString($needle, $blob, "build-info must not expose '$needle'");
        }
    }

    public function test_schema_and_protocol_compatibility_checks(): void
    {
        $svc = app(EdgeBuildInfoService::class);
        $this->assertTrue($svc->supportsBootstrapSchema('edge-bootstrap-v5'));
        $this->assertFalse($svc->supportsBootstrapSchema('edge-bootstrap-v4'));
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
        $ready = json_decode($controller->ready(app(\App\Services\Edge\EdgeLocalReadiness::class))->getContent(), true);
        // EDGE-LOCAL-RUNTIME-1: without a provisioned local DB the runtime FOUNDATION is not ready, and
        // selling capabilities are honestly reported as not-yet-implemented (nothing faked).
        $this->assertFalse($ready['ready']);
        $this->assertSame([], $ready['problems']);
        $this->assertSame('ready', $ready['runtime_boundary']);
        $this->assertSame('not_ready', $ready['local_database']);
        $this->assertSame('not_ready', $ready['bootstrap_binding']);
        $this->assertSame('not_ready', $ready['operational_stock']);
        $this->assertSame('not_ready', $ready['local_auth']); // EDGE-LOCAL-AUTH-1: real state, uninitialised
        $this->assertFalse($ready['activation_ready']);
    }
}
