<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\Continuity\ContinuityFingerprint;
use App\Services\Edge\Continuity\LiveStateClient;
use App\Models\Tenant\SalesOrder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * EDGE 0.9 CONTINUITY (stream A) — the appliance's K1 live-state client, no database: device-authenticated GET on the Cloud
 * API base (derived from the provisioned config-refresh URL when no explicit URL exists), `since` = the held revision, TLS
 * verification ON, bounded timeouts, and every non-success answer refused with a classified code (the caller records it; the
 * mirror never half-applies). Plus the shared order fingerprint both ends compute.
 */
class EdgeLiveStateMirrorClientTest extends TestCase
{
    private const REFRESH = 'https://cloud.bingoo.test/api/edge/config/refresh';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'edge.standby.config_refresh_url' => self::REFRESH,
            'edge.sync.device_id' => 'dev-uuid-1', 'edge.sync.device_secret' => 'not-a-real-secret',
            'edge.continuity.live_state_url' => null,
        ]);
    }

    public function test_the_url_is_derived_from_the_cloud_api_base_or_taken_from_explicit_config(): void
    {
        $this->assertSame('https://cloud.bingoo.test/api/edge/live-state', app(LiveStateClient::class)->url());
        config(['edge.continuity.live_state_url' => 'https://other.test/api/edge/live-state']);
        $this->assertSame('https://other.test/api/edge/live-state', app(LiveStateClient::class)->url());
        config(['edge.continuity.live_state_url' => null, 'edge.standby.config_refresh_url' => null]);
        $this->assertSame('', app(LiveStateClient::class)->url());
        $this->expectExceptionMessage('LIVE_STATE_URL_MISSING');
        app(LiveStateClient::class)->fetch();
    }

    public function test_fetch_is_a_device_authenticated_get_carrying_the_held_revision(): void
    {
        $seen = [];
        Http::fake(function (ClientRequest $r) use (&$seen) {
            $seen[] = $r;

            return Http::response(['revision' => 'r2', 'generated_at' => now()->toIso8601String(), 'branch_id' => 1, 'shifts' => [], 'table_sessions' => [], 'held_sales' => []]);
        });
        $pkg = app(LiveStateClient::class)->fetch('r1');
        $this->assertSame('r2', $pkg['revision']);
        $this->assertCount(1, $seen);
        $this->assertSame('GET', $seen[0]->method());
        $this->assertSame('https://cloud.bingoo.test/api/edge/live-state?since=r1', $seen[0]->url());
        $this->assertSame(['dev-uuid-1'], $seen[0]->header('X-Edge-Device-ID'));
        $this->assertSame(['Bearer not-a-real-secret'], $seen[0]->header('Authorization'));
        // TLS verification is never disabled (same discipline as every Edge → Cloud client).
        $src = (string) file_get_contents(app_path('Services/Edge/Continuity/LiveStateClient.php'));
        $this->assertStringContainsString("withOptions(['verify' => true])", $src);
        $this->assertStringNotContainsString("'verify' => false", $src);
    }

    public function test_unchanged_and_every_refusal_are_classified(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['unchanged' => true, 'revision' => 'r1'])
            ->push(['status' => 'refused', 'failure_code' => 'DEVICE_REVOKED'], 403)
            ->push(['revision' => 'r3', 'branch_id' => 1, 'shifts' => []])            // malformed: no table_sessions / held_sales
            ->push(['unchanged' => true])]);                                          // malformed: unchanged without revision
        $client = app(LiveStateClient::class);
        $this->assertSame(['unchanged' => true, 'revision' => 'r1'], $client->fetch('r1'));
        foreach (['LIVE_STATE_REFUSED: HTTP 403 DEVICE_REVOKED', 'LIVE_STATE_MALFORMED', 'LIVE_STATE_MALFORMED'] as $expected) {
            try {
                $client->fetch('r1');
                $this->fail('expected ' . $expected);
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith($expected, $e->getMessage());
            }
        }
    }

    public function test_an_unreachable_cloud_is_a_classified_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('simulated WAN failure'));
        $this->expectExceptionMessage('LIVE_STATE_UNREACHABLE');
        app(LiveStateClient::class)->fetch();
    }

    public function test_the_order_fingerprint_is_identical_on_both_ends_and_moves_with_any_change(): void
    {
        $mirror = ['total' => '450.00', 'lines' => [
            ['line_uuid' => '01JDZZZZZZZZZZZZZZZZZZZZZB', 'product_id' => 7, 'variant_id' => null, 'qty' => '2', 'unit_price' => '150.00'],
            ['line_uuid' => '01JDZZZZZZZZZZZZZZZZZZZZZA', 'product_id' => 9, 'variant_id' => 3, 'qty' => '1.000', 'unit_price' => '150'],
        ]];
        $order = new SalesOrder(['grand_total' => 450]);
        $rows = [
            (object) ['line_uuid' => '01JDZZZZZZZZZZZZZZZZZZZZZA', 'product_id' => 9, 'product_variant_id' => 3, 'quantity' => 1, 'unit_price' => 150],
            (object) ['line_uuid' => '01JDZZZZZZZZZZZZZZZZZZZZZB', 'product_id' => 7, 'product_variant_id' => null, 'quantity' => 2.0, 'unit_price' => '150.0000'],
        ];
        $this->assertSame(ContinuityFingerprint::fromMirror($mirror), ContinuityFingerprint::fromOrder($order, $rows), 'order-independent, format-independent');

        $rows[1]->quantity = 3;   // a waiter added one more on the Cloud after the mirror
        $this->assertNotSame(ContinuityFingerprint::fromMirror($mirror), ContinuityFingerprint::fromOrder($order, $rows));
        $rows[1]->quantity = 2;
        $order->grand_total = 460; // repriced / service charge changed
        $this->assertNotSame(ContinuityFingerprint::fromMirror($mirror), ContinuityFingerprint::fromOrder($order, $rows));
        $order->grand_total = 450;
        $rows[] = (object) ['line_uuid' => '01JDZZZZZZZZZZZZZZZZZZZZZC', 'product_id' => 7, 'product_variant_id' => null, 'quantity' => 1, 'unit_price' => 0];
        $this->assertNotSame(ContinuityFingerprint::fromMirror($mirror), ContinuityFingerprint::fromOrder($order, $rows), 'a line added on the Cloud');
    }
}
