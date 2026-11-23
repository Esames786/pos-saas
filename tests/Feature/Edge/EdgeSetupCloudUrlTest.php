<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\Cloud\DeviceProfileService;
use Tests\TestCase;

/**
 * EDGE 0.9 (K2, CP5 pre-flight 22 Nov 2026) — the cloud_url the setup-code redeem advertises is the CENTRAL domain with the
 * scheme of app.url, and keeps a NON-default port of app.url when app.url's host is the central domain. Production
 * (https://bingoopos.com) is byte-for-byte unchanged.
 */
class EdgeSetupCloudUrlTest extends TestCase
{
    private function url(string $appUrl, string $central): string
    {
        config(['app.url' => $appUrl, 'tenancy.central_domain' => $central]);

        return app(DeviceProfileService::class)->cloudUrl();
    }

    public function test_production_stays_exactly_as_today(): void
    {
        $this->assertSame('https://bingoopos.com', $this->url('https://bingoopos.com', 'bingoopos.com'));
        $this->assertSame('https://bingoopos.com', $this->url('https://bingoopos.com/', 'bingoopos.com'));
        $this->assertSame('https://bingoopos.com', $this->url('https://bingoopos.com:443', 'bingoopos.com'), 'a default port is never added');
        $this->assertSame('http://bingoopos.com', $this->url('http://bingoopos.com:80', 'bingoopos.com'));
    }

    public function test_a_non_default_port_on_the_central_host_is_kept(): void
    {
        $this->assertSame('http://127.0.0.1:9831', $this->url('http://127.0.0.1:9831', '127.0.0.1'));
        $this->assertSame('https://cloud.example.test:8443', $this->url('https://Cloud.Example.test:8443', 'cloud.example.test'));
        $this->assertSame('http://127.0.0.1:443', $this->url('http://127.0.0.1:443', '127.0.0.1'), '443 is not the default for http');
    }

    public function test_a_port_of_another_host_is_never_attached_to_the_central_domain(): void
    {
        $this->assertSame('http://pos-saas.test', $this->url('http://pos-saas-edge.test:8080', 'pos-saas.test'));
        $this->assertSame('https://bingoopos.com', $this->url('', 'bingoopos.com'), 'no app.url → https');
    }
}
