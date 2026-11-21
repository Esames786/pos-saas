<?php

namespace Tests\MySql;

use App\Contracts\Edge\CounterSetupRenderer;
use App\Exceptions\BranchLocalEdgeException;
use App\Models\Master\EdgeDevice;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Services\Edge\BranchOperatingModeService;
use App\Services\Edge\Cloud\CounterBundleService;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgePosRuntimeFactory;
use App\Support\Pos\CloudPosRuntimeFactory;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\EdgeSetupCloudFixture;

/**
 * EDGE 0.9 stream C — the owner's 21 Nov additions over the REAL tenant HTTP stack:
 *  (1) the optional, default-OFF Automatic Offline Takeover branch setting: security-permission only, validated 180–3600 s,
 *      audited, and delivered to the appliance by the next config refresh as restrictions[0].auto_takeover (the table-backed
 *      branch row is untouched, so 0.8.x appliances keep refreshing);
 *  (2) the Cloud's own lease-expired fence presented as BRANCH_SERVER_UNREACHABLE (fence decision unchanged);
 *  (3) self-service "Install Offline Counter": own branch only, signed counter bundle, an enrollment assertion for the
 *      requesting user ONLY, terminal hint, refusals for no permission / other branch / other tenant / no device / no LAN.
 */
class OfflineEdgeAutoTakeoverCounterHttpMySqlTest extends MySqlTenantTestCase
{
    use EdgeSetupCloudFixture;

    public const TENANT_CODE = 'e09cauto';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->seedEdgeCloud(['tenant.offline-edge.index']);
        DB::connection('tenant')->table('edge_branch_authority_leases')->delete();
    }

    protected function tearDown(): void
    {
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

    private function freshTenantConnection(): void
    {
        app(\App\Services\Tenancy\TenancyManager::class)->deactivate();
        config(['database.connections.tenant.database' => $this->tenantDb]);
        DB::purge('tenant');
    }

    /* ── (1) Automatic Offline Takeover ─────────────────────────────────── */

    public function test_auto_takeover_is_security_gated_validated_audited_and_reaches_the_refresh_payload(): void
    {
        $device = $this->makeDevice($this->branchA, 'auto-secret');
        $url = $this->tenantUrl('/settings/offline-edge/branches/' . $this->branchA . '/auto-takeover');
        $refresh = function () use ($device) {
            $this->freshTenantConnection();

            return app(EdgeBootstrapService::class)->refreshPackage(EdgeDevice::findOrFail($device->id));
        };

        // Default OFF, delivered as such; the branch row carries no new key.
        $before = $refresh();
        $this->assertSame(['enabled' => false, 'after_seconds' => 300, 'set_by' => null, 'set_at' => null], $before['sections']['restrictions'][0]['auto_takeover']);
        $this->assertArrayNotHasKey('auto_takeover', $before['sections']['branch'][0]);
        $branchKeys = array_keys($before['sections']['branch'][0]);

        $this->grantTo('owner', ['tenant.offline-edge.auto-takeover']);
        $this->asUser('owner')->post($url, ['enabled' => 1, 'after_seconds' => 240])->assertStatus(403);   // route permission alone is not enough
        $this->grantTo('owner', ['tenant.offline-edge.security']);
        $this->asUser('owner')->post($url, ['enabled' => 1, 'after_seconds' => 100])->assertSessionHasErrors('after_seconds');
        $this->asUser('owner')->post($url, ['enabled' => 1, 'after_seconds' => 4000])->assertSessionHasErrors('after_seconds');

        Log::spy();
        $this->asUser('owner')->post($url, ['enabled' => 1, 'after_seconds' => 240])->assertRedirect()->assertSessionHasNoErrors();
        Log::shouldHaveReceived('info')->withArgs(fn ($m, $c = []) => $m === '[edge-setup-audit] edge.branch.auto_takeover_changed'
            && $c['enabled'] === true && $c['after_seconds'] === 240 && $c['previous_enabled'] === false && $c['user_id'] === $this->users['owner'])->once();
        $row = DB::connection('tenant')->table('branches')->where('id', $this->branchA)->first();
        $this->assertSame(1, (int) $row->edge_auto_takeover_enabled);
        $this->assertSame(240, (int) $row->edge_auto_takeover_after_seconds);
        $this->assertSame($this->users['owner'], (int) $row->edge_auto_takeover_set_by_user_id);

        $after = $refresh();
        $at = $after['sections']['restrictions'][0]['auto_takeover'];
        $this->assertTrue($at['enabled']);
        $this->assertSame(240, $at['after_seconds']);
        $this->assertSame($this->users['owner'], $at['set_by']);
        $this->assertNotNull($at['set_at']);
        $this->assertGreaterThan($before['manifest']['config_revision'], $after['manifest']['config_revision'], 'the change mints a new config revision');
        $this->assertSame($branchKeys, array_keys($after['sections']['branch'][0]), 'the table-backed branch row keeps its exact keys');
        $this->assertNotSame($before['manifest']['sections']['restrictions']['hash'], $after['manifest']['sections']['restrictions']['hash']);

        // The page shows it; switching OFF works even after the entitlement is gone (security control).
        $this->asUser('owner')->get($this->tenantUrl('/settings/offline-edge'))->assertOk()->assertSee('ON after 240 s');
        config(['edge.testing.assume_entitled' => false, 'app.edge_feature_enabled' => false]);
        $this->asUser('owner')->post($url, ['enabled' => 1, 'after_seconds' => 300])->assertStatus(403);   // turning ON needs the entitlement
        $this->asUser('owner')->post($url, ['enabled' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, (int) DB::connection('tenant')->table('branches')->where('id', $this->branchA)->value('edge_auto_takeover_enabled'));

        // A cashier without the permissions cannot touch it.
        $this->asUser('cashier')->post($url, ['enabled' => 1, 'after_seconds' => 300])->assertStatus(403);
    }

    /* ── (2) the Online POS message when the Branch Server stopped heartbeating ─── */

    private function lease(array $x): void
    {
        DB::connection('tenant')->table('edge_branch_authority_leases')->delete();
        DB::connection('tenant')->table('edge_branch_authority_leases')->insert(array_merge([
            'branch_id' => $this->branchA, 'device_public_uuid' => (string) Str::uuid(), 'holder' => 'cloud', 'edge_state' => 'standby',
            'heartbeat_seq' => 42, 'lease_ttl_seconds' => 120, 'last_heartbeat_at' => now()->subMinutes(10), 'expires_at' => now()->subMinutes(8),
            'created_at' => now(), 'updated_at' => now(),
        ], $x));
    }

    private function fenceCode(): ?string
    {
        try {
            app(BranchOperatingModeService::class)->assertSaleMutationAllowed(Branch::on('tenant')->findOrFail($this->branchA));

            return null;
        } catch (BranchLocalEdgeException $e) {
            return $e->reasonCode;
        }
    }

    public function test_the_lease_expired_fence_is_presented_as_branch_server_unreachable_without_changing_the_decision(): void
    {
        $this->assertNull($this->fenceCode(), 'no lease row: the branch is untouched');

        $this->lease([]);   // holder cloud, heartbeat stopped, lease expired
        $this->assertSame(BranchLocalEdgeException::CODE_BRANCH_SERVER_UNREACHABLE, $this->fenceCode());
        try {
            app(BranchOperatingModeService::class)->assertSaleMutationAllowed(Branch::on('tenant')->findOrFail($this->branchA));
            $this->fail('the fence must still refuse');
        } catch (BranchLocalEdgeException $e) {
            $this->assertSame(BranchLocalEdgeException::UNREACHABLE_MESSAGE, $e->getMessage());
            $req = Request::create('/pos', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
            $res = $e->render($req);
            $this->assertSame(409, $res->getStatusCode());
            $body = json_decode($res->getContent(), true);
            $this->assertSame('BRANCH_SERVER_UNREACHABLE', $body['code']);
            $this->assertStringContainsString('Branch Server not reachable', $body['message']);
            $this->assertNotEmpty($body['branch_server_last_seen_at']);
        }

        $this->lease(['holder' => 'edge', 'edge_state' => 'local_active']);   // the Branch Server holds authority: the generic wording
        $this->assertSame(BranchLocalEdgeException::CODE_ACTIVE, $this->fenceCode());

        $this->lease(['expires_at' => now()->addMinute(), 'last_heartbeat_at' => now()]);   // healthy lease: no fence at all
        $this->assertNull($this->fenceCode());

        $this->lease([]);
        DB::connection('tenant')->table('branches')->where('id', $this->branchA)->update(['local_edge_status' => 'active']);   // handed by mode
        $this->assertSame(BranchLocalEdgeException::CODE_ACTIVE, $this->fenceCode(), 'a branch handed to its server keeps the generic wording');
    }

    /* ── (3) self-service Install Offline Counter ───────────────────────── */

    private function counterReady(): EdgeDevice
    {
        $device = $this->makeDevice($this->branchA, 'counter-secret');
        $this->giveLanProfile($device);
        $c = DB::connection('tenant');
        $term = $c->table('terminals')->insertGetId(['branch_id' => $this->branchA, 'code' => 'T1', 'name' => 'Till 1', 'created_at' => now(), 'updated_at' => now()]);
        $c->table('users')->where('id', $this->users['cashier'])->update(['default_terminal_id' => $term]);
        $this->app->instance(CounterSetupRenderer::class, new class implements CounterSetupRenderer {
            public function renderWindows(array $lanProfile, string $onlinePosUrl, string $branchLabel): string
            {
                return "# counter setup for {$branchLabel} {$lanProfile['lan_ip']}\r\n";
            }

            public function phoneOnboarding(array $lanProfile): array
            {
                return [];
            }
        });
        $this->grantTo('cashier', ['tenant.offline-edge.counter-install']);

        return $device;
    }

    public function test_a_cashier_downloads_the_signed_counter_setup_for_their_own_branch_and_optionally_their_own_enrollment(): void
    {
        $device = $this->counterReady();
        $page = $this->asUser('cashier')->get($this->tenantUrl('/pos/offline-counter'))->assertOk();
        $page->assertSee('Alpha Branch')->assertSee('192.168.100.52')->assertSee('Download with my Edge PIN setup');

        $res = $this->asUser('cashier')->get($this->tenantUrl('/pos/offline-counter/setup'))->assertOk();
        $this->assertStringContainsString('attachment; filename="Setup-alpha-branch-Counter.ps1"', (string) $res->headers->get('Content-Disposition'));
        $script = $res->getContent();
        $this->assertStringStartsWith('# counter setup for Alpha Branch 192.168.100.52', $script);
        $bundle = CounterBundleService::extract($script);
        $this->assertIsArray($bundle);
        $this->assertTrue(EdgeEnrollmentCrypto::verifySignature($bundle, $this->enrollmentKeys['public']), 'the bundle verifies with the enrollment PUBLIC key');
        $p = $bundle['payload'];
        $this->assertSame('edge-counter-bundle', $p['purpose']);
        $this->assertSame($this->branchA, $p['branch_id']);
        $this->assertSame($device->public_uuid, $p['branch_server']['device_public_uuid']);
        $this->assertSame($device->fresh()->lan_ca_sha256, $p['branch_server']['ca_sha256']);
        $this->assertSame($this->users['cashier'], $p['user']['id']);
        $this->assertSame('T1', $p['terminal_hint']['code']);
        $this->assertNull($p['self_enrollment'], 'GET never carries an enrollment assertion');
        $this->assertSame($this->tenantUrl('/pos'), $p['online_pos_url']);
        $this->assertLessThanOrEqual(900, $p['expires_at'] - $p['issued_at']);
        $this->assertStringNotContainsString('PRIVATE KEY', base64_decode(preg_replace('/\s+/', '', (string) preg_replace('/^.*?v1\r?\n|BINGOO-COUNTER-BUNDLE-END.*$/s', '', $script))) ?: '');

        // A bundle is NOT an enrollment assertion (different purpose) — tampering breaks it.
        $this->assertNotSame(EdgeEnrollmentCrypto::PURPOSE, $p['purpose']);
        $bad = $bundle;
        $bad['payload']['user']['id'] = $this->users['owner'];
        $this->assertFalse(EdgeEnrollmentCrypto::verifySignature($bad, $this->enrollmentKeys['public']));

        // POST my-enrollment: ONE assertion, for the requesting user themselves, issued by themselves, audited.
        Log::spy();
        $mine = CounterBundleService::extract($this->asUser('cashier')->post($this->tenantUrl('/pos/offline-counter/my-enrollment'))->assertOk()->getContent());
        $a = $mine['payload']['self_enrollment']['assertion'];
        $this->assertTrue(EdgeEnrollmentCrypto::verifySignature($a, $this->enrollmentKeys['public']));
        $this->assertSame(EdgeEnrollmentCrypto::PURPOSE, $a['payload']['purpose']);
        $this->assertSame($this->users['cashier'], $a['payload']['user_id']);
        $this->assertSame($this->users['cashier'], $a['payload']['issuer_user_id']);
        $this->assertSame($device->public_uuid, $a['payload']['device_public_uuid']);
        Log::shouldHaveReceived('info')->withArgs(fn ($m, $c = []) => $m === '[edge-enrollment-issued]' && ($c['via'] ?? null) === 'self_service_counter'
            && $c['target_user_id'] === $this->users['cashier'] && ! array_key_exists('assertion', $c))->once();
        // The body cannot ask for someone else: any user_id input is ignored.
        $other = CounterBundleService::extract($this->asUser('cashier')->post($this->tenantUrl('/pos/offline-counter/my-enrollment'), ['user_id' => $this->users['owner']])->getContent());
        $this->assertSame($this->users['cashier'], $other['payload']['self_enrollment']['assertion']['payload']['user_id']);

        // The Online POS entry: Cloud runtime offers it to this user; the Edge runtime never does.
        $this->actingAs(User::on('tenant')->find($this->users['cashier']), 'tenant');
        $this->assertSame('/pos/offline-counter', app(CloudPosRuntimeFactory::class)->make($this->branchA, 'Alpha Branch')->route('offlineCounterInstall'));
        $this->assertContains('offlineCounterInstall', \App\Support\Pos\PosRuntime::ROUTE_KEYS);
        $this->assertStringContainsString("'offlineCounterInstall' => null", (string) file_get_contents(base_path('app/Services/Edge/EdgePosRuntimeFactory.php')));
        $this->actingAs(User::on('tenant')->find($this->users['owner']), 'tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertNull(app(CloudPosRuntimeFactory::class)->make($this->branchA, 'Alpha Branch')->route('offlineCounterInstall'), 'no permission → no entry');
    }

    public function test_the_counter_downloads_refuse_without_permission_other_branch_other_tenant_no_device_no_lan_no_code(): void
    {
        $device = $this->counterReady();

        // No permission (owner lacks counter-install here).
        $this->asUser('owner')->get($this->tenantUrl('/pos/offline-counter'))->assertStatus(403);
        $this->asUser('owner')->get($this->tenantUrl('/pos/offline-counter/setup'))->assertStatus(403);
        $this->asUser('owner')->post($this->tenantUrl('/pos/offline-counter/my-enrollment'))->assertStatus(403);

        // Another branch's user (default branch B): branch B has no Branch Server — and A's server is never offered,
        // not even when ANOTHER TENANT has a device registered under branch B's id.
        $this->grantTo('bravo', ['tenant.offline-edge.counter-install']);
        EdgeDevice::create(['public_uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantId + 100000, 'branch_id' => $this->branchB,
            'installation_uuid' => (string) Str::uuid(), 'device_secret_hash' => hash('sha256', 'x'), 'status' => 'ready', 'active_slot' => 1,
            'lan_hostname' => 'evil.local', 'lan_ip' => '10.9.9.9', 'lan_https_port' => 8443, 'lan_ca_pem' => $device->fresh()->lan_ca_pem, 'lan_ca_sha256' => $device->fresh()->lan_ca_sha256]);
        $this->asUser('bravo')->get($this->tenantUrl('/pos/offline-counter'))->assertOk()->assertSee('No Branch Server is set up for Bravo Branch')->assertDontSee('192.168.100.52');
        $this->asUser('bravo')->get($this->tenantUrl('/pos/offline-counter/setup'))->assertRedirect()->assertSessionHasErrors('counter');
        $this->asUser('bravo')->postJson($this->tenantUrl('/pos/offline-counter/my-enrollment'))->assertStatus(409)->assertJsonPath('reason', 'no_device');
        EdgeDevice::where('tenant_id', $this->tenantId + 100000)->delete();

        // No LAN profile yet.
        $device->forceFill(['lan_ca_pem' => null, 'lan_ca_sha256' => null])->save();
        $this->asUser('cashier')->postJson($this->tenantUrl('/pos/offline-counter/my-enrollment'))->assertStatus(409)->assertJsonPath('reason', 'no_lan_profile');
        $this->giveLanProfile($device->fresh());

        // A user without an employee code gets the setup but no enrollment.
        $this->grantTo('nocode', ['tenant.offline-edge.counter-install']);
        $this->asUser('nocode')->get($this->tenantUrl('/pos/offline-counter/setup'))->assertOk();
        $this->asUser('nocode')->postJson($this->tenantUrl('/pos/offline-counter/my-enrollment'))->assertStatus(409)->assertJsonPath('reason', 'no_employee_code');

        // Entitlement withdrawn → closed.
        config(['edge.testing.assume_entitled' => false, 'app.edge_feature_enabled' => false]);
        $this->asUser('cashier')->get($this->tenantUrl('/pos/offline-counter/setup'))->assertStatus(403);
    }
}
