<?php

namespace App\Services\Edge\Cloud;

use App\Exceptions\EdgePairingException;
use App\Models\Master\EdgeDevice;
use App\Models\Master\EdgePairingCode;
use App\Models\Master\EdgeSetupSession;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Services\Edge\EdgePairingService;
use App\Services\Edge\OfflineEdgeEntitlementService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * EDGE 0.9 (K2) — Branch Server setup sessions.
 *
 *   create()  the owner (permission tenant.offline-edge.security, entitlement + rollout checked by the caller) creates a
 *             10-character setup code for ONE branch, valid 60 minutes, shown ONCE. One live code per branch (cancel
 *             first — the same no-silent-replace rule as pairing codes).
 *   redeem()  the setup wizard exchanges the code (public, IP-throttled, per-code attempt limit) for an opaque setup token
 *             (60 min) + a FRESH pairing code minted through the EXISTING EdgePairingService::generateCode (pairing is not
 *             changed: the wizard then runs the existing edge:local:pair with it). For a branch that already has an
 *             active device the session is an UPDATE session: no pairing code, the token still authorises K3.
 *   resolveToken()  K3 — the device-authenticated wizard presents the token; it must be live and scoped to the device's
 *             tenant + branch (and installation, when the redeem named one).
 *
 * SECURITY: the code is stored only as HMAC-SHA256(code, app key); the token only as SHA-256(token). A wrong code matches no
 * row (bounded by the IP throttle on the route — 31^10 ≈ 8·10^14 codes); failures on a RESOLVED code increment its attempt
 * counter and burn it when exhausted, and those failure mutations COMMIT. Audit lines carry identifiers only.
 */
class SetupSessionService
{
    public const CODE_LENGTH      = 10;
    public const CODE_TTL_MINUTES = 60;
    public const TOKEN_TTL_MINUTES = 60;
    public const MAX_ATTEMPTS     = 5;
    /** Unambiguous alphabet: no 0/O, 1/I/L. */
    public const ALPHABET         = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const GEN_RETRIES     = 20;

    public function __construct(
        private readonly EdgePairingService $pairing,
        private readonly OfflineEdgeEntitlementService $entitlement,
        private readonly TenancyManager $tenancy,
        private readonly DeviceProfileService $profiles,
    ) {}

    /* ── hashing / format ───────────────────────────────────────────────── */

    public static function normalise(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public function digest(string $code): string
    {
        return hash_hmac('sha256', 'edge-setup|' . self::normalise($code), (string) config('app.key'));
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** XXXXX-XXXXX for display. */
    public static function display(string $code): string
    {
        $c = self::normalise($code);

        return substr($c, 0, 5) . '-' . substr($c, 5);
    }

    /* ── queries ────────────────────────────────────────────────────────── */

    public function liveSessionForBranch(int $tenantId, int $branchId): ?EdgeSetupSession
    {
        $row = EdgeSetupSession::where('tenant_id', $tenantId)->where('branch_id', $branchId)
            ->where('active_slot', EdgeSetupSession::ACTIVE_SLOT)->first();

        return $row && $row->isLive() ? $row : null;
    }

    /* ── create (tenant page) ───────────────────────────────────────────── */

    /**
     * @return array{code:string, display:string, session:EdgeSetupSession, expires_at:string, mode:string}
     */
    public function create(Tenant $tenant, Branch $branch, int $userId): array
    {
        $device = $this->pairing->activeDeviceForBranch((int) $tenant->id, (int) $branch->id);
        $mode = $device ? EdgeSetupSession::MODE_UPDATE : EdgeSetupSession::MODE_INSTALL;
        if ($mode === EdgeSetupSession::MODE_INSTALL) {
            // Same eligibility as pairing-code generation: a cloud/inactive or pending branch, a free licensed slot.
            if (! in_array($branch->local_edge_status, [Branch::STATUS_INACTIVE, Branch::STATUS_PENDING, null], true)) {
                throw EdgeSetupException::of(EdgeSetupException::BRANCH_NOT_ELIGIBLE);
            }
            if ($this->pairing->activeDeviceCount((int) $tenant->id) >= $this->pairing->deviceLimit($tenant)) {
                throw EdgeSetupException::of(EdgeSetupException::DEVICE_LIMIT);
            }
        }

        $result = DB::connection('master')->transaction(function () use ($tenant, $branch, $userId, $mode) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = EdgeSetupSession::where('tenant_id', $tenant->id)->where('branch_id', $branch->id)
                ->where('active_slot', EdgeSetupSession::ACTIVE_SLOT)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->isLive()) {
                    return ['fail' => EdgeSetupException::CODE_ALREADY_ACTIVE];
                }
                $existing->update(['active_slot' => null]);   // dead code — free the slot
            }

            for ($i = 0; $i < self::GEN_RETRIES; $i++) {
                $code = $this->randomCode();
                try {
                    $row = EdgeSetupSession::create([
                        'public_uuid'        => (string) Str::uuid(),
                        'tenant_id'          => (int) $tenant->id,
                        'branch_id'          => (int) $branch->id,
                        'mode'               => $mode,
                        'code_hash'          => $this->digest($code),
                        'active_slot'        => EdgeSetupSession::ACTIVE_SLOT,
                        'code_expires_at'    => now()->addMinutes(self::CODE_TTL_MINUTES),
                        'attempts'           => 0,
                        'max_attempts'       => self::MAX_ATTEMPTS,
                        'created_by_user_id' => $userId,
                    ]);

                    return ['ok' => $row, 'code' => $code];
                } catch (UniqueConstraintViolationException $e) {
                    continue;   // a historical row already used this hash — draw again
                }
            }

            return ['fail' => EdgeSetupException::CODE_INVALID];
        });

        if (isset($result['fail'])) {
            throw EdgeSetupException::of($result['fail']);
        }
        /** @var EdgeSetupSession $row */
        $row = $result['ok'];

        $this->audit('edge.setup.code_created', [
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'session' => $row->public_uuid, 'mode' => $mode, 'user_id' => $userId,
        ]);

        return [
            'code'       => $result['code'],
            'display'    => self::display($result['code']),
            'session'    => $row,
            'expires_at' => $row->code_expires_at->toIso8601String(),
            'mode'       => $mode,
        ];
    }

    /** Cancel the branch's live setup code AND any unexpired setup token (security control, idempotent). */
    public function cancel(Tenant $tenant, int $branchId, int $userId): int
    {
        $n = EdgeSetupSession::where('tenant_id', $tenant->id)->where('branch_id', $branchId)
            ->whereNull('cancelled_at')
            ->where(fn ($q) => $q->where('active_slot', EdgeSetupSession::ACTIVE_SLOT)->orWhere('token_expires_at', '>', now()))
            ->update(['active_slot' => null, 'cancelled_at' => now(), 'updated_at' => now()]);
        $this->audit('edge.setup.cancelled', ['tenant_id' => $tenant->id, 'branch_id' => $branchId, 'sessions' => $n, 'user_id' => $userId]);

        return $n;
    }

    private function randomCode(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    /* ── redeem (public API) ────────────────────────────────────────────── */

    /**
     * @param array{setup_code:string, installation_uuid:string, device_name?:?string} $in
     * @return array<string,mixed> the K2 response
     */
    public function redeem(array $in): array
    {
        $code = self::normalise((string) ($in['setup_code'] ?? ''));
        if (strlen($code) !== self::CODE_LENGTH) {
            throw EdgeSetupException::of(EdgeSetupException::CODE_INVALID);
        }
        $installationUuid = strtolower((string) ($in['installation_uuid'] ?? ''));
        $deviceName = Str::limit(trim((string) ($in['device_name'] ?? '')), 190, '') ?: null;

        $pre = EdgeSetupSession::where('code_hash', $this->digest($code))->first();
        if (! $pre) {
            throw EdgeSetupException::of(EdgeSetupException::CODE_INVALID);
        }
        $tenant = Tenant::find($pre->tenant_id);
        if (! $tenant || $tenant->status !== 'active') {
            throw EdgeSetupException::of(EdgeSetupException::CODE_INVALID);
        }
        if ($this->profiles->enrollmentPublicKey() === null) {
            // Fail closed BEFORE consuming the code: without the enrollment key the wizard cannot enroll anybody.
            throw EdgeSetupException::of(EdgeSetupException::NOT_CONFIGURED);
        }

        $this->tenancy->activate($tenant);
        try {
            $token = Str::random(48);
            // Phase 1 — claim the code under its row lock (failure mutations COMMIT; the exception is thrown after).
            $claim = DB::connection('master')->transaction(function () use ($code, $installationUuid, $deviceName, $token) {
                $s = EdgeSetupSession::where('code_hash', $this->digest($code))->lockForUpdate()->first();
                if (! $s) {
                    return ['fail' => EdgeSetupException::CODE_INVALID];
                }
                if ($s->isRedeemed()) {
                    return ['fail' => EdgeSetupException::CODE_USED];
                }
                if ($s->isCancelled()) {
                    return ['fail' => EdgeSetupException::CODE_INVALID];
                }
                if ($s->isExhausted()) {
                    if ($s->active_slot !== null) {
                        $s->update(['active_slot' => null]);
                    }
                    return ['fail' => EdgeSetupException::CODE_EXHAUSTED];
                }
                if ($s->isCodeExpired()) {
                    if ($s->active_slot !== null) {
                        $s->update(['active_slot' => null]);
                    }
                    return ['fail' => EdgeSetupException::CODE_EXPIRED];
                }
                if ($s->active_slot !== EdgeSetupSession::ACTIVE_SLOT) {
                    return ['fail' => EdgeSetupException::CODE_INVALID];
                }
                if (! $this->entitlement->featureIsEnabled() || ! $this->entitlement->tenantHasOfflineEdgeAccess()) {
                    $this->burnAttempt($s);
                    return ['fail' => EdgeSetupException::ENTITLEMENT];
                }
                $branch = Branch::find($s->branch_id);
                if (! $branch || $branch->status !== 'active') {
                    $this->burnAttempt($s);
                    return ['fail' => EdgeSetupException::BRANCH_NOT_ELIGIBLE];
                }
                $s->update([
                    'redeemed_at' => now(), 'redeemed_installation_uuid' => $installationUuid, 'redeemed_device_name' => $deviceName,
                    'setup_token_hash' => self::tokenHash($token), 'token_expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
                    'active_slot' => null, 'last_used_at' => now(),
                ]);

                return ['ok' => $s->fresh(), 'branch' => $branch];
            });
            if (isset($claim['fail'])) {
                $this->audit('edge.setup.redeem_refused', ['tenant_id' => $pre->tenant_id, 'branch_id' => $pre->branch_id, 'session' => $pre->public_uuid, 'reason' => $claim['fail']]);
                throw EdgeSetupException::of($claim['fail']);
            }
            /** @var EdgeSetupSession $session */
            $session = $claim['ok'];
            /** @var Branch $branch */
            $branch = $claim['branch'];

            // Phase 2 — an INSTALL session mints a fresh pairing code through the existing service. On failure the claim is
            // released (the owner can fix the branch — e.g. cancel a manual pairing code — and the SAME code works again).
            $device = $this->pairing->activeDeviceForBranch((int) $tenant->id, (int) $branch->id);
            $pairing = null;
            if (! $device) {
                try {
                    $pairing = $this->mintPairingCode($tenant, $branch, $session);
                } catch (EdgeSetupException $e) {
                    $this->releaseClaim($session);
                    $this->audit('edge.setup.redeem_refused', ['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'session' => $session->public_uuid, 'reason' => $e->setupCode]);
                    throw $e;
                }
                $session->forceFill(['pairing_code_uuid' => $pairing['public_uuid'], 'mode' => EdgeSetupSession::MODE_INSTALL])->save();
            } else {
                $session->forceFill(['mode' => EdgeSetupSession::MODE_UPDATE])->save();
            }

            $this->audit('edge.setup.redeemed', [
                'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'session' => $session->public_uuid,
                'installation_uuid' => $installationUuid, 'mode' => $session->mode,
                'pairing_code_uuid' => $pairing['public_uuid'] ?? null, 'device_uuid' => $device?->public_uuid,
            ]);

            return [
                'setup_token'                   => $token,
                'setup_token_expires_at'        => $session->token_expires_at->toIso8601String(),
                'setup_session_id'              => $session->public_uuid,
                'mode'                          => $session->mode,
                'cloud_url'                     => $this->profiles->cloudUrl(),
                'tenant_code'                   => (string) $tenant->tenant_code,
                'branch_id'                     => (int) $branch->id,
                'branch_name'                   => (string) $branch->name,
                'pairing_code'                  => $pairing['code'] ?? null,
                'pairing_code_expires_at'       => $pairing['expires_at'] ?? null,
                'device_id'                     => $device?->public_uuid,
                'enrollment_public_key'         => $this->profiles->enrollmentPublicKey(),
                'update_public_key_fingerprint' => $this->profiles->updateKeyId(),
                'maintenance_window'            => $this->window($device, $branch),
            ];
        } finally {
            $this->tenancy->deactivate();
        }
    }

    /** @return array{start:string,end:string,tz:string} */
    private function window(?EdgeDevice $device, Branch $branch): array
    {
        $w = $this->profiles->maintenanceWindow($device, $branch);

        return ['start' => $w['start'], 'end' => $w['end'], 'tz' => $w['tz']];
    }

    /**
     * Mint the pairing code via EdgePairingService::generateCode. A live pairing code minted by an EARLIER setup session of
     * this branch (a wizard that lost its redeem response) is cancelled first through the same service; a pairing code the
     * owner generated by hand is never replaced (HARDEN-2) — the wizard is told to have it cancelled.
     *
     * @return array{code:string, public_uuid:string, expires_at:string}
     */
    private function mintPairingCode(Tenant $tenant, Branch $branch, EdgeSetupSession $session): array
    {
        if (! in_array($branch->local_edge_status, [Branch::STATUS_INACTIVE, Branch::STATUS_PENDING, null], true)) {
            throw EdgeSetupException::of(EdgeSetupException::BRANCH_NOT_ELIGIBLE);
        }
        $live = $this->pairing->liveCodeForBranch((int) $tenant->id, (int) $branch->id);
        if ($live && ! $live->isExpired()) {
            $ours = EdgeSetupSession::where('tenant_id', $tenant->id)->where('branch_id', $branch->id)
                ->where('pairing_code_uuid', $live->public_uuid)->exists();
            if (! $ours) {
                throw EdgeSetupException::of(EdgeSetupException::PAIRING_CODE_ACTIVE);
            }
            $this->pairing->cancelCode($tenant, $branch, (int) $session->created_by_user_id);
            $branch->refresh();
        }

        try {
            $r = $this->pairing->generateCode($tenant, $branch, (int) $session->created_by_user_id);
        } catch (EdgePairingException $e) {
            throw match ($e->pairingCode) {
                EdgePairingException::CODE_DEVICE_LIMIT    => EdgeSetupException::of(EdgeSetupException::DEVICE_LIMIT),
                EdgePairingException::CODE_ALREADY_ACTIVE  => EdgeSetupException::of(EdgeSetupException::PAIRING_CODE_ACTIVE),
                default                                    => EdgeSetupException::of(EdgeSetupException::PAIRING_UNAVAILABLE),
            };
        }

        return ['code' => $r['code'], 'public_uuid' => $r['public_uuid'], 'expires_at' => $r['expires_at']];
    }

    /** Undo a phase-1 claim after a phase-2 failure: the code becomes redeemable again (one attempt burned). */
    private function releaseClaim(EdgeSetupSession $session): void
    {
        DB::connection('master')->transaction(function () use ($session) {
            $s = EdgeSetupSession::whereKey($session->id)->lockForUpdate()->first();
            if (! $s || $s->isCancelled()) {
                return;
            }
            $attempts = $s->attempts + 1;
            $slotFree = ! EdgeSetupSession::where('tenant_id', $s->tenant_id)->where('branch_id', $s->branch_id)
                ->where('active_slot', EdgeSetupSession::ACTIVE_SLOT)->where('id', '!=', $s->id)->exists();
            $s->update([
                'redeemed_at' => null, 'redeemed_installation_uuid' => null, 'redeemed_device_name' => null,
                'setup_token_hash' => null, 'token_expires_at' => null, 'attempts' => $attempts,
                'active_slot' => ($slotFree && ! $s->isCodeExpired() && $attempts < $s->max_attempts) ? EdgeSetupSession::ACTIVE_SLOT : null,
            ]);
        });
    }

    private function burnAttempt(EdgeSetupSession $s): void
    {
        $s->increment('attempts');
        if ($s->attempts >= $s->max_attempts) {
            $s->update(['active_slot' => null]);
        }
    }

    /* ── K3 token resolution ────────────────────────────────────────────── */

    /** The live setup session the device-authenticated wizard presents, scoped to that device's tenant + branch. */
    public function resolveToken(?string $token, EdgeDevice $device): EdgeSetupSession
    {
        $token = trim((string) $token);
        if ($token === '' || strlen($token) > 200) {
            throw EdgeSetupException::of(EdgeSetupException::TOKEN_INVALID);
        }
        $s = EdgeSetupSession::where('setup_token_hash', self::tokenHash($token))->first();
        if (! $s || ! $s->tokenIsUsable()) {
            throw EdgeSetupException::of(EdgeSetupException::TOKEN_INVALID);
        }
        if ((int) $s->tenant_id !== (int) $device->tenant_id || (int) $s->branch_id !== (int) $device->branch_id
            || ($s->redeemed_installation_uuid && strtolower((string) $device->installation_uuid) !== strtolower((string) $s->redeemed_installation_uuid))) {
            $this->audit('edge.setup.token_scope_refused', ['session' => $s->public_uuid, 'device_uuid' => $device->public_uuid]);
            throw EdgeSetupException::of(EdgeSetupException::TOKEN_SCOPE);
        }

        return $s;
    }

    public function audit(string $event, array $ctx = []): void
    {
        Log::info("[edge-setup-audit] {$event}", $ctx);
    }
}
