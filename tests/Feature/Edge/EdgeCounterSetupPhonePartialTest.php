<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\EdgeCounterSetupRenderer;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Edge 0.9 stream D — resources/views/tenant/offline-edge/partials/phone-onboarding.blade.php renders the per-OS steps and
 * the QR codes inline (no CDN, no script), with the CA download link from stream C's route when it is known.
 */
class EdgeCounterSetupPhonePartialTest extends TestCase
{
    private const CA_PEM = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIFWzCCA0OgAwIBAgIQbu/MmHP7vIVH2YwMH0sCETANBgkqhkiG9w0BAQsFADBG
MRgwFgYDVQQKDA9CaW5nb28gUE9TIEVkZ2UxKjAoBgNVBAMMIUJpbmdvbyBFZGdl
IEJyYW5jaCBDQSAoS2hhdHJpLTAxKTAeFw0yNjExMjEwNjQzMDNaFw0zMTExMjEw
NjUzMDJaMEYxGDAWBgNVBAoMD0JpbmdvbyBQT1MgRWRnZTEqMCgGA1UEAwwhQmlu
Z29vIEVkZ2UgQnJhbmNoIENBIChLaGF0cmktMDEpMIICIjANBgkqhkiG9w0BAQEF
AAOCAg8AMIICCgKCAgEAwSJl+pcaoJmwPcYoKz9PuTg2wh4JcB1/uJL79GO9r7jM
4KUJVewgVvgv7UQj30iUZJJF0qzmFYPioYtD76jeJ02/bClBI4HmuJ1qAIvMhGik
yq65tK/rCXu4exZf6uaV+2tmwh9KuuwUhjcHpiX70iR53AQ0fpMig4NymqS2JQU+
ySRanKEoJkw01xy7Kl+xmCJqfCfEDA+GI9lzDCiAi0PVYQLIV7TuOEHszZfYY95f
dG3UxoV9DF/vSFi3mA4tdEoPqOFC3Yq27kyXFowm5TEIECm/pyfsrmZMn04Zv0yD
DJzXyKmL9KYFqZspHva6qXi0NJ4bmTe//Zbe+v3HZ9Eaxu6AmMu4XEqUImBQLi4A
Q+hMS/iGxDUOW4+8popHk961l19fMy7Bwt67ADgIngGuETSYrcZqM7txbR8C0Z2C
1LglXWtabguehP0mF1SDpoAY1TWBdDsFdWpAQN86+zb+kJwIxp1z1eS948kzN7im
O/WsJcHfvTwdpu0WdB26yljGrg+Y0ckE3z1fHZHjdYV0i0G2RjhC39KbJQVCJd/Y
3pf/1vFHo7oxpUan+ll5ndJ0Tm9UdSoA5BocWZmn6E9I99EIe9SGxm8VJApI4vaf
SganjvQ2ZaskK1quDjfhqK7CLsvWqP8Q6XXklMC9AIC4U31OBUxDAbEEgoynUXUC
AwEAAaNFMEMwDgYDVR0PAQH/BAQDAgEGMBIGA1UdEwEB/wQIMAYBAf8CAQAwHQYD
VR0OBBYEFOoyh3yu3KqRZxGqp4rAGAG+yDbIMA0GCSqGSIb3DQEBCwUAA4ICAQBq
IOv2O6O87dokqFpMujjVYK2junafuns11VdUiWUv8bXPxUqJqQpXOaNHApr/SIU6
2JMVoViNjeFbctNKSeCLMGlH1iNVK550OHNCUXVeceSWPVpE1sUBXSzfEEvnbDns
T9gculoi8BhbrwPeO/TWywF9cEGg6cosCEwtTXrfdFCzEsmwO6SF4ED6Unu4L6oc
Hhzi4ZHzcTVphVIr2T0PnrCUEICrbDji41bGXUM/122DTcW0Sw605lSAYzgk6LAt
qPAAY1YYEAjhv63V4tDhfh9Gm+Q/BveCJQwxzUaRHz6rmkfPFGoxWj1yX8lP7gKO
CFVCsO94JhnuPuTWfG8CF/SIePEqcteRL/OwdOoLkWRv0rEa+PdP/lBzH+Va9tWx
h3zvjpb8XEM94FLQNHsyuC6w+lCRyIxApYEIpZt4JdtQgJz42+KDEVVLARPPjXUY
CdIUxPbLUGk3JlCEotH4LBr9V28VM2A1Hxc7LOmEXviS83ju2pGlIvyTvSwVMmTV
8WvxsBDZT9wRLofOf4/38ezpeHX79+eWXyNoH+7vLytAC9CPkSvS5Op83G0o95SR
VsOCRQH71miWXhy8UKq4MaKZayGNZiJI3ZVQjUiY+nYIOUknIg4dYTqEmjfx/lav
NryJ4883lMzeNeP6xi4P/dwDqVz//pKiI4qEq6l2OA==
-----END CERTIFICATE-----
PEM;

    private const VIEW = 'tenant.offline-edge.partials.phone-onboarding';

    private function phone(array $extra = []): array
    {
        return (new EdgeCounterSetupRenderer)->phoneOnboarding(array_merge([
            'lan_hostname' => 'bingoo-edge.local',
            'lan_ip' => '192.168.100.52',
            'lan_https_port' => 8443,
            'lan_ca_pem' => self::CA_PEM,
        ], $extra));
    }

    public function test_renders_both_qr_codes_steps_and_certificate_identity_with_an_explicit_ca_url(): void
    {
        $html = view(self::VIEW, [
            'phone' => $this->phone(),
            'caDownloadUrl' => 'https://khatribiryani.bingoopos.com/settings/offline-edge/branches/1/ca?signature=abc&expires=1',
        ])->render();

        $this->assertSame(2, substr_count($html, '<svg '));
        $this->assertStringContainsString('data-qr="ca"', $html);
        $this->assertStringContainsString('data-qr="login"', $html);
        $this->assertStringContainsString('https://192.168.100.52:8443/edge/local/login', $html);
        $this->assertStringContainsString('href="https://khatribiryani.bingoopos.com/settings/offline-edge/branches/1/ca?signature=abc&amp;expires=1"', $html);
        $this->assertStringContainsString('QR 1', $html);
        $this->assertStringContainsString('QR 2', $html);
        $this->assertStringContainsString('data-platform="android"', $html);
        $this->assertStringContainsString('data-platform="ios"', $html);
        $this->assertStringContainsString('iPhone / iPad', $html);
        $this->assertStringContainsString('Certificate Trust Settings', $html);
        $this->assertStringContainsString('Phone ko dukaan ke Wi-Fi se connect karein', $html); // Roman Urdu
        $this->assertStringContainsString('lang="ur-Latn"', $html);
        $this->assertStringContainsString('Bingoo Edge Branch CA (Khatri-01)', $html);
        $this->assertStringContainsString('B7:8F:A7:EE', $html);
        $this->assertStringContainsString('&quot;Install anyway&quot;', $html); // step text is escaped

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsStringIgnoringCase('cdn', $html);
        $this->assertStringNotContainsString('http://', str_replace('http://www.w3.org/2000/svg', '', $html));
        $this->assertStringNotContainsStringIgnoringCase('password', $html);
    }

    public function test_without_a_ca_url_or_route_it_shows_the_fallback_and_only_the_login_qr(): void
    {
        $html = view(self::VIEW, ['phone' => $this->phone()])->render();

        $this->assertSame(1, substr_count($html, '<svg '));
        $this->assertStringContainsString('Certificate link not available here', $html);
        $this->assertStringContainsString('bingoo-branch-ca.crt', $html);
    }

    public function test_builds_the_ca_link_from_stream_c_route_and_the_branch(): void
    {
        // Stream C registers the real route (…/branches/{branch}/ca.crt) inside the tenant subdomain group; the tenant
        // middleware supplies {subdomain} at runtime, so the test does the same.
        \Illuminate\Support\Facades\URL::defaults(['subdomain' => 'khatri']);
        if (! Route::has(EdgeCounterSetupRenderer::CA_DOWNLOAD_ROUTE)) {
            Route::get('/settings/offline-edge/branches/{branch}/ca.crt', fn () => 'ca')->name(EdgeCounterSetupRenderer::CA_DOWNLOAD_ROUTE);
            app('router')->getRoutes()->refreshNameLookups();
        }

        $html = view(self::VIEW, ['phone' => $this->phone(), 'branch' => 12])->render();
        $this->assertSame(2, substr_count($html, '<svg '));
        $this->assertMatchesRegularExpression('#href="[^"]*/settings/offline-edge/branches/12/ca\.crt"#', $html);

        $fromProfile = view(self::VIEW, ['phone' => $this->phone(['branch_id' => 5])])->render();
        $this->assertMatchesRegularExpression('#href="[^"]*/settings/offline-edge/branches/5/ca\.crt"#', $fromProfile);
    }
}
