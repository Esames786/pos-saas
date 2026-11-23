<?php

namespace App\Services\Edge\Cloud;

use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeCanonicalJson;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgeEnrollmentIssuer;
use App\Services\Edge\EdgePairingService;
use App\Support\EdgeUserAuthz;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * EDGE 0.9 (owner, 21 Nov 2026) — self-service "Install Offline Counter" for a logged-in POS user.
 *
 * The counter wizard (stream D: EdgeCounterSetupRenderer::renderWindowsWizard) is built ONLY for the user's OWN branch
 * (users.default_branch_id) and only when that branch has an active paired Branch Server that reported its LAN profile.
 * The wizard context this service builds:
 *   tenant_label    the business name
 *   terminal        {id, label} — the user's default terminal when it belongs to the branch (a binding HINT)
 *   signed_profile  {profile, signature, public_key}: the LAN profile signed with the Cloud ENROLLMENT key (Ed25519 over
 *                   EdgeCanonicalJson::encode(profile)), so the wizard can verify the profile came from the Cloud. The profile
 *                   carries purpose edge-counter-profile — it can never be mistaken for an enrollment assertion.
 *   enrollment      ONLY on the explicit POST "my enrollment": {assertion, user_label} — ONE EdgeEnrollmentIssuer assertion for
 *                   the requesting user THEMSELVES (issuer = the same user, 15 minutes, audited). Nobody can obtain an
 *                   assertion for anybody else here.
 */
class CounterBundleService
{
    public const PROFILE_PURPOSE = 'edge-counter-profile';
    public const PROFILE_VERSION = 'edge-counter-profile-v1';
    public const TTL_SECONDS = 900;

    public function __construct(
        private readonly EdgePairingService $pairing,
        private readonly EdgeEnrollmentIssuer $issuer,
        private readonly DeviceProfileService $profiles,
    ) {}

    /**
     * The user's own branch + its Branch Server, or a clear refusal.
     *
     * @return array{branch: Branch, device: EdgeDevice}
     */
    public function contextFor(Tenant $tenant, User $user): array
    {
        $branchId = (int) ($user->default_branch_id ?? 0);
        $branch = $branchId > 0 ? Branch::find($branchId) : null;
        if (! $branch || $branch->status !== 'active') {
            throw EdgeSetupException::of(EdgeSetupException::BRANCH_NOT_ELIGIBLE, 'Your user has no active home branch, so there is no Branch Server to connect this counter to. Ask the owner to set your branch.', ['reason' => 'no_branch']);
        }
        $device = $this->pairing->activeDeviceForBranch((int) $tenant->id, (int) $branch->id);
        if (! $device) {
            throw EdgeSetupException::of(EdgeSetupException::DEVICE_NOT_CURRENT, 'No Branch Server is set up for ' . $branch->name . ' yet. Ask the owner to set one up on the Offline Edge page.', ['reason' => 'no_device']);
        }
        if (! $device->hasLanProfile()) {
            throw EdgeSetupException::of(EdgeSetupException::DEVICE_NOT_CURRENT, 'The Branch Server of ' . $branch->name . ' has not reported its shop-network address yet. Try again after it finished its setup.', ['reason' => 'no_lan_profile']);
        }

        return ['branch' => $branch, 'device' => $device];
    }

    /**
     * The renderWindowsWizard() $context. $withSelfEnrollment issues ONE assertion for $user themselves.
     *
     * @return array{tenant_label: string, terminal: ?array, signed_profile: array, enrollment: ?array}
     */
    public function wizardContext(Tenant $tenant, User $user, Branch $branch, EdgeDevice $device, string $onlinePosUrl, bool $withSelfEnrollment): array
    {
        $secret = (string) config('edge.enrollment.signing_key');
        $publicKey = $this->profiles->enrollmentPublicKey();
        $sk = base64_decode($secret, true);
        if ($publicKey === null || ! EdgeEnrollmentCrypto::available() || $sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw EdgeSetupException::of(EdgeSetupException::NOT_CONFIGURED);
        }

        $enrollment = null;
        if ($withSelfEnrollment) {
            if (! EdgeUserAuthz::isEdgeLoginEligible($user)) {
                throw EdgeSetupException::of(EdgeSetupException::ENROLLMENT_UNAVAILABLE, 'Your user has no employee code, so it cannot log in on the Branch Server. Ask the owner to give you one.', ['reason' => 'no_employee_code']);
            }
            try {
                $assertion = $this->issuer->issue($tenant, (int) $branch->id, $device, $user, (int) $user->id);
            } catch (RuntimeException $e) {
                throw EdgeSetupException::of(EdgeSetupException::ENROLLMENT_UNAVAILABLE, 'Your Edge PIN cannot be prepared right now: ' . $e->getMessage(), ['reason' => 'refused']);
            }
            $p = $assertion['payload'];
            Log::info('[edge-enrollment-issued]', [
                'via' => 'self_service_counter', 'issuer_user_id' => (int) $user->id, 'target_user_id' => (int) $user->id,
                'tenant_id' => (int) $tenant->id, 'branch_id' => (int) $branch->id, 'device' => $device->public_uuid,
                'activation_epoch' => $p['activation_epoch'] ?? null, 'jti' => $p['jti'] ?? null, 'expires_at' => $p['expires_at'] ?? null,
            ]);
            $enrollment = ['assertion' => $assertion, 'user_label' => trim((string) $user->name) . ' (' . trim((string) $user->employee_code) . ')'];
        }

        $now = time();
        $profile = [
            'purpose'            => self::PROFILE_PURPOSE,
            'version'            => self::PROFILE_VERSION,
            'issued_at'          => $now,
            'expires_at'         => $now + self::TTL_SECONDS,
            'tenant_id'          => (int) $tenant->id,
            'tenant_code'        => (string) $tenant->tenant_code,
            'branch_id'          => (int) $branch->id,
            'branch_name'        => (string) $branch->name,
            'device_public_uuid' => (string) $device->public_uuid,
            'lan_hostname'       => (string) $device->lan_hostname,
            'lan_ip'             => (string) $device->lan_ip,
            'https_port'         => (int) $device->lan_https_port,
            'ca_sha256'          => (string) $device->lan_ca_sha256,
            'online_pos_url'     => $onlinePosUrl,
            'user_id'            => (int) $user->id,
        ];
        $signature = base64_encode(sodium_crypto_sign_detached(EdgeCanonicalJson::encode($profile), $sk));

        Log::info('[edge-setup-audit] edge.counter.wizard_issued', [
            'tenant_id' => (int) $tenant->id, 'branch_id' => (int) $branch->id, 'user_id' => (int) $user->id,
            'device_uuid' => $device->public_uuid, 'self_enrollment' => $enrollment !== null,
        ]);

        return [
            'tenant_label'   => (string) $tenant->business_name,
            'terminal'       => $this->terminalHint($user, (int) $branch->id),
            'signed_profile' => ['profile' => $profile, 'signature' => $signature, 'public_key' => $publicKey],
            'enrollment'     => $enrollment,
        ];
    }

    /** The user's default terminal when it belongs to this branch: {id, label} (a binding HINT — the wizard confirms it). */
    private function terminalHint(User $user, int $branchId): ?array
    {
        $tid = (int) ($user->getAttribute('default_terminal_id') ?? 0);
        if ($tid < 1) {
            return null;
        }
        $t = DB::connection('tenant')->table('terminals')->where('id', $tid)->where('branch_id', $branchId)->first(['id', 'code', 'name']);

        return $t ? ['id' => (int) $t->id, 'label' => trim((string) $t->name . ' (' . $t->code . ')')] : null;
    }
}
