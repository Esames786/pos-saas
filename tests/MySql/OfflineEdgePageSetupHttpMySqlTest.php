<?php

namespace Tests\MySql;

use App\Contracts\Edge\CounterSetupRenderer;
use App\Models\Master\EdgeDevice;
use App\Models\Master\EdgeSetupSession;
use App\Models\Tenant\User;
use App\Services\Edge\Cloud\SetupSessionService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\EdgeSetupCloudFixture;

/**
 * EDGE 0.9 stream C — the Cloud Offline Edge page over the REAL tenant HTTP stack (IdentifyTenant → auth:tenant →
 * subscription gate → route.permission): "Set up a Branch Server" (setup code shown once, no-store, requires
 * tenant.offline-edge.security, redeemable by the wizard), cancel, per-device status / version / LAN facts, "Update now",
 * maintenance window, and the K7 counter-setup download, phone page (QR codes, signed CA link) and CA download — with the
 * buttons disabled until the device reported its LAN profile, and every action refused without its permission.
 */
class OfflineEdgePageSetupHttpMySqlTest extends MySqlTenantTestCase
{
    use EdgeSetupCloudFixture;

    public const TENANT_CODE = 'e09cpage';

    private const BASE = ['tenant.offline-edge.index'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->seedEdgeCloud(self::BASE);
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

    private function asUser(string $user): static
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->find($this->users[$user]), 'tenant');
    }

    public function test_the_setup_code_is_shown_once_needs_the_security_permission_and_the_wizard_can_redeem_it(): void
    {
        $url = $this->tenantUrl('/settings/offline-edge/branches/' . $this->branchA . '/setup-code');
        $this->grantTo('owner', ['tenant.offline-edge.setup-code.generate']);
        $this->asUser('owner')->post($url)->assertStatus(403);   // route permission alone is not enough
        $this->assertSame(0, EdgeSetupSession::count());

        $this->grantTo('owner', ['tenant.offline-edge.security']);
        $page = $this->asUser('owner')->get($this->tenantUrl('/settings/offline-edge'))->assertOk();
        $page->assertSee('Set up a Branch Server');

        $res = $this->asUser('owner')->post($url)->assertOk();
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $html = $res->getContent();
        $this->assertStringContainsString('Branch Server setup code for Alpha Branch', $html);
        $this->assertSame(1, preg_match('/id="edge-setup-code">([A-Z0-9]{5}-[A-Z0-9]{5})</', $html, $m));
        $display = $m[1];
        $session = EdgeSetupSession::where('tenant_id', $this->tenantId)->firstOrFail();
        $this->assertSame(app(SetupSessionService::class)->digest($display), $session->code_hash);
        $this->assertSame($this->users['owner'], $session->created_by_user_id);
        $this->assertStringContainsString(config('tenancy.central_domain'), $html, 'the instructions name the CENTRAL Cloud address');

        // Never shown again; the page shows the live state and a cancel control instead.
        $again = $this->asUser('owner')->get($this->tenantUrl('/settings/offline-edge'))->assertOk()->getContent();
        $this->assertStringNotContainsString($display, $again);
        $this->assertStringContainsString('Setup code active', $again);
        $this->assertStringContainsString('Cancel setup code', $again);
        $this->asUser('owner')->post($url)->assertRedirect()->assertSessionHasErrors('setup');   // one live code per branch

        // The wizard redeems exactly the displayed code (a separate process in real life: drop the tenant the previous
        // tenant-domain request left bound in this test app, or central.only would 404).
        app(\App\Services\Tenancy\TenancyManager::class)->deactivate();
        config(['database.connections.tenant.database' => $this->tenantDb]);
        $this->postJson($this->central('/api/edge/setup/redeem'), ['setup_code' => $display, 'installation_uuid' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('branch_id', $this->branchA);

        // Cancel (security control) kills the issued setup token too.
        $this->grantTo('owner', ['tenant.offline-edge.setup-code.cancel']);
        $this->asUser('owner')->post($this->tenantUrl('/settings/offline-edge/branches/' . $this->branchA . '/setup-code/cancel'))->assertRedirect();
        $this->assertNotNull($session->fresh()->cancelled_at);
    }

    public function test_the_device_row_shows_status_version_and_lan_facts_and_gates_the_counter_buttons(): void
    {
        $this->grantTo('owner', ['tenant.offline-edge.security', 'tenant.offline-edge.setup-code.generate']);
        $device = $this->makeDevice($this->branchA, 'page-secret');
        $device->forceFill(['install_phase' => 'ready', 'install_ok' => true, 'install_message' => 'All services running',
            'install_reported_at' => now(), 'reported_version' => '0.9.0-edge'])->save();

        $html = $this->asUser('owner')->get($this->tenantUrl('/settings/offline-edge'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<div class="mt-2 edge-device-status" data-device="' . preg_quote($device->public_uuid, '/') . '">(.*?)<\/form>/s', $html) ? 1 : 0);
        $this->assertStringContainsString('Ready', $html);
        $this->assertStringContainsString('All services running', $html);
        $this->assertStringContainsString('0.9.0-edge', $html);
        $this->assertStringContainsString('LAN address and certificate not reported yet', $html);
        $this->assertStringContainsString('Auto-update window 03:00–05:00 (Asia/Karachi)', $html);
        $this->assertMatchesRegularExpression('/<button class="btn btn-outline-secondary btn-sm" disabled title="Available after the Branch Server reports its LAN address and certificate"><i class="ti ti-device-desktop me-1"><\/i>Counter setup/', $html);
        $this->assertStringContainsString('Run setup again', $html);
        $this->assertStringContainsString('Set up a Branch Server', $html, 'branch B (no device) offers the setup');

        $cert = $this->giveLanProfile($device);
        $this->app->instance(CounterSetupRenderer::class, new FakeCounterSetupRenderer());
        $html = $this->asUser('owner')->get($this->tenantUrl('/settings/offline-edge'))->assertOk()->getContent();
        $this->assertStringContainsString('192.168.100.52:8443', $html);
        $this->assertStringContainsString(\App\Services\Edge\Cloud\DeviceProfileService::colonHex($cert['sha256']), $html, 'the full CA SHA-256 the counter wizard shows');
        $this->assertStringContainsString((string) app(\App\Services\Edge\Cloud\DeviceProfileService::class)->enrollmentKeyId(), $html, 'the Cloud key id the wizard shows');
        $this->assertStringContainsString('/settings/offline-edge/branches/' . $this->branchA . '/counter-setup', $html);
        $this->assertStringContainsString('/settings/offline-edge/branches/' . $this->branchA . '/phone', $html);
        $this->assertStringNotContainsString('LAN address and certificate not reported yet', $html);
    }

    public function test_update_now_and_the_maintenance_window(): void
    {
        $this->grantTo('owner', ['tenant.offline-edge.devices.update-now', 'tenant.offline-edge.devices.maintenance-window']);
        $device = $this->makeDevice($this->branchA, 'page-secret');

        $this->asUser('owner')->post($this->tenantUrl('/settings/offline-edge/devices/' . $device->id . '/update-now'))->assertRedirect();
        $this->assertNotNull($device->fresh()->update_now_requested_at);
        $this->asUser('owner')->get($this->tenantUrl('/settings/offline-edge'))->assertOk()->assertSee('Update requested');

        $mw = $this->tenantUrl('/settings/offline-edge/devices/' . $device->id . '/maintenance-window');
        $this->asUser('owner')->post($mw, ['start' => '01:00', 'end' => '02:30'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['01:00', '02:30'], [$device->fresh()->maintenance_window_start, $device->fresh()->maintenance_window_end]);
        $this->asUser('owner')->post($mw, ['start' => '25:00', 'end' => '02:30'])->assertSessionHasErrors('start');
        $this->asUser('owner')->post($mw, ['start' => '02:00', 'end' => '02:00'])->assertSessionHasErrors('end');
        $this->asUser('owner')->post($mw, ['start' => '', 'end' => ''])->assertSessionHasNoErrors();
        $this->assertNull($device->fresh()->maintenance_window_start);

        // Another tenant's device / a revoked device → 404.
        $foreign = EdgeDevice::create(['public_uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantId + 100000, 'branch_id' => $this->branchA,
            'installation_uuid' => (string) Str::uuid(), 'device_secret_hash' => hash('sha256', 'x'), 'status' => 'ready', 'active_slot' => 1]);
        $this->asUser('owner')->post($this->tenantUrl('/settings/offline-edge/devices/' . $foreign->id . '/update-now'))->assertNotFound();
        $this->assertNull($foreign->fresh()->update_now_requested_at);
        $foreign->delete();
        $device->forceFill(['status' => EdgeDevice::STATUS_REVOKED, 'active_slot' => null, 'revoked_at' => now(), 'update_now_requested_at' => null])->save();
        $this->asUser('owner')->post($this->tenantUrl('/settings/offline-edge/devices/' . $device->id . '/update-now'))->assertNotFound();
    }

    public function test_counter_setup_phone_page_and_ca_downloads(): void
    {
        $this->grantTo('owner', ['tenant.offline-edge.counter-setup', 'tenant.offline-edge.phone', 'tenant.offline-edge.branch.ca']);
        $base = '/settings/offline-edge/branches/' . $this->branchA;
        $this->asUser('owner')->get($this->tenantUrl($base . '/counter-setup'))->assertNotFound();   // no device yet
        $device = $this->makeDevice($this->branchA, 'page-secret');
        $this->asUser('owner')->get($this->tenantUrl($base . '/counter-setup'))->assertStatus(409);   // no LAN profile yet
        $this->asUser('owner')->get($this->tenantUrl($base . '/phone'))->assertStatus(409);
        $cert = $this->giveLanProfile($device);

        // Before stream D's renderer exists: a controlled message, not an error page.
        $this->asUser('owner')->get($this->tenantUrl($base . '/counter-setup'))->assertRedirect()->assertSessionHasErrors('counter');

        $fake = new FakeCounterSetupRenderer();
        $this->app->instance(CounterSetupRenderer::class, $fake);
        $ps1 = $this->asUser('owner')->get($this->tenantUrl($base . '/counter-setup'))->assertOk();
        $this->assertStringContainsString('attachment; filename="Setup-alpha-branch-Counter.ps1"', (string) $ps1->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $ps1->headers->get('Cache-Control'));
        $this->assertSame('# counter for Alpha Branch -> 192.168.100.52 bingoo-edge.local :8443 ' . $cert['sha256'] . ' online=' . $this->tenantUrl('/pos'), $ps1->getContent());
        $this->assertStringNotContainsString('PRIVATE KEY', $ps1->getContent());

        $phone = $this->asUser('owner')->get($this->tenantUrl($base . '/phone'))->assertOk();
        $html = $phone->getContent();
        $this->assertSame(2, substr_count($html, '<svg xmlns="http://www.w3.org/2000/svg"'), 'a QR for the CA link and one for the login URL');
        $this->assertStringContainsString('https://192.168.100.52:8443/edge/local/login', $html);
        $this->assertStringContainsString($cert['sha256'], $html);
        $caUrl = $fake->lastProfile['ca_download_url'];
        $this->assertStringContainsString('signature=', $caUrl);
        $this->assertStringStartsWith($this->tenantUrl($base . '/ca-public.crt'), $caUrl);

        // The signed CA link works WITHOUT a login …
        $this->app['auth']->forgetGuards();
        $pub = $this->get($caUrl)->assertOk();
        $this->assertSame($cert['pem'], $pub->getContent());
        $this->assertSame('application/x-x509-ca-cert', $pub->headers->get('Content-Type'));
        // … a tampered or expired link does not, and a rotated CA makes old links stale.
        $this->get(str_replace('signature=', 'signature=ff', $caUrl))->assertStatus(403);
        $this->giveLanProfile($device->fresh());   // the appliance reported a NEW CA
        $this->get($caUrl)->assertStatus(410);
        Carbon::setTestNow(now()->addMinutes(31));
        $this->get($caUrl)->assertStatus(403);
        Carbon::setTestNow();

        $ca = $this->asUser('owner')->get($this->tenantUrl($base . '/ca.crt'))->assertOk();
        $this->assertStringContainsString('-----BEGIN CERTIFICATE-----', $ca->getContent());
        $this->assertStringContainsString('bingoo-branch-ca.crt', (string) $ca->headers->get('Content-Disposition'));
    }

    public function test_every_new_action_is_refused_without_its_permission_or_the_entitlement(): void
    {
        $device = $this->makeDevice($this->branchA, 'page-secret');
        $this->giveLanProfile($device);
        $base = '/settings/offline-edge/branches/' . $this->branchA;
        $calls = [
            ['post', $base . '/setup-code'], ['post', '/settings/offline-edge/devices/' . $device->id . '/update-now'],
            ['post', '/settings/offline-edge/devices/' . $device->id . '/maintenance-window'], ['get', $base . '/counter-setup'],
            ['get', $base . '/phone'], ['get', $base . '/ca.crt'], ['post', $base . '/setup-code/cancel'],
        ];
        foreach ($calls as [$method, $path]) {
            $this->asUser('cashier')->{$method}($this->tenantUrl($path))->assertStatus(403);
        }
        $this->assertSame(0, EdgeSetupSession::count());
        $this->assertNull($device->fresh()->update_now_requested_at);

        // Entitlement / rollout withdrawn: the setup actions close even for an Owner holding every permission.
        $this->grantTo('owner', ['tenant.offline-edge.security', 'tenant.offline-edge.setup-code.generate', 'tenant.offline-edge.devices.update-now', 'tenant.offline-edge.phone']);
        config(['edge.testing.assume_entitled' => false, 'app.edge_feature_enabled' => false]);
        $this->asUser('owner')->post($this->tenantUrl($base . '/setup-code'))->assertStatus(403);
        $this->asUser('owner')->post($this->tenantUrl('/settings/offline-edge/devices/' . $device->id . '/update-now'))->assertStatus(403);
        $this->asUser('owner')->get($this->tenantUrl($base . '/phone'))->assertStatus(403);
        $this->assertSame(0, EdgeSetupSession::count());
    }
}

/** A stand-in for stream D's EdgeCounterSetupRenderer (the contract only). */
class FakeCounterSetupRenderer implements CounterSetupRenderer
{
    public array $lastProfile = [];

    public function renderWindows(array $lanProfile, string $onlinePosUrl, string $branchLabel): string
    {
        return '# counter for ' . $branchLabel . ' -> ' . $lanProfile['lan_ip'] . ' ' . $lanProfile['lan_hostname'] . ' :' . $lanProfile['https_port']
            . ' ' . $lanProfile['ca_sha256'] . ' online=' . $onlinePosUrl;
    }

    public function phoneOnboarding(array $lanProfile): array
    {
        $this->lastProfile = $lanProfile;

        return [];
    }

    public function renderWindowsWizard(array $lanProfile, string $onlinePosUrl, string $branchLabel, array $context = []): string
    {
        return '# wizard for ' . $branchLabel;
    }
}
