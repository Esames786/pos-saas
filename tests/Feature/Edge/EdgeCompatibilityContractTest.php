<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeCompatibilityService;
use Tests\TestCase;

/**
 * EDGE-COMPATIBILITY-CONTRACT-1 — the Cloud must be able to classify an appliance EXPLICITLY:
 * compatible / software_update_required / feature_unavailable_offline — with no silent partial
 * feature behavior (every known offline-relevant feature receives a verdict).
 */
class EdgeCompatibilityContractTest extends TestCase
{
    private function currentManifest(array $overrides = []): array
    {
        return array_merge([
            'edge_app_version' => '0.1.0-edge',
            'edge_schema_version' => 'edge-local-schema@2026_08_13_000001_add_config_refresh_to_edge_local_meta',
            'bootstrap_schema_version' => EdgeBootstrapService::SCHEMA_VERSION,
            'config_schema_version' => EdgeBootstrapService::CONFIG_SCHEMA_VERSION,
            'applied_config_schema_version' => EdgeBootstrapService::CONFIG_SCHEMA_VERSION,
            'last_config_revision' => 7,
            'activation_epoch' => 1,
            'capabilities' => config('edge.capabilities'),
        ], $overrides);
    }

    public function test_current_build_classifies_compatible_and_every_feature_gets_a_verdict(): void
    {
        $result = app(EdgeCompatibilityService::class)->classify($this->currentManifest());

        $this->assertSame(EdgeCompatibilityService::COMPATIBLE, $result['overall']);

        // NO silent partial behavior: every known offline-relevant feature is explicitly classified.
        $this->assertSame(
            EdgeCompatibilityService::CLOUD_OFFLINE_FEATURES,
            array_keys($result['features']),
        );

        // Implemented capabilities are compatible; unimplemented Cloud features are EXPLICITLY offline-unavailable.
        $this->assertSame(EdgeCompatibilityService::COMPATIBLE, $result['features']['config_refresh']);
        $this->assertSame(EdgeCompatibilityService::COMPATIBLE, $result['features']['local_pos_cash_sales']);
        $this->assertSame(EdgeCompatibilityService::FEATURE_UNAVAILABLE_OFFLINE, $result['features']['returns']);
        $this->assertSame(EdgeCompatibilityService::FEATURE_UNAVAILABLE_OFFLINE, $result['features']['card_payments']);
        $this->assertSame(EdgeCompatibilityService::FEATURE_UNAVAILABLE_OFFLINE, $result['features']['manufacturing']);
    }

    public function test_stale_bootstrap_schema_requires_software_update(): void
    {
        $result = app(EdgeCompatibilityService::class)->classify(
            $this->currentManifest(['bootstrap_schema_version' => 'edge-bootstrap-v4'])
        );

        $this->assertSame(EdgeCompatibilityService::SOFTWARE_UPDATE_REQUIRED, $result['overall']);
        // An outdated build gets update-required for EVERYTHING — never a partial "this still works" claim.
        foreach ($result['features'] as $feature => $verdict) {
            $this->assertSame(EdgeCompatibilityService::SOFTWARE_UPDATE_REQUIRED, $verdict, $feature);
        }
    }

    public function test_stale_config_schema_requires_software_update(): void
    {
        $result = app(EdgeCompatibilityService::class)->classify(
            $this->currentManifest(['config_schema_version' => 'edge-config-v0'])
        );

        $this->assertSame(EdgeCompatibilityService::SOFTWARE_UPDATE_REQUIRED, $result['overall']);
    }

    public function test_missing_capability_is_explicitly_unavailable_offline(): void
    {
        $capabilities = array_values(array_diff(config('edge.capabilities'), ['local_printing']));
        $result = app(EdgeCompatibilityService::class)->classify(
            $this->currentManifest(['capabilities' => $capabilities])
        );

        $this->assertSame(EdgeCompatibilityService::COMPATIBLE, $result['overall']);
        $this->assertSame(EdgeCompatibilityService::FEATURE_UNAVAILABLE_OFFLINE, $result['features']['local_printing']);
    }

    public function test_edge_manifest_exposes_grounded_facts_and_no_secrets(): void
    {
        config(['app.role' => 'branch_server']);
        $manifest = app(EdgeCompatibilityService::class)->manifest();

        foreach (['edge_app_version', 'edge_schema_version', 'bootstrap_schema_version', 'config_schema_version',
            'applied_config_schema_version', 'last_config_revision', 'activation_epoch', 'capabilities'] as $key) {
            $this->assertArrayHasKey($key, $manifest);
        }
        $this->assertSame(EdgeBootstrapService::SCHEMA_VERSION, $manifest['bootstrap_schema_version']);
        $this->assertSame(EdgeBootstrapService::CONFIG_SCHEMA_VERSION, $manifest['config_schema_version']);
        $this->assertContains('config_refresh', $manifest['capabilities']);
        // Unbound appliance reports binding facts honestly as null (never fabricated).
        $this->assertNull($manifest['last_config_revision']);
        $this->assertNull($manifest['activation_epoch']);

        $blob = strtolower(json_encode($manifest));
        foreach (['password', 'secret', 'bearer', 'private', 'token'] as $needle) {
            $this->assertStringNotContainsString($needle, $blob);
        }

        // Round trip: the manifest this build emits classifies as compatible on the current Cloud.
        $this->assertSame(
            EdgeCompatibilityService::COMPATIBLE,
            app(EdgeCompatibilityService::class)->classify($manifest)['overall'],
        );
    }
}
