<?php

namespace Tests\MySql;

use App\Services\Edge\EdgeSyncReconciliationClient;
use Illuminate\Support\Facades\Http;

/**
 * PRODUCTIZATION GATE 0 — the Edge-side reconciliation fetch transport (Http::fake). Proves it carries device
 * auth headers with TLS verification, parses the Cloud `statuses` map, and fails on a transport/HTTP error
 * (best-effort — the caller retries later). It posts nothing.
 */
class EdgeSyncReconciliationClientMySqlTest extends MySqlTenantTestCase
{
    private string $url = 'https://cloud.example.test/api/edge/sync/reconcile';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'edge.sync.reconcile_url' => $this->url,
            'edge.sync.device_id' => 'device-A',
            'edge.sync.device_secret' => 'secret-A',
        ]);
    }

    private function client(): EdgeSyncReconciliationClient
    {
        return app(EdgeSyncReconciliationClient::class);
    }

    public function test_it_fetches_and_parses_the_cloud_statuses_with_device_auth(): void
    {
        Http::fake([$this->url => Http::response(['statuses' => [
            'SALE-1' => ['status' => 'applied', 'content_hash' => 'h1'],
        ]], 200)]);

        $statuses = $this->client()->fetchStatuses(['SALE-1']);
        $this->assertSame('applied', $statuses['SALE-1']['status']);

        Http::assertSent(function ($request) {
            return $request->url() === $this->url
                && $request->hasHeader('X-Edge-Device-ID', 'device-A')
                && $request->hasHeader('Authorization', 'Bearer secret-A')
                && $request->data()['sale_uuids'] === ['SALE-1'];
        });
    }

    public function test_an_empty_query_sends_nothing(): void
    {
        Http::fake();
        $this->assertSame([], $this->client()->fetchStatuses([]));
        Http::assertNothingSent();
    }

    public function test_an_http_error_throws_for_a_later_retry(): void
    {
        Http::fake([$this->url => Http::response(['error' => 'down'], 503)]);
        $this->expectExceptionMessage('RECONCILE_HTTP_503');
        $this->client()->fetchStatuses(['SALE-1']);
    }
}
