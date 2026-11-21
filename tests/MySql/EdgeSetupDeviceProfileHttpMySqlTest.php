<?php

namespace Tests\MySql;

use App\Models\Master\EdgeDevice;
use Illuminate\Support\Carbon;
use Tests\MySql\Support\EdgeSetupCloudFixture;

/**
 * EDGE 0.9 stream C — K4 POST /api/edge/device/status and K5 POST /api/edge/device/lan-profile over the REAL central-domain
 * HTTP stack. The device row is always the authenticated one; phases and versions are validated; the Cloud records its own
 * clock; an "Update now" request is closed by an update outcome; the LAN profile accepts ONE currently valid CA certificate
 * (basicConstraints CA:TRUE) whose SHA-256 over the DER matches, and refuses a leaf, a mismatching fingerprint, a private key,
 * two certificates, a bad IP / host / port. Pairing / authority columns are never touched.
 */
class EdgeSetupDeviceProfileHttpMySqlTest extends MySqlTenantTestCase
{
    use EdgeSetupCloudFixture;

    public const TENANT_CODE = 'e09cprofile';

    private EdgeDevice $device;
    private string $secret = 'profile-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEdgeCloud();
        $this->device = $this->makeDevice($this->branchA, $this->secret);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        try {
            $this->cleanupEdgeCloud();
        } catch (\Throwable $e) {
        }
        parent::tearDown();
    }

    private function reportStatus(array $body, ?EdgeDevice $d = null, ?string $s = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->central('/api/edge/device/status'), $body, $this->deviceHeaders($d ?? $this->device, $s ?? $this->secret));
    }

    private function reportLan(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->central('/api/edge/device/lan-profile'), $body, $this->deviceHeaders($this->device, $this->secret));
    }

    public function test_status_is_recorded_on_the_authenticated_device_with_the_cloud_clock(): void
    {
        $pairingFacts = fn () => (array) \Illuminate\Support\Facades\DB::connection('master')->table('edge_devices')->where('id', $this->device->id)
            ->first(['public_uuid', 'installation_uuid', 'device_secret_hash', 'status', 'active_slot', 'paired_at', 'app_version']);
        $before = $pairingFacts();
        Carbon::setTestNow('2026-11-21 12:00:00');
        $res = $this->reportStatus(['phase' => 'installing', 'version' => '0.9.0-edge', 'ok' => true, 'message' => 'Installing MySQL service', 'at' => '2026-11-21T00:00:00+05:00'])
            ->assertOk()->assertJsonPath('status', 'recorded')->assertJsonPath('phase', 'installing')->assertJsonPath('update_now', false);
        $d = EdgeDevice::findOrFail($this->device->id);
        $this->assertSame('installing', $d->install_phase);
        $this->assertTrue($d->install_ok);
        $this->assertSame('Installing MySQL service', $d->install_message);
        $this->assertSame('0.9.0-edge', $d->reported_version);
        $this->assertSame('2026-11-21 12:00:00', $d->install_reported_at->format('Y-m-d H:i:s'), 'the Cloud clock, not the appliance\'s');
        $this->assertSame($res->json('recorded_at'), $d->install_reported_at->toIso8601String());
        $this->assertSame($before, $pairingFacts(), 'pairing/authority facts untouched');

        $this->reportStatus(['phase' => 'update_failed', 'ok' => false, 'message' => str_repeat('x', 1200)])->assertOk();
        $d->refresh();
        $this->assertFalse($d->install_ok);
        $this->assertSame('0.9.0-edge', $d->reported_version, 'a report without a version keeps the last one');
        $this->assertLessThanOrEqual(500, mb_strlen((string) $d->install_message));
    }

    public function test_an_update_outcome_closes_the_owners_update_now_request(): void
    {
        $this->device->forceFill(['update_now_requested_at' => now()])->save();
        $this->reportStatus(['phase' => 'updating', 'version' => '0.9.0-edge', 'ok' => true])->assertOk()->assertJsonPath('update_now', true);
        $this->assertNotNull(EdgeDevice::findOrFail($this->device->id)->update_now_requested_at);
        $this->reportStatus(['phase' => 'updated', 'version' => '0.9.1-edge', 'ok' => true])->assertOk()->assertJsonPath('update_now', false);
        $d = EdgeDevice::findOrFail($this->device->id);
        $this->assertNull($d->update_now_requested_at);
        $this->assertSame('0.9.1-edge', $d->reported_version);
    }

    public function test_status_validation_and_device_auth(): void
    {
        $this->reportStatus(['phase' => 'exploded', 'ok' => true])->assertStatus(422);
        $this->reportStatus(['phase' => 'ready'])->assertStatus(422);
        $this->reportStatus(['phase' => 'ready', 'ok' => true, 'version' => '0.9 edge; rm -rf'])->assertStatus(422);
        $this->postJson($this->central('/api/edge/device/status'), ['phase' => 'ready', 'ok' => true])->assertStatus(401);
        $this->reportStatus(['phase' => 'ready', 'ok' => true], $this->device, 'wrong-secret')->assertStatus(401);
        // Another branch's device reporting never touches this one.
        $other = $this->makeDevice($this->branchB, 'other-secret');
        $this->reportStatus(['phase' => 'ready', 'ok' => true, 'version' => '9.9.9'], $other, 'other-secret')->assertOk();
        $this->assertNull(EdgeDevice::findOrFail($this->device->id)->reported_version);
    }

    public function test_a_valid_ca_lan_profile_is_stored_normalised(): void
    {
        $ca = $this->makeCertificate();
        $colonUpper = strtoupper(implode(':', str_split($ca['sha256'], 2)));
        $res = $this->reportLan(['lan_hostname' => 'Bingoo-Edge.local', 'lan_ip' => '192.168.100.52', 'https_port' => 8443,
            'ca_certificate_pem' => $ca['pem'], 'ca_sha256' => $colonUpper])->assertOk();
        $res->assertJsonPath('ca_sha256', $ca['sha256'])->assertJsonPath('lan_hostname', 'bingoo-edge.local')->assertJsonPath('https_port', 8443);
        $d = EdgeDevice::findOrFail($this->device->id);
        $this->assertTrue($d->hasLanProfile());
        $this->assertSame('192.168.100.52', $d->lan_ip);
        $this->assertSame($ca['sha256'], $d->lan_ca_sha256);
        $this->assertSame($ca['sha256'], strtolower(openssl_x509_fingerprint($d->lan_ca_pem, 'sha256')));
        $this->assertStringNotContainsString('PRIVATE KEY', $d->lan_ca_pem);
    }

    public function test_the_lan_profile_refuses_anything_but_one_matching_ca_certificate(): void
    {
        $ca = $this->makeCertificate();
        $leaf = $this->makeCertificate(false, 'bingoo-edge.local');
        $ok = ['lan_hostname' => 'bingoo-edge.local', 'lan_ip' => '192.168.100.52', 'https_port' => 8443, 'ca_certificate_pem' => $ca['pem'], 'ca_sha256' => $ca['sha256']];

        $this->reportLan(['ca_certificate_pem' => $leaf['pem'], 'ca_sha256' => $leaf['sha256']] + $ok)->assertStatus(422)->assertJsonPath('code', 'EDGE_LAN_PROFILE_INVALID');
        $this->reportLan(['ca_sha256' => str_repeat('a', 64)] + $ok)->assertStatus(422)->assertJsonPath('field', 'ca_sha256');
        $this->reportLan(['ca_sha256' => 'not-hex'] + $ok)->assertStatus(422);
        $this->reportLan(['ca_certificate_pem' => $ca['pem'] . "\n" . $ca['key_pem']] + $ok)->assertStatus(422)->assertJsonPath('code', 'EDGE_LAN_PROFILE_INVALID');
        $this->reportLan(['ca_certificate_pem' => $ca['pem'] . "\n" . $leaf['pem']] + $ok)->assertStatus(422);
        $this->reportLan(['ca_certificate_pem' => "-----BEGIN CERTIFICATE-----\nnot base64\n-----END CERTIFICATE-----\n"] + $ok)->assertStatus(422);
        $this->reportLan(['lan_ip' => '192.168.100.300'] + $ok)->assertStatus(422);
        $this->reportLan(['lan_hostname' => 'bad host!'] + $ok)->assertStatus(422)->assertJsonPath('field', 'lan_hostname');
        $this->reportLan(['https_port' => 0] + $ok)->assertStatus(422);
        $this->assertFalse(EdgeDevice::findOrFail($this->device->id)->hasLanProfile(), 'nothing was stored by a refused report');
        $this->postJson($this->central('/api/edge/device/lan-profile'), $ok)->assertStatus(401);
    }
}
