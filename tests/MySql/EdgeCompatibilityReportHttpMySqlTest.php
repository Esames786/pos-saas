<?php

namespace Tests\MySql;

use App\Models\Master\EdgeDevice;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeCompatibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EDGE-COMPATIBILITY-CONTRACT-1 — the device-authenticated compatibility report proven through the
 * REAL HTTP stack (central domain + AuthenticateEdgeDevice). The Cloud stores the reported manifest
 * on the device row and answers with the explicit classification; a bad/missing device credential
 * never reaches the controller.
 */
class EdgeCompatibilityReportHttpMySqlTest extends MySqlTenantTestCase
{
    private string $uri;
    private string $secret = 'test-device-secret';
    private EdgeDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uri = 'http://' . config('tenancy.central_domain') . '/api/edge/compatibility/report';

        DB::connection('master')->table('edge_devices')->where('device_name', 'compat-proof')->delete();
        $this->device = EdgeDevice::create([
            'public_uuid' => (string) Str::uuid(), 'tenant_id' => 4242, 'branch_id' => 1,
            'installation_uuid' => (string) Str::uuid(), 'device_name' => 'compat-proof',
            'device_secret_hash' => hash('sha256', $this->secret),
            'status' => EdgeDevice::STATUS_READY, 'active_slot' => EdgeDevice::ACTIVE_SLOT,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('master')->table('edge_devices')->where('id', $this->device->id)->delete();
        } catch (\Throwable $e) {
            // best-effort cleanup; never mask the real outcome
        }
        parent::tearDown();
    }

    private function manifest(array $overrides = []): array
    {
        return array_merge([
            'edge_app_version' => '0.1.0-edge',
            'edge_schema_version' => 'edge-local-schema@test',
            'bootstrap_schema_version' => EdgeBootstrapService::SCHEMA_VERSION,
            'config_schema_version' => EdgeBootstrapService::CONFIG_SCHEMA_VERSION,
            'applied_config_schema_version' => EdgeBootstrapService::CONFIG_SCHEMA_VERSION,
            'last_config_revision' => 3,
            'activation_epoch' => 1,
            'capabilities' => config('edge.capabilities'),
        ], $overrides);
    }

    private function headers(): array
    {
        return ['X-Edge-Device-ID' => $this->device->public_uuid, 'Authorization' => 'Bearer ' . $this->secret];
    }

    public function test_report_is_stored_and_classified(): void
    {
        $res = $this->postJson($this->uri, $this->manifest(), $this->headers());

        $res->assertStatus(200)
            ->assertJsonPath('overall', EdgeCompatibilityService::COMPATIBLE)
            ->assertJsonPath('features.config_refresh', EdgeCompatibilityService::COMPATIBLE)
            ->assertJsonPath('features.returns', EdgeCompatibilityService::FEATURE_UNAVAILABLE_OFFLINE);

        $fresh = EdgeDevice::find($this->device->id);
        $this->assertSame(3, (int) $fresh->compatibility_manifest['last_config_revision']);
        $this->assertNotNull($fresh->compatibility_reported_at);
        $this->assertSame(EdgeBootstrapService::SCHEMA_VERSION, $fresh->schema_version);
        $this->assertSame('0.1.0-edge', $fresh->app_version);
    }

    public function test_stale_build_is_classified_update_required(): void
    {
        $res = $this->postJson($this->uri, $this->manifest(['bootstrap_schema_version' => 'edge-bootstrap-v4']), $this->headers());

        $res->assertStatus(200)->assertJsonPath('overall', EdgeCompatibilityService::SOFTWARE_UPDATE_REQUIRED);
    }

    public function test_wrong_or_missing_device_credential_is_refused(): void
    {
        $this->postJson($this->uri, $this->manifest())->assertStatus(401);
        $this->postJson($this->uri, $this->manifest(), [
            'X-Edge-Device-ID' => $this->device->public_uuid, 'Authorization' => 'Bearer wrong-secret',
        ])->assertStatus(401);

        $this->assertNull(EdgeDevice::find($this->device->id)->compatibility_reported_at, 'an unauthenticated report must never be stored');
    }
}
