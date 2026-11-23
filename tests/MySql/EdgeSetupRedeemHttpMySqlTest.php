<?php

namespace Tests\MySql;

use App\Models\Master\EdgeDevice;
use App\Models\Master\EdgeSetupSession;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Services\Edge\Cloud\EdgeSetupException;
use App\Services\Edge\Cloud\SetupSessionService;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgePairingService;
use App\Services\Edge\EdgeSigningKeyStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeSetupCloudFixture;

/**
 * EDGE 0.9 stream C — K2 setup-code redeem and K3 bulk enrollment assertions over the REAL central-domain HTTP stack
 * (central.only, throttles, AuthenticateEdgeDevice), against the real master + tenant schema.
 *
 * Proves: the K2 payload (fresh pairing code minted by the EXISTING pairing service, enrollment PUBLIC key derived from the
 * Cloud signing key, central Cloud URL, default maintenance window) and that the pairing code really pairs through the
 * unchanged /api/edge/pair; single use; expiry; IP throttle on unknown codes; per-code attempt limit; a hand-made pairing code
 * is never replaced (and the setup code survives that refusal); update mode for a paired branch; one live code per branch;
 * nothing secret stored in clear. K3: device auth + a live, scoped setup token; per-user assertions that verify against the
 * public key with the session owner as issuer; skip reasons; one audit line per assertion without the assertion body.
 */
class EdgeSetupRedeemHttpMySqlTest extends MySqlTenantTestCase
{
    use EdgeSetupCloudFixture;

    public const TENANT_CODE = 'e09csetup';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEdgeCloud();
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

    /** @return array{code:string, session:EdgeSetupSession} */
    private function createCode(int $branchId): array
    {
        $tenant = Tenant::findOrFail($this->tenantId);
        $r = app(SetupSessionService::class)->create($tenant, Branch::on('tenant')->findOrFail($branchId), $this->users['owner']);

        return ['code' => $r['code'], 'session' => $r['session']];
    }

    private function redeem(string $code, ?string $installation = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->central('/api/edge/setup/redeem'), [
            'setup_code' => $code, 'installation_uuid' => $installation ?? (string) Str::uuid(), 'device_name' => 'Counter PC',
        ]);
    }

    public function test_redeem_returns_the_k2_payload_and_its_pairing_code_pairs_through_the_existing_endpoint(): void
    {
        $update = EdgeEnrollmentCrypto::generateKeypair();
        config(['edge.update.public_key' => $update['public']]);
        ['code' => $code, 'session' => $session] = $this->createCode($this->branchA);
        $this->assertSame(10, strlen($code));
        $this->assertMatchesRegularExpression('/^[' . SetupSessionService::ALPHABET . ']{10}$/', $code);

        $installation = (string) Str::uuid();
        // The wizard may send the displayed form (dashes, lower case).
        $res = $this->redeem(strtolower(SetupSessionService::display($code)), $installation)->assertOk();
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $j = $res->json();
        $this->assertSame('install', $j['mode']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $j['pairing_code']);
        $this->assertSame($this->enrollmentKeys['public'], $j['enrollment_public_key']);
        $this->assertSame(EdgeSigningKeyStore::keyId($update['public']), $j['update_public_key_fingerprint']);
        $this->assertSame((parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https') . '://' . config('tenancy.central_domain'), $j['cloud_url']);
        $this->assertSame(self::TENANT_CODE, $j['tenant_code']);
        $this->assertSame($this->branchA, $j['branch_id']);
        $this->assertSame('Alpha Branch', $j['branch_name']);
        $this->assertSame(['start' => '03:00', 'end' => '05:00', 'tz' => 'Asia/Karachi'], $j['maintenance_window']);
        $this->assertNull($j['device_id']);
        $this->assertGreaterThanOrEqual(40, strlen((string) $j['setup_token']));
        $this->assertArrayNotHasKey('signing_key', $j);
        $this->assertStringNotContainsString($this->enrollmentKeys['secret'], $res->getContent(), 'the private key never leaves the Cloud');

        // Nothing secret is stored in clear.
        $row = EdgeSetupSession::findOrFail($session->id);
        $this->assertSame(hash('sha256', $j['setup_token']), $row->setup_token_hash);
        $this->assertNotSame($code, $row->code_hash);
        $this->assertNotNull($row->redeemed_at);
        $this->assertNull($row->active_slot);
        $this->assertSame($installation, $row->redeemed_installation_uuid);
        $this->assertNotNull($row->pairing_code_uuid);
        $flat = json_encode(DB::connection('master')->table('edge_setup_sessions')->where('id', $session->id)->first());
        $this->assertStringNotContainsString($code, $flat);
        $this->assertStringNotContainsString($j['setup_token'], $flat);
        $this->assertStringNotContainsString((string) $j['pairing_code'], json_encode(DB::connection('master')->table('edge_pairing_codes')->where('tenant_id', $this->tenantId)->get()));

        // The minted pairing code is an ordinary, live pairing code of branch A (existing service) …
        $pc = DB::connection('master')->table('edge_pairing_codes')->where('public_uuid', $row->pairing_code_uuid)->first();
        $this->assertSame(1, (int) $pc->active_slot);
        $this->assertSame($this->branchA, (int) $pc->branch_id);
        $this->assertSame(Branch::STATUS_PENDING, DB::connection('tenant')->table('branches')->where('id', $this->branchA)->value('local_edge_status'));

        // … and it pairs through the UNCHANGED /api/edge/pair.
        $secret = 'wizard-device-secret';
        $pair = $this->postJson($this->central('/api/edge/pair'), [
            'pairing_code' => $j['pairing_code'], 'installation_uuid' => $installation, 'device_name' => 'Counter PC',
            'device_secret_hash' => hash('sha256', $secret), 'app_version' => '0.9.0-edge',
        ])->assertOk();
        $device = EdgeDevice::where('public_uuid', $pair->json('device_id'))->firstOrFail();
        $this->assertSame(EdgeDevice::STATUS_PENDING_BOOTSTRAP, $device->status);
        $this->assertSame($this->branchA, (int) $device->branch_id);

        // After bootstrap (ready + activation generation) the same setup token drives K3 for that device.
        $device->forceFill(['status' => EdgeDevice::STATUS_READY])->save();
        DB::connection('master')->table('edge_branch_activations')->insert(['tenant_id' => $this->tenantId, 'branch_id' => $this->branchA,
            'generation' => 1, 'edge_device_id' => $device->id, 'device_public_uuid' => $device->public_uuid, 'reason' => 'initial',
            'created_at' => now(), 'updated_at' => now()]);
        $k3 = $this->postJson($this->central('/api/edge/setup/enrollment-assertions'), ['user_ids' => [$this->users['owner']]],
            $this->deviceHeaders($device, $secret) + ['X-Edge-Setup-Token' => $j['setup_token']])->assertOk();
        $this->assertSame($this->users['owner'], $k3->json('assertions.0.user_id'));
        $this->assertTrue(EdgeEnrollmentCrypto::verifySignature($k3->json('assertions.0.assertion'), $j['enrollment_public_key']));
    }

    public function test_a_setup_code_is_single_use(): void
    {
        ['code' => $code] = $this->createCode($this->branchA);
        $this->redeem($code)->assertOk();
        $this->redeem($code)->assertStatus(409)->assertJsonPath('code', EdgeSetupException::CODE_USED);
        $this->assertSame(1, DB::connection('master')->table('edge_pairing_codes')->where('tenant_id', $this->tenantId)->count(), 'the refused replay minted nothing');
    }

    public function test_an_expired_code_is_refused_and_mints_nothing(): void
    {
        ['code' => $code, 'session' => $s] = $this->createCode($this->branchA);
        Carbon::setTestNow(now()->addMinutes(SetupSessionService::CODE_TTL_MINUTES + 1));
        $this->redeem($code)->assertStatus(422)->assertJsonPath('code', EdgeSetupException::CODE_EXPIRED);
        $this->assertSame(0, DB::connection('master')->table('edge_pairing_codes')->where('tenant_id', $this->tenantId)->count());
        $this->assertNull(EdgeSetupSession::findOrFail($s->id)->active_slot, 'an expired code leaves the live slot');
        $this->assertNull(EdgeSetupSession::findOrFail($s->id)->redeemed_at);
    }

    public function test_unknown_codes_are_bounded_by_the_ip_throttle(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->redeem('ZZZZZ-ZZZZ' . SetupSessionService::ALPHABET[$i])->assertStatus(422)->assertJsonPath('code', EdgeSetupException::CODE_INVALID);
        }
        $this->redeem('ZZZZZ-ZZZZZ')->assertStatus(429);
    }

    public function test_failures_on_a_resolved_code_are_counted_and_burn_it(): void
    {
        ['code' => $code, 'session' => $s] = $this->createCode($this->branchA);
        config(['edge.testing.assume_entitled' => false, 'app.edge_feature_enabled' => false]);
        for ($i = 0; $i < SetupSessionService::MAX_ATTEMPTS; $i++) {
            $this->redeem($code)->assertStatus(403)->assertJsonPath('code', EdgeSetupException::ENTITLEMENT);
        }
        config(['edge.testing.assume_entitled' => true]);
        $this->redeem($code)->assertStatus(422)->assertJsonPath('code', EdgeSetupException::CODE_EXHAUSTED);
        $row = EdgeSetupSession::findOrFail($s->id);
        $this->assertSame(SetupSessionService::MAX_ATTEMPTS, $row->attempts);
        $this->assertNull($row->active_slot);
        $this->assertNull($row->redeemed_at);
    }

    public function test_a_hand_made_pairing_code_is_never_replaced_and_the_setup_code_survives_the_refusal(): void
    {
        $tenant = Tenant::findOrFail($this->tenantId);
        $branch = Branch::on('tenant')->findOrFail($this->branchA);
        $manual = app(EdgePairingService::class)->generateCode($tenant, $branch, $this->users['owner']);
        ['code' => $code, 'session' => $s] = $this->createCode($this->branchA);

        $this->redeem($code)->assertStatus(409)->assertJsonPath('code', EdgeSetupException::PAIRING_CODE_ACTIVE);
        $this->assertSame(1, (int) DB::connection('master')->table('edge_pairing_codes')->where('public_uuid', $manual['public_uuid'])->value('active_slot'), 'the owner\'s code is untouched');
        $row = EdgeSetupSession::findOrFail($s->id);
        $this->assertNull($row->redeemed_at, 'the claim was released');
        $this->assertNull($row->setup_token_hash);
        $this->assertSame(1, $row->active_slot);

        app(EdgePairingService::class)->cancelCode($tenant, $branch->fresh(), $this->users['owner']);
        $this->redeem($code)->assertOk()->assertJsonPath('mode', 'install');
    }

    public function test_a_paired_branch_gets_an_update_session_without_a_pairing_code(): void
    {
        $device = $this->makeDevice($this->branchA, 'secret-a');
        ['code' => $code] = $this->createCode($this->branchA);
        $res = $this->redeem($code, $device->installation_uuid)->assertOk();
        $this->assertSame('update', $res->json('mode'));
        $this->assertNull($res->json('pairing_code'));
        $this->assertSame($device->public_uuid, $res->json('device_id'));
        $this->assertSame(0, DB::connection('master')->table('edge_pairing_codes')->where('tenant_id', $this->tenantId)->count());
    }

    public function test_one_live_setup_code_per_branch_and_cancel_frees_it(): void
    {
        $this->createCode($this->branchA);
        try {
            $this->createCode($this->branchA);
            $this->fail('a second live setup code must be refused');
        } catch (EdgeSetupException $e) {
            $this->assertSame(EdgeSetupException::CODE_ALREADY_ACTIVE, $e->setupCode);
        }
        $this->createCode($this->branchB); // another branch is independent
        app(SetupSessionService::class)->cancel(Tenant::findOrFail($this->tenantId), $this->branchA, $this->users['owner']);
        ['code' => $code] = $this->createCode($this->branchA);
        $this->redeem($code)->assertOk();
    }

    /** CP5 pre-flight (22 Nov 2026): the redeem's cloud_url keeps a non-default port of app.url on the central host. */
    public function test_redeem_advertises_the_central_url_with_a_non_default_port_and_production_unchanged(): void
    {
        $central = (string) config('tenancy.central_domain');
        config(['app.url' => 'http://' . $central . ':9831']);
        ['code' => $code] = $this->createCode($this->branchA);
        $this->redeem($code)->assertOk()->assertJsonPath('cloud_url', 'http://' . $central . ':9831');

        app(SetupSessionService::class)->cancel(Tenant::findOrFail($this->tenantId), $this->branchA, $this->users['owner']);
        DB::connection('master')->table('edge_pairing_codes')->where('tenant_id', $this->tenantId)->update(['active_slot' => null, 'cancelled_at' => now()]);
        config(['app.url' => 'https://' . $central]);
        ['code' => $code2] = $this->createCode($this->branchA);
        $this->redeem($code2)->assertOk()->assertJsonPath('cloud_url', 'https://' . $central);
    }

    public function test_redeem_fails_closed_without_an_enrollment_signing_key(): void
    {
        ['code' => $code, 'session' => $s] = $this->createCode($this->branchA);
        config(['edge.enrollment.signing_key' => null]);
        $this->redeem($code)->assertStatus(503)->assertJsonPath('code', EdgeSetupException::NOT_CONFIGURED);
        $this->assertNull(EdgeSetupSession::findOrFail($s->id)->redeemed_at, 'the code was not consumed');
    }

    /* ── K3 ─────────────────────────────────────────────────────────────── */

    /** A redeemed UPDATE session for an active device on branch A; returns [device, secret, token]. */
    private function deviceWithToken(int $branchId = 0): array
    {
        $branchId = $branchId ?: $this->branchA;
        $secret = 'secret-' . $branchId;
        $device = $this->makeDevice($branchId, $secret);
        ['code' => $code] = $this->createCode($branchId);
        $token = $this->redeem($code, $device->installation_uuid)->assertOk()->json('setup_token');

        return [$device, $secret, $token];
    }

    public function test_bulk_assertions_verify_name_the_session_owner_skip_with_reasons_and_audit_each_one(): void
    {
        [$device, $secret, $token] = $this->deviceWithToken();
        Log::spy();
        $u = $this->users;
        $res = $this->postJson($this->central('/api/edge/setup/enrollment-assertions'), [
            'user_ids' => [$u['owner'], $u['cashier'], $u['manager'], $u['inactive'], $u['nocode'], $u['bravo'], 999999],
        ], $this->deviceHeaders($device, $secret) + ['X-Edge-Setup-Token' => $token])->assertOk();

        $this->assertSame([$u['owner'], $u['cashier'], $u['manager']], array_column($res->json('assertions'), 'user_id'));
        $this->assertSame(['OWN1', 'CSH1', 'MGR1'], array_column($res->json('assertions'), 'employee_code'));
        foreach ($res->json('assertions') as $a) {
            $this->assertTrue(EdgeEnrollmentCrypto::verifySignature($a['assertion'], $this->enrollmentKeys['public']));
            $p = $a['assertion']['payload'];
            $this->assertSame($u['owner'], $p['issuer_user_id'], 'issuer = the setup session owner');
            $this->assertSame($a['user_id'], $p['user_id']);
            $this->assertSame($this->tenantId, $p['tenant_id']);
            $this->assertSame($this->branchA, $p['branch_id']);
            $this->assertSame($device->public_uuid, $p['device_public_uuid']);
            $this->assertSame(1, $p['activation_epoch']);
            $this->assertLessThanOrEqual(900, $p['expires_at'] - $p['issued_at']);
        }
        $skipped = collect($res->json('skipped'))->mapWithKeys(fn ($s) => [$s['user_id'] => $s['reason']])->all();
        $this->assertSame([
            999999 => 'not_found', $u['inactive'] => 'inactive', $u['nocode'] => 'no_employee_code', $u['bravo'] => 'not_on_branch',
        ], array_replace([999999 => null, $u['inactive'] => null, $u['nocode'] => null, $u['bravo'] => null], $skipped));

        Log::shouldHaveReceived('info')->withArgs(function ($msg, $ctx = []) use ($device) {
            return $msg === '[edge-enrollment-issued]' && ($ctx['via'] ?? null) === 'setup_session'
                && ($ctx['device'] ?? null) === $device->public_uuid && ! array_key_exists('assertion', $ctx)
                && ! array_key_exists('signature', $ctx) && isset($ctx['jti'], $ctx['target_user_id']);
        })->times(3);

        // all_active: every active, coded user of the branch (default branch OR active assignment); others skipped.
        $all = $this->postJson($this->central('/api/edge/setup/enrollment-assertions'), ['all_active' => true],
            $this->deviceHeaders($device, $secret) + ['X-Edge-Setup-Token' => $token])->assertOk();
        $this->assertSame([$u['owner'], $u['cashier'], $u['manager']], array_column($all->json('assertions'), 'user_id'));
        $this->assertEqualsCanonicalizing([['user_id' => $u['inactive'], 'reason' => 'inactive'], ['user_id' => $u['nocode'], 'reason' => 'no_employee_code']], $all->json('skipped'));
        $this->assertSame(6, (int) DB::connection('master')->table('edge_setup_sessions')->where('tenant_id', $this->tenantId)->where('branch_id', $this->branchA)->whereNotNull('redeemed_at')->value('assertions_issued'));
    }

    public function test_bulk_assertions_need_device_auth_and_a_live_token_of_the_same_branch_and_installation(): void
    {
        [$device, $secret, $token] = $this->deviceWithToken();
        $url = $this->central('/api/edge/setup/enrollment-assertions');
        $body = ['user_ids' => [$this->users['owner']]];

        $this->postJson($url, $body, ['X-Edge-Setup-Token' => $token])->assertStatus(401);
        $this->postJson($url, $body, $this->deviceHeaders($device, 'wrong-secret') + ['X-Edge-Setup-Token' => $token])->assertStatus(401);
        $this->postJson($url, $body, $this->deviceHeaders($device, $secret))->assertStatus(403)->assertJsonPath('code', EdgeSetupException::TOKEN_INVALID);
        $this->postJson($url, $body, $this->deviceHeaders($device, $secret) + ['X-Edge-Setup-Token' => 'not-a-token'])->assertStatus(403)->assertJsonPath('code', EdgeSetupException::TOKEN_INVALID);

        // A token of branch B's setup session presented by branch A's device.
        $deviceB = $this->makeDevice($this->branchB, 'secret-b');
        ['code' => $codeB] = $this->createCode($this->branchB);
        $tokenB = $this->redeem($codeB, $deviceB->installation_uuid)->json('setup_token');
        $this->postJson($url, $body, $this->deviceHeaders($device, $secret) + ['X-Edge-Setup-Token' => $tokenB])->assertStatus(403)->assertJsonPath('code', EdgeSetupException::TOKEN_SCOPE);

        // Same branch, but redeemed for ANOTHER installation.
        app(SetupSessionService::class)->cancel(Tenant::findOrFail($this->tenantId), $this->branchA, $this->users['owner']);
        $this->postJson($url, $body, $this->deviceHeaders($device, $secret) + ['X-Edge-Setup-Token' => $token])->assertStatus(403)->assertJsonPath('code', EdgeSetupException::TOKEN_INVALID);
        ['code' => $code2] = $this->createCode($this->branchA);
        $other = $this->redeem($code2, (string) Str::uuid())->json('setup_token');
        $this->postJson($url, $body, $this->deviceHeaders($device, $secret) + ['X-Edge-Setup-Token' => $other])->assertStatus(403)->assertJsonPath('code', EdgeSetupException::TOKEN_SCOPE);
    }

    public function test_an_expired_token_and_a_revoked_device_are_refused(): void
    {
        [$device, $secret, $token] = $this->deviceWithToken();
        $url = $this->central('/api/edge/setup/enrollment-assertions');
        $body = ['user_ids' => [$this->users['owner']]];
        Carbon::setTestNow(now()->addMinutes(SetupSessionService::TOKEN_TTL_MINUTES + 1));
        $this->postJson($url, $body, $this->deviceHeaders($device, $secret) + ['X-Edge-Setup-Token' => $token])->assertStatus(403)->assertJsonPath('code', EdgeSetupException::TOKEN_INVALID);
        Carbon::setTestNow();
        $device->forceFill(['status' => EdgeDevice::STATUS_REVOKED, 'active_slot' => null, 'revoked_at' => now()])->save();
        $this->postJson($url, $body, $this->deviceHeaders($device, $secret) + ['X-Edge-Setup-Token' => $token])->assertStatus(401);
    }

    public function test_bulk_assertions_fail_closed_before_the_bootstrap_allocated_an_activation(): void
    {
        $device = $this->makeDevice($this->branchA, 'secret-x', EdgeDevice::STATUS_READY, null, false);
        ['code' => $code] = $this->createCode($this->branchA);
        $token = $this->redeem($code, $device->installation_uuid)->json('setup_token');
        $this->postJson($this->central('/api/edge/setup/enrollment-assertions'), ['all_active' => true],
            $this->deviceHeaders($device, 'secret-x') + ['X-Edge-Setup-Token' => $token])
            ->assertStatus(409)->assertJsonPath('code', EdgeSetupException::ENROLLMENT_UNAVAILABLE)->assertJsonPath('reason', 'no_activation');
    }
}
