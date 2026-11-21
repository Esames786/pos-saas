<?php

namespace App\Services\Edge\Cloud;

use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
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
 * The counter setup (stream D's renderer, K7) is built ONLY for the user's OWN branch (users.default_branch_id) and only
 * when that branch has an active paired Branch Server that reported its LAN profile. The download carries a COUNTER BUNDLE:
 * a payload signed with the Cloud enrollment key (purpose edge-counter-bundle — never accepted as an enrollment assertion)
 * so the counter wizard can verify it came from the Cloud: tenant, branch, Branch Server LAN profile + CA fingerprint, the
 * Online POS URL, the user's default terminal as a binding hint and — only on the explicit POST "my enrollment" — ONE
 * enrollment assertion for the requesting user THEMSELVES (EdgeEnrollmentIssuer, issuer = the same user, 15 minutes), so the
 * counter wizard can let them set their own Edge PIN. No user can obtain an assertion for anybody else here.
 *
 * Bundle embedding (contract for stream D): appended to the .ps1 as a PowerShell comment block, base64 so no `#>` can end
 * the comment early:
 *     <# BINGOO-COUNTER-BUNDLE v1
 *     <base64 of the JSON {"payload":{…},"signature":"…"}>
 *     BINGOO-COUNTER-BUNDLE-END #>
 * Verify with the enrollment PUBLIC key (EdgeEnrollmentCrypto::verifySignature — canonical JSON, Ed25519).
 */
class CounterBundleService
{
    public const PURPOSE = 'edge-counter-bundle';
    public const VERSION = 'edge-counter-bundle-v1';
    public const TTL_SECONDS = 900;
    public const BLOCK_BEGIN = '<# BINGOO-COUNTER-BUNDLE v1';
    public const BLOCK_END = 'BINGOO-COUNTER-BUNDLE-END #>';

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
     * The signed counter bundle. $withSelfEnrollment issues ONE assertion for $user themselves.
     *
     * @return array{payload: array<string,mixed>, signature: string}
     */
    public function bundle(Tenant $tenant, User $user, Branch $branch, EdgeDevice $device, string $onlinePosUrl, bool $withSelfEnrollment): array
    {
        $key = (string) config('edge.enrollment.signing_key');
        if ($key === '' || ! EdgeEnrollmentCrypto::available()) {
            throw EdgeSetupException::of(EdgeSetupException::NOT_CONFIGURED);
        }

        $selfEnrollment = null;
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
            $selfEnrollment = ['assertion' => $assertion, 'expires_at' => date(DATE_ATOM, (int) $p['expires_at'])];
        }

        $now = time();
        $payload = [
            'purpose'      => self::PURPOSE,
            'version'      => self::VERSION,
            'issued_at'    => $now,
            'expires_at'   => $now + self::TTL_SECONDS,
            'cloud_url'    => $this->profiles->cloudUrl(),
            'online_pos_url' => $onlinePosUrl,
            'tenant_id'    => (int) $tenant->id,
            'tenant_code'  => (string) $tenant->tenant_code,
            'branch_id'    => (int) $branch->id,
            'branch_name'  => (string) $branch->name,
            'branch_server' => [
                'device_public_uuid' => (string) $device->public_uuid,
                'lan_hostname'       => (string) $device->lan_hostname,
                'lan_ip'             => (string) $device->lan_ip,
                'https_port'         => (int) $device->lan_https_port,
                'ca_sha256'          => (string) $device->lan_ca_sha256,
                'ca_certificate_pem' => (string) $device->lan_ca_pem,
            ],
            'user' => ['id' => (int) $user->id, 'employee_code' => $user->employee_code, 'name' => (string) $user->name],
            'terminal_hint' => $this->terminalHint($user, (int) $branch->id),
            'self_enrollment' => $selfEnrollment,
        ];

        Log::info('[edge-setup-audit] edge.counter.bundle_issued', [
            'tenant_id' => (int) $tenant->id, 'branch_id' => (int) $branch->id, 'user_id' => (int) $user->id,
            'device_uuid' => $device->public_uuid, 'self_enrollment' => $selfEnrollment !== null,
        ]);

        return EdgeEnrollmentCrypto::sign($payload, $key);
    }

    /** The user's default terminal when it belongs to this branch (a binding HINT — the counter wizard confirms it). */
    private function terminalHint(User $user, int $branchId): ?array
    {
        $tid = (int) ($user->getAttribute('default_terminal_id') ?? 0);
        if ($tid < 1) {
            return null;
        }
        $t = DB::connection('tenant')->table('terminals')->where('id', $tid)->where('branch_id', $branchId)->first(['id', 'code', 'name']);

        return $t ? ['terminal_id' => (int) $t->id, 'code' => (string) $t->code, 'name' => (string) $t->name] : null;
    }

    /** The comment block appended to the counter setup script (CRLF, ASCII, base64 body). */
    public static function scriptBlock(array $signedBundle): string
    {
        $json = json_encode($signedBundle, JSON_UNESCAPED_SLASHES);

        return "\r\n" . self::BLOCK_BEGIN . "\r\n" . chunk_split(base64_encode((string) $json), 76, "\r\n") . self::BLOCK_END . "\r\n";
    }

    /** Parse a script's bundle block back (tests / tooling). */
    public static function extract(string $script): ?array
    {
        if (! preg_match('/' . preg_quote(self::BLOCK_BEGIN, '/') . '\r?\n(.*?)' . preg_quote(self::BLOCK_END, '/') . '/s', $script, $m)) {
            return null;
        }
        $json = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}
