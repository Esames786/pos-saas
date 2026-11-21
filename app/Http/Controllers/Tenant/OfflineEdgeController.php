<?php

namespace App\Http\Controllers\Tenant;

use App\Contracts\Edge\CounterSetupRenderer;
use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Models\Master\EdgeSetupSession;
use App\Models\Tenant\Branch;
use App\Services\Edge\Cloud\DeviceProfileService;
use App\Services\Edge\Cloud\EdgeSetupException;
use App\Services\Edge\Cloud\QrSvg;
use App\Services\Edge\Cloud\ReleaseRegistry;
use App\Services\Edge\Cloud\SetupSessionService;
use App\Services\Edge\EdgePairingService;
use App\Services\Edge\OfflineEdgeEntitlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * OFFLINE-EDGE-ENTITLEMENT-1 + BRANCH-DEVICE-PAIRING-1 (+HARDEN-1) — the entitled Settings
 * page (generate code / download) AND a security-management path (cancel code / revoke
 * device) that stays reachable even after entitlement is removed or the rollout flag is off.
 *
 * EDGE 0.9 (stream C) adds: "Download Branch Server Setup" (the latest SIGNED, registered release zip — gate 3),
 * "Set up a Branch Server" (a one-time setup code for the setup wizard, K2), per-device install/update status, version,
 * LAN profile, "Update now" + maintenance window (K4/K5/K6), and the per-branch counter setup script, phone page and CA
 * download (K7).
 *
 * Gating summary:
 *   index / generate / download / setup-code / update-now / maintenance-window / counter-setup / phone / ca
 *                               → entitlement + rollout (assertSetupAccessAllowed) + route permission
 *   setup-code                  → additionally tenant.offline-edge.security (in-controller)
 *   security / cancel / revoke / setup-code cancel → permission + ownership ONLY (security controls)
 *   ca-public                   → a 30-minute signed URL issued by the phone page (public CA material only)
 */
class OfflineEdgeController extends Controller
{
    public const SECURITY_PERMISSION = 'tenant.offline-edge.security';
    public const CA_LINK_TTL_MINUTES = 30;

    public function __construct(
        private readonly OfflineEdgeEntitlementService $entitlement,
        private readonly EdgePairingService $pairing,
        private readonly SetupSessionService $setup,
        private readonly DeviceProfileService $profiles,
        private readonly ReleaseRegistry $releases,
    ) {}

    public function index(Request $request)
    {
        $this->entitlement->assertSetupAccessAllowed();

        // The setup page never carries a pairing code — the code is rendered ONCE, directly
        // in the generate POST response (see generatePairingCode). This avoids the session
        // flash entirely, which under concurrent same-session generate requests could be
        // clobbered by the losing request's session write (BOOTSTRAP preflight finding).
        return $this->renderSetupPage(securityOnly: false);
    }

    /**
     * Security-management view — permission-gated only (NO entitlement / rollout gate), so an
     * Owner can always revoke a device / cancel a code after entitlement is removed, the
     * subscription lapses, or EDGE_FEATURE_ENABLED is turned off. Generation/download controls
     * are never rendered here.
     */
    public function security(Request $request)
    {
        return $this->renderSetupPage(securityOnly: true);
    }

    /**
     * "Download Branch Server Setup" — the latest published STABLE release (signature + manifest verified at publish
     * time; size + SHA-256 re-verified here before a byte is sent). Falls back to the legacy configured installer path.
     */
    public function download(Request $request)
    {
        $this->entitlement->assertSetupAccessAllowed();

        if (! $this->entitlement->installerIsAvailable()) {
            throw \App\Exceptions\OfflineEdgeAccessException::installerNotAvailable();
        }

        $release = $this->releases->latestServable();
        if ($release) {
            if (! $this->releases->integrityOk($release)) {
                abort(409, 'The Branch Server package on the Cloud failed its integrity check. Contact support.');
            }

            return response()->download((string) $this->releases->absolutePath($release), ReleaseRegistry::downloadName($release), [
                'Content-Type'          => 'application/zip',
                'X-Edge-Release-Sha256' => $release->zip_sha256,
            ]);
        }

        $path    = config('app.edge_installer_path');
        $version = $this->entitlement->installerVersion();
        $name    = 'BingooEdgeSetup' . ($version ? '-' . $version : '') . '.exe';

        return response()->download($path, $name);
    }

    /** Generate a fresh six-digit code for a branch (shown ONCE). Entitlement + rollout required. */
    public function generatePairingCode(Request $request, Branch $branch)
    {
        $this->entitlement->assertSetupAccessAllowed();
        $this->assertEligibleBranch($branch);

        $result = $this->pairing->generateCode(app('tenant'), $branch, (int) auth('tenant')->id());

        // Render the six-digit code ONCE, directly in this POST response — never via session
        // flash (race-safe under concurrent generation) and never in a URL. no-store headers
        // stop a Back/bfcache/proxy from re-surfacing it; a refresh re-POSTs → 409 ALREADY_ACTIVE
        // (the code is not retrievable again).
        return $this->renderSetupPage(
            securityOnly: false,
            newCode: $result['code'],
            newCodeBranch: $branch->id,
            newCodeExpires: $result['expires_at'],
        );
    }

    /**
     * EDGE 0.9 (K2) — "Set up a Branch Server": a one-time 10-character setup code for the setup wizard (60 min), shown
     * ONCE in this POST response (no-store; never flashed, never in a URL). Requires tenant.offline-edge.security.
     */
    public function generateSetupCode(Request $request, Branch $branch)
    {
        $this->entitlement->assertSetupAccessAllowed();
        abort_unless((bool) auth('tenant')->user()?->can(self::SECURITY_PERMISSION), 403, 'Permission denied.');
        abort_unless($branch->status === 'active', 404);

        try {
            $r = $this->setup->create(app('tenant'), $branch, (int) auth('tenant')->id());
        } catch (EdgeSetupException $e) {
            return redirect(url('/settings/offline-edge'))->withErrors(['setup' => $e->getMessage()]);
        }

        return $this->renderSetupPage(securityOnly: false, setupCode: [
            'display'    => $r['display'],
            'branch_id'  => (int) $branch->id,
            'branch'     => $branch->name,
            'mode'       => $r['mode'],
            'expires_at' => $r['expires_at'],
            'cloud_url'  => $this->profiles->cloudUrl(),
        ]);
    }

    /** Cancel a branch's live setup code and any unexpired setup token. SECURITY control. */
    public function cancelSetupCode(Request $request, Branch $branch)
    {
        $this->setup->cancel(app('tenant'), (int) $branch->id, (int) auth('tenant')->id());

        return redirect($this->backTo())->with('status', 'Setup code cancelled.');
    }

    /** Cancel a live code. SECURITY control — no entitlement/flag dependency. */
    public function cancelPairingCode(Request $request, Branch $branch)
    {
        $this->pairing->cancelCode(app('tenant'), $branch, (int) auth('tenant')->id());

        return redirect($this->backTo())->with('status', 'Pairing code cancelled.');
    }

    /** Revoke a paired device. SECURITY control — no entitlement/flag dependency. */
    public function revokeDevice(Request $request, EdgeDevice $edgeDevice)
    {
        abort_unless((int) $edgeDevice->tenant_id === (int) app('tenant')->id, 404);

        $this->pairing->revokeDevice(
            app('tenant'),
            $edgeDevice,
            (int) auth('tenant')->id(),
            $request->string('reason')->limit(190, '')->value() ?: null,
        );

        return redirect($this->backTo())->with('status', 'Edge device revoked.');
    }

    /** EDGE 0.9 (K6) — "Update now": the appliance applies the latest signed release on its next check (standby only). */
    public function updateNow(Request $request, EdgeDevice $edgeDevice)
    {
        $this->entitlement->assertSetupAccessAllowed();
        $device = $this->ownedActiveDevice($edgeDevice);
        $this->profiles->requestUpdateNow($device, (int) auth('tenant')->id());

        return redirect(url('/settings/offline-edge'))->with('status', 'Update requested. The Branch Server installs it on its next check while it is on standby.');
    }

    /** EDGE 0.9 (K6) — the device's auto-update maintenance window (branch business timezone); empty = 03:00–05:00. */
    public function maintenanceWindow(Request $request, EdgeDevice $edgeDevice)
    {
        $this->entitlement->assertSetupAccessAllowed();
        $device = $this->ownedActiveDevice($edgeDevice);
        $data = $request->validate([
            'start' => ['nullable', 'date_format:H:i', 'required_with:end'],
            'end'   => ['nullable', 'date_format:H:i', 'required_with:start', 'different:start'],
        ]);
        $this->profiles->setMaintenanceWindow($device, $data['start'] ?? null, $data['end'] ?? null, (int) auth('tenant')->id());

        return redirect(url('/settings/offline-edge'))->with('status', 'Maintenance window saved.');
    }

    /** EDGE 0.9 (K7) — the per-branch Windows counter setup script (CA trust, hosts line, shortcuts, TLS check). */
    public function counterSetup(Request $request, Branch $branch)
    {
        $this->entitlement->assertSetupAccessAllowed();
        $device = $this->deviceWithLanProfile($branch);
        $renderer = $this->renderer();
        if (! $renderer) {
            return redirect(url('/settings/offline-edge'))->withErrors(['counter' => 'Counter setup is not available on this Cloud yet.']);
        }
        $script = $renderer->renderWindows($this->profiles->lanProfile($device), url('/pos'), (string) $branch->name);
        $name = 'Setup-' . (Str::slug((string) $branch->name) ?: 'Branch-' . $branch->id) . '-Counter.ps1';

        return response($script, 200, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control'       => 'no-store, private, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** EDGE 0.9 (K7) — phone onboarding: QR codes for the CA download (signed Cloud link) and the IP login URL. */
    public function phone(Request $request, Branch $branch)
    {
        $this->entitlement->assertSetupAccessAllowed();
        $device = $this->deviceWithLanProfile($branch);
        $profile = $this->profiles->lanProfile($device);
        $profile['branch_name'] = (string) $branch->name;
        $profile['ca_download_url'] = URL::temporarySignedRoute('offline-edge.ca.public', now()->addMinutes(self::CA_LINK_TTL_MINUTES), [
            // {subdomain} is bound to null for dispatch (AppServiceProvider) — derive it from the tenant host instead.
            'subdomain' => Str::beforeLast($request->getHost(), '.' . config('tenancy.tenant_base_domain')),
            'branch'    => (int) $branch->id,
            'fp'        => substr((string) $device->lan_ca_sha256, 0, 16),
        ]);

        $renderer = $this->renderer();
        $onboarding = $renderer ? $renderer->phoneOnboarding($profile) : [];
        $host = str_contains((string) $profile['lan_ip'], ':') ? '[' . $profile['lan_ip'] . ']' : (string) $profile['lan_ip'];
        $onboarding += [
            'login_url'       => 'https://' . $host . ':' . (int) $profile['https_port'] . '/edge/local/login',
            'ca_download_url' => $profile['ca_download_url'],
            'ca_sha256'       => (string) $profile['ca_sha256'],
            'steps'           => self::defaultPhoneSteps(),
        ];

        $response = response()->view('tenant.offline-edge.phone', [
            'branch'      => $branch,
            'profile'     => $profile,
            'onboarding'  => $onboarding,
            'loginQr'     => QrSvg::encode((string) $onboarding['login_url'])->svg(220, 'Branch Server login (IP address)'),
            'caQr'        => ! empty($onboarding['ca_download_url']) ? QrSvg::encode((string) $onboarding['ca_download_url'])->svg(220, 'Branch CA certificate download') : null,
            'caTtl'       => self::CA_LINK_TTL_MINUTES,
            'hasPartial'  => view()->exists('tenant.offline-edge.partials.phone-onboarding'),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');

        return $response;
    }

    /** EDGE 0.9 (K7) — the branch CA certificate (PEM) for manual installation on a counter PC. */
    public function caCertificate(Request $request, Branch $branch)
    {
        $this->entitlement->assertSetupAccessAllowed();

        return $this->caResponse($this->deviceWithLanProfile($branch), $branch);
    }

    /** EDGE 0.9 (K7) — the same CA behind the phone page's signed link (no login; fingerprint must still be current). */
    public function caCertificatePublic(Request $request, $branch)
    {
        $branchModel = Branch::find((int) $branch);
        abort_unless($branchModel && $branchModel->status === 'active', 404);
        $device = $this->pairing->activeDeviceForBranch((int) app('tenant')->id, (int) $branchModel->id);
        abort_unless($device && $device->hasLanProfile(), 404);
        abort_unless(hash_equals(substr((string) $device->lan_ca_sha256, 0, 16), (string) $request->query('fp', '')), 410, 'This link is out of date. Open the phone page again.');

        return $this->caResponse($device, $branchModel);
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    private function caResponse(EdgeDevice $device, Branch $branch)
    {
        $name = 'Bingoo-' . (Str::slug((string) $branch->name) ?: 'Branch-' . $branch->id) . '-CA.crt';

        return response((string) $device->lan_ca_pem, 200, [
            'Content-Type'        => 'application/x-x509-ca-cert',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control'       => 'no-store, private, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Edge-CA-Sha256'    => (string) $device->lan_ca_sha256,
        ]);
    }

    /** The K7 renderer: a container binding when present, else stream D's class when it exists, else null. */
    private function renderer(): ?CounterSetupRenderer
    {
        if (app()->bound(CounterSetupRenderer::class)) {
            return app(CounterSetupRenderer::class);
        }
        $class = 'App\\Services\\Edge\\EdgeCounterSetupRenderer';
        if (class_exists($class) && is_subclass_of($class, CounterSetupRenderer::class)) {
            return app($class);
        }

        return null;
    }

    private function ownedActiveDevice(EdgeDevice $device): EdgeDevice
    {
        abort_unless((int) $device->tenant_id === (int) app('tenant')->id, 404);
        abort_if($device->isRevoked() || (int) $device->active_slot !== EdgeDevice::ACTIVE_SLOT, 404);

        return $device;
    }

    private function deviceWithLanProfile(Branch $branch): EdgeDevice
    {
        abort_unless($branch->status === 'active', 404);
        $device = $this->pairing->activeDeviceForBranch((int) app('tenant')->id, (int) $branch->id);
        abort_unless($device !== null, 404, 'No Branch Server is paired to this branch.');
        abort_unless($device->hasLanProfile(), 409, 'The Branch Server has not reported its LAN address and certificate yet.');

        return $device;
    }

    /** @return array<string, list<string>> */
    public static function defaultPhoneSteps(): array
    {
        return [
            'android' => [
                'Scan the first QR code and download the certificate.',
                'Settings → Security → Encryption & credentials → Install a certificate → CA certificate → choose the downloaded file.',
                'Scan the second QR code to open the Branch Server login page.',
            ],
            'ios' => [
                'Scan the first QR code in Safari and allow the profile download.',
                'Settings → General → VPN & Device Management → install the downloaded profile.',
                'Settings → General → About → Certificate Trust Settings → turn on full trust for the Bingoo branch CA.',
                'Scan the second QR code to open the Branch Server login page.',
            ],
        ];
    }

    /** Per-branch device + live-code state (master joined to tenant branches in memory). */
    private function branchState(): array
    {
        $tenant   = app('tenant');
        $branches = Branch::where('status', 'active')->orderBy('name')->get();
        $devices  = EdgeDevice::active()->where('tenant_id', $tenant->id)->get()->keyBy('branch_id');
        $latest   = $this->releases->latestServable();

        $rows = $branches->map(function (Branch $b) use ($tenant, $devices, $latest) {
            $liveCode = $this->pairing->liveCodeForBranch($tenant->id, $b->id);
            $device = $devices->get($b->id);
            $setup = $this->setup->liveSessionForBranch((int) $tenant->id, (int) $b->id);

            return [
                'id'            => $b->id,
                'name'          => $b->name,
                'lifecycle'     => $b->local_edge_status,
                'device'        => $device,
                // W-G1 — the build the appliance reported on its heartbeat (read-only facts; null = nothing reported yet).
                'build'         => $device ? $this->buildFacts($device) : null,
                'has_live_code' => (bool) $liveCode,
                'code_expires'  => $liveCode?->expires_at,
                // EDGE 0.9 — setup code, install/update status, LAN profile, update offer, maintenance window.
                'setup_live'    => $setup !== null,
                'setup_expires' => $setup?->code_expires_at,
                'status'        => $device ? $this->deviceStatus($device, $latest) : null,
                'window'        => $device ? $this->profiles->maintenanceWindow($device, $b) : null,
                'lan_ready'     => $device ? $device->hasLanProfile() : false,
            ];
        });

        return [
            'branchRows'    => $rows,
            'deviceLimit'   => $this->pairing->deviceLimit($tenant),
            'activeDevices' => $this->pairing->activeDeviceCount($tenant->id),
            'latestRelease' => $latest,
        ];
    }

    /** @return array<string, mixed> */
    private function deviceStatus(EdgeDevice $device, $latest): array
    {
        $current = $this->releases->deviceVersion($device);

        return [
            'phase'            => $device->install_phase,
            'ok'               => $device->install_ok,
            'message'          => $device->install_message,
            'reported_at'      => $device->install_reported_at,
            'version'          => $current,
            'last_seen'        => $device->last_authenticated_at,
            'update_available' => $latest && ($current === null || version_compare($latest->version, $current, '>')) ? $latest->version : null,
            'update_requested' => $device->update_now_requested_at,
            'lan_hostname'     => $device->lan_hostname,
            'lan_ip'           => $device->lan_ip,
            'lan_port'         => $device->lan_https_port,
            'lan_ca_sha256'    => $device->lan_ca_sha256,
            'lan_reported_at'  => $device->lan_reported_at,
        ];
    }

    /**
     * W-F VERSION REPORTING (W-G1 display) — what the appliance last reported about itself, as recorded by
     * EdgeAuthorityLeaseService::recordBuildReport on the master device row: `app_version` / `schema_version` (the widened
     * pairing-era columns), the canonical build block in `compatibility_manifest` (edge_app_version, git_commit,
     * bootstrap_schema, applied_edge_schema_version, capabilities …; the compatibility-report endpoint writes the
     * `*_version` aliases) and `build_reported_at` / `compatibility_reported_at`. Nothing here is invented: a fact the
     * appliance did not report renders as a dash, and a device that never reported renders "not reported yet".
     *
     * @return array{app_version:?string, git_commit:?string, bootstrap_schema:?string, applied_edge_schema:?string, capabilities:list<string>, reported_at:?\Illuminate\Support\Carbon}|null
     */
    private function buildFacts(EdgeDevice $device): ?array
    {
        $manifest = is_array($device->compatibility_manifest) ? $device->compatibility_manifest : [];
        $str = function (string ...$keys) use ($manifest): ?string {
            foreach ($keys as $k) {
                $v = $manifest[$k] ?? null;
                if (is_scalar($v) && trim((string) $v) !== '') {
                    return trim((string) $v);
                }
            }

            return null;
        };
        $capabilities = array_values(array_filter(array_map(
            fn ($c) => is_scalar($c) ? trim((string) $c) : '',
            is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : []
        ), fn ($c) => $c !== ''));
        $reportedAt = $device->build_reported_at ?? $device->compatibility_reported_at;

        $facts = [
            'app_version'         => $device->app_version ?: $str('edge_app_version', 'artifact_version'),
            'git_commit'          => $str('git_commit'),
            'bootstrap_schema'    => $str('bootstrap_schema', 'bootstrap_schema_version') ?: ($device->schema_version ?: null),
            'applied_edge_schema' => $str('applied_edge_schema_version', 'edge_schema_version'),
            'capabilities'        => $capabilities,
            'reported_at'         => $reportedAt,
        ];

        $reported = $reportedAt !== null || $facts['app_version'] || $facts['git_commit'] || $facts['bootstrap_schema']
            || $facts['applied_edge_schema'] || $capabilities !== [];

        return $reported ? $facts : null;
    }

    /**
     * Render the setup/security page. When $newCode / $setupCode is supplied (only on the generate POST responses) the
     * plaintext code is shown ONCE and the response is marked no-store so no cache/back/bfcache can re-surface it. The
     * codes are never written to the session.
     */
    private function renderSetupPage(bool $securityOnly, ?string $newCode = null, ?int $newCodeBranch = null, ?string $newCodeExpires = null, ?array $setupCode = null)
    {
        $user = auth('tenant')->user();
        $response = response()->view('tenant.offline-edge.index', array_merge($this->branchState(), [
            'securityOnly'       => $securityOnly,
            'installerAvailable' => $securityOnly ? false : $this->entitlement->installerIsAvailable(),
            'installerVersion'   => $securityOnly ? null : $this->entitlement->installerVersion(),
            'newCode'            => $newCode,
            'newCodeBranch'      => $newCodeBranch,
            'newCodeExpires'     => $newCodeExpires,
            'setupCode'          => $setupCode,
            'canSecurity'        => (bool) $user?->can(self::SECURITY_PERMISSION),
            'rendererAvailable'  => $this->renderer() !== null,
        ]));

        if ($newCode !== null || $setupCode !== null) {
            $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }

    /** Return to the setup page when reachable, else the security page. */
    private function backTo(): string
    {
        return $this->entitlement->tenantHasOfflineEdgeAccess() && $this->entitlement->featureIsEnabled()
            ? url('/settings/offline-edge')
            : url('/settings/offline-edge/security');
    }

    private function assertEligibleBranch(Branch $branch): void
    {
        abort_unless(
            in_array($branch->local_edge_status, [Branch::STATUS_INACTIVE, Branch::STATUS_PENDING], true),
            422,
        );
    }
}
