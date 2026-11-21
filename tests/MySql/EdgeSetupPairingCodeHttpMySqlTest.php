<?php

namespace Tests\MySql;

use App\Models\Master\EdgeSetupSession;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Services\Edge\Cloud\EdgeSetupException;
use App\Services\Edge\Cloud\SetupSessionService;
use App\Services\Edge\EdgePairingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeSetupCloudFixture;

/**
 * EDGE 0.9 stream C — K2b POST /api/edge/setup/pairing-code over the REAL central-domain HTTP stack: an INSTALL setup session
 * gets a fresh pairing code (minted by the EXISTING pairing service, same tenant/branch scope as the redeem) only when the
 * current one is in its last 3 minutes, expired or gone; the previous code of the session is cancelled; the fresh code pairs
 * through the unchanged /api/edge/pair. Refused: still-valid code (409 + its expiry), an already paired branch, a hand-made
 * code (never replaced — the same 409 as redeem), an update session, a bad / expired / cancelled token, the per-session cap and
 * the IP throttle.
 */
class EdgeSetupPairingCodeHttpMySqlTest extends MySqlTenantTestCase
{
    use EdgeSetupCloudFixture;

    public const TENANT_CODE = 'e09cpairing';

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

    /** Redeem a fresh setup code of branch A; returns the K2 JSON + the installation uuid used. */
    private function redeemed(?string $installation = null): array
    {
        $r = app(SetupSessionService::class)->create(Tenant::findOrFail($this->tenantId), Branch::on('tenant')->findOrFail($this->branchA), $this->users['owner']);
        $installation ??= (string) Str::uuid();
        $j = $this->postJson($this->central('/api/edge/setup/redeem'), ['setup_code' => $r['code'], 'installation_uuid' => $installation])->assertOk()->json();

        return [$j, $installation];
    }

    private function reissue(?string $token): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->central('/api/edge/setup/pairing-code'), [], $token === null ? [] : ['X-Edge-Setup-Token' => $token]);
    }

    private function codeRow(string $uuid): object
    {
        return DB::connection('master')->table('edge_pairing_codes')->where('public_uuid', $uuid)->first();
    }

    public function test_a_fresh_code_only_in_the_last_three_minutes_or_after_expiry_and_it_pairs(): void
    {
        [$j, $installation] = $this->redeemed();
        $session = EdgeSetupSession::where('public_uuid', $j['setup_session_id'])->firstOrFail();
        $firstUuid = $session->pairing_code_uuid;
        $this->assertSame(1, $session->pairing_codes_issued);

        // Still valid (15 min): refused with its expiry, nothing minted.
        $this->reissue($j['setup_token'])->assertStatus(409)->assertJsonPath('code', EdgeSetupException::PAIRING_CODE_STILL_VALID)
            ->assertJsonPath('pairing_code_expires_at', Carbon::parse($j['pairing_code_expires_at'])->toIso8601String());
        $this->assertSame(1, DB::connection('master')->table('edge_pairing_codes')->where('tenant_id', $this->tenantId)->count());

        // In its last 3 minutes: a fresh code; the previous one is cancelled (not just left to expire).
        Carbon::setTestNow(now()->addMinutes(EdgePairingService::CODE_TTL_MINUTES - 2));
        $res = $this->reissue($j['setup_token'])->assertOk();
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $fresh = $res->json();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $fresh['pairing_code']);
        $this->assertSame($this->branchA, $fresh['branch_id']);
        $this->assertSame($j['setup_session_id'], $fresh['setup_session_id']);
        $session->refresh();
        $this->assertNotSame($firstUuid, $session->pairing_code_uuid);
        $this->assertSame(2, $session->pairing_codes_issued);
        $this->assertNull($this->codeRow($firstUuid)->active_slot);
        $this->assertNotNull($this->codeRow($firstUuid)->cancelled_at);
        $this->assertSame(1, (int) $this->codeRow($session->pairing_code_uuid)->active_slot);
        $this->assertStringNotContainsString($fresh['pairing_code'], json_encode(DB::connection('master')->table('edge_pairing_codes')->where('tenant_id', $this->tenantId)->get()));

        // After that one expired: another fresh code.
        Carbon::setTestNow(now()->addMinutes(EdgePairingService::CODE_TTL_MINUTES + 1));
        $third = $this->reissue($j['setup_token'])->assertOk()->json();
        $this->assertSame(3, $session->fresh()->pairing_codes_issued);

        // The fresh code pairs through the UNCHANGED /api/edge/pair; afterwards the branch is paired → no more codes.
        $this->postJson($this->central('/api/edge/pair'), ['pairing_code' => $third['pairing_code'], 'installation_uuid' => $installation,
            'device_secret_hash' => hash('sha256', 'wizard-secret')])->assertOk();
        Carbon::setTestNow(now()->addMinutes(EdgePairingService::CODE_TTL_MINUTES + 1));
        $this->reissue($j['setup_token'])->assertStatus(409)->assertJsonPath('code', EdgeSetupException::DEVICE_ALREADY_PAIRED);
    }

    public function test_a_hand_made_pairing_code_is_never_replaced(): void
    {
        [$j] = $this->redeemed();
        $tenant = Tenant::findOrFail($this->tenantId);
        $branch = Branch::on('tenant')->findOrFail($this->branchA);
        app(EdgePairingService::class)->cancelCode($tenant, $branch, $this->users['owner']);             // the wizard's code is gone …
        $manual = app(EdgePairingService::class)->generateCode($tenant, $branch->fresh(), $this->users['owner']);   // … the owner made one

        $this->reissue($j['setup_token'])->assertStatus(409)->assertJsonPath('code', EdgeSetupException::PAIRING_CODE_ACTIVE);
        $this->assertSame(1, (int) $this->codeRow($manual['public_uuid'])->active_slot, 'the owner\'s code is untouched');
    }

    public function test_update_sessions_bad_tokens_the_cap_and_the_throttle_are_refused(): void
    {
        [$j] = $this->redeemed();   // branch A, install session (before any device exists: the plan allows one device)

        // Update session (the branch already has a device): no pairing codes at all.
        $deviceB = $this->makeDevice($this->branchB, 'secret-b');
        $rb = app(SetupSessionService::class)->create(Tenant::findOrFail($this->tenantId), Branch::on('tenant')->findOrFail($this->branchB), $this->users['owner']);
        $tokenB = $this->postJson($this->central('/api/edge/setup/redeem'), ['setup_code' => $rb['code'], 'installation_uuid' => $deviceB->installation_uuid])
            ->assertOk()->assertJsonPath('mode', 'update')->json('setup_token');
        $this->reissue($tokenB)->assertStatus(409)->assertJsonPath('code', EdgeSetupException::NOT_INSTALL_SESSION);
        $deviceB->delete();   // free the licensed slot again for the rest of the test

        $this->reissue(null)->assertStatus(403)->assertJsonPath('code', EdgeSetupException::TOKEN_INVALID);
        $this->reissue('not-a-token')->assertStatus(403)->assertJsonPath('code', EdgeSetupException::TOKEN_INVALID);

        // Per-session cap.
        Carbon::setTestNow(now()->addMinutes(EdgePairingService::CODE_TTL_MINUTES + 1));
        EdgeSetupSession::where('public_uuid', $j['setup_session_id'])->update(['pairing_codes_issued' => SetupSessionService::MAX_PAIRING_CODES]);
        $this->reissue($j['setup_token'])->assertStatus(409)->assertJsonPath('code', EdgeSetupException::PAIRING_CODES_EXHAUSTED);
        EdgeSetupSession::where('public_uuid', $j['setup_session_id'])->update(['pairing_codes_issued' => 1]);

        // Cancelled by the owner (security control) → the token is dead.
        app(SetupSessionService::class)->cancel(Tenant::findOrFail($this->tenantId), $this->branchA, $this->users['owner']);
        $this->reissue($j['setup_token'])->assertStatus(403)->assertJsonPath('code', EdgeSetupException::TOKEN_INVALID);

        // Expired token (60 min).
        Carbon::setTestNow();
        [$j2] = $this->redeemed();
        Carbon::setTestNow(now()->addMinutes(SetupSessionService::TOKEN_TTL_MINUTES + 1));
        $this->reissue($j2['setup_token'])->assertStatus(403)->assertJsonPath('code', EdgeSetupException::TOKEN_INVALID);
    }

    public function test_the_route_is_ip_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->reissue('guess-' . $i)->assertStatus(403);
        }
        $this->reissue('guess-11')->assertStatus(429);
    }
}
