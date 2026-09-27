<?php

namespace Tests\Feature\Edge;

use App\Models\Edge\EdgeLocalMeta;
use App\Services\Edge\EdgeAuthorityLeaseClient;
use App\Services\Edge\EdgeBuildInfoService;
use App\Services\Edge\EdgePurchaseReturnEnvelopeBuilder;
use App\Services\Edge\EdgeReturnEnvelopeBuilder;
use App\Services\Edge\EdgeSaleEnvelopeBuilder;
use App\Services\Edge\EdgeSupplierFinanceEnvelopeBuilder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * W-F VERSION REPORTING — the appliance's heartbeat payload builder (no database): the lease contract (seq + edge_state)
 * is unchanged, the informational `build` block carries the non-secret build facts, and a failure to gather them sends
 * `build: null` instead of failing the beat.
 */
class EdgeHeartbeatBuildPayloadTest extends TestCase
{
    public function test_build_report_carries_the_build_facts_and_the_applied_schema(): void
    {
        $meta = new EdgeLocalMeta();
        $meta->forceFill(['edge_schema_version' => 'edge-local-schema@applied']);

        $build = app(EdgeAuthorityLeaseClient::class)->buildReport($meta);
        $info = app(EdgeBuildInfoService::class)->info();

        $this->assertSame([
            'edge_app_version', 'git_commit', 'artifact_version', 'bootstrap_schema', 'config_schema',
            'edge_schema_version', 'applied_edge_schema_version', 'envelope_versions', 'capabilities',
        ], array_keys($build));
        $this->assertSame((string) config('edge.app_version'), $build['edge_app_version']);
        $this->assertSame((string) config('edge.bootstrap_schema'), $build['bootstrap_schema']);
        $this->assertSame((string) config('edge.config_schema'), $build['config_schema']);
        $this->assertSame($info['edge_schema_version'], $build['edge_schema_version']);
        $this->assertSame('edge-local-schema@applied', $build['applied_edge_schema_version']);
        $this->assertSame([
            EdgeSaleEnvelopeBuilder::SCHEMA_VERSION, EdgeSaleEnvelopeBuilder::SCHEMA_VERSION_V2,
            EdgeReturnEnvelopeBuilder::SCHEMA, EdgePurchaseReturnEnvelopeBuilder::SCHEMA,
            EdgeSupplierFinanceEnvelopeBuilder::SCHEMA_PAYMENT, EdgeSupplierFinanceEnvelopeBuilder::SCHEMA_AP_JOURNAL,
        ], $build['envelope_versions']);
        $this->assertSame(array_values((array) config('edge.capabilities')), $build['capabilities']);

        // Unbound appliance: the applied schema is honestly null; everything else is still reported.
        $this->assertNull(app(EdgeAuthorityLeaseClient::class)->buildReport(null)['applied_edge_schema_version']);

        // Non-secret only.
        $blob = strtolower((string) json_encode($build));
        foreach (['app_key', 'password', 'secret', 'bearer', 'private', 'token'] as $needle) {
            $this->assertStringNotContainsString($needle, $blob);
        }
    }

    public function test_unavailable_build_facts_report_null_and_never_throw(): void
    {
        $broken = new class extends EdgeBuildInfoService {
            public function info(): array
            {
                throw new \RuntimeException('manifest unreadable');
            }
        };

        $this->assertNull((new EdgeAuthorityLeaseClient($broken))->buildReport(null));
    }

    public function test_heartbeat_request_body_keeps_the_lease_contract_and_adds_build(): void
    {
        config([
            'edge.authority.heartbeat_url' => 'https://cloud.test/api/edge/authority/heartbeat',
            'edge.sync.device_id' => 'dev-1',
            'edge.sync.device_secret' => 's3cr3t',
        ]);
        $sent = [];
        Http::fake(function (ClientRequest $request) use (&$sent) {
            $sent[] = $request->data();

            return Http::response(['status' => 'ok', 'holder' => 'cloud', 'seq' => 7, 'capabilities' => ['customer_create' => false]], 200);
        });

        $client = app(EdgeAuthorityLeaseClient::class);
        $build = $client->buildReport(null);
        $ack = $client->heartbeat(7, 'standby', $build);

        $this->assertSame('ok', $ack['status']);
        $this->assertSame(['seq' => 7, 'edge_state' => 'standby', 'build' => $build], $sent[0]);
        $this->assertSame(['seq' => 7, 'edge_state' => 'standby', 'build' => null], $client->heartbeatPayload(7, 'standby'));

        // The legacy two-argument call still works (build: null).
        $client->heartbeat(8, 'standby');
        $this->assertSame(['seq' => 8, 'edge_state' => 'standby', 'build' => null], $sent[1]);
    }
}
