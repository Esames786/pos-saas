<?php

namespace App\Services\Edge\Cloud;

use App\Models\Master\EdgeDevice;
use App\Models\Master\EdgeSetupSession;
use App\Models\Master\Tenant;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeActivationEpochService;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgeEnrollmentIssuer;
use App\Services\Edge\EdgePairingService;
use App\Services\Edge\OfflineEdgeEntitlementService;
use App\Services\Tenancy\TenancyManager;
use App\Support\EdgeUserAuthz;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * EDGE 0.9 (K3) — bulk enrollment assertions for the setup wizard's "Users & PINs" page.
 *
 * The wizard (device-authenticated + X-Edge-Setup-Token) asks for the branch's users at once; each assertion is issued by
 * the EXISTING EdgeEnrollmentIssuer (same payload, same Ed25519 key, same 15-minute TTL, same fail-closed contract) with
 * the setup session's OWNER as the issuer. Users that cannot enroll are listed in `skipped` with a reason instead of
 * failing the whole call: not_found, inactive, no_employee_code, not_on_branch, refused. One audit line per assertion
 * (identifiers only — never the assertion body).
 */
class BulkEnrollment
{
    public const MAX_USERS = 300;
    /** A setup session may mint at most this many assertions over its life (re-tries included). */
    public const MAX_PER_SESSION = 1000;

    public function __construct(
        private readonly EdgeEnrollmentIssuer $issuer,
        private readonly EdgePairingService $pairing,
        private readonly EdgeActivationEpochService $epoch,
        private readonly OfflineEdgeEntitlementService $entitlement,
        private readonly TenancyManager $tenancy,
    ) {}

    /**
     * @param list<int>|null $userIds  explicit users, or null with $allActive = true for every user of the branch
     * @return array{assertions: list<array<string,mixed>>, skipped: list<array{user_id:int, reason:string}>}
     */
    public function issue(EdgeDevice $device, EdgeSetupSession $session, ?array $userIds, bool $allActive): array
    {
        $tenant = Tenant::find((int) $device->tenant_id);
        if (! $tenant) {
            throw EdgeSetupException::of(EdgeSetupException::DEVICE_NOT_CURRENT);
        }

        $this->tenancy->activate($tenant);
        try {
            $this->assertIssuable($tenant, $device);
            $branchId = (int) $device->branch_id;

            $skipped = [];
            if ($allActive) {
                $assigned = DB::connection('tenant')->table('branch_user')->where('branch_id', $branchId)->where('is_active', 1)->pluck('user_id')->all();
                $users = User::query()
                    ->where(fn ($q) => $q->where('default_branch_id', $branchId)->orWhereIn('id', $assigned ?: [0]))
                    ->orderBy('id')->limit(self::MAX_USERS + 1)->get();
                if ($users->count() > self::MAX_USERS) {
                    throw EdgeSetupException::of(EdgeSetupException::ENROLLMENT_UNAVAILABLE, 'Too many users for one request; send user_ids in batches of ' . self::MAX_USERS . '.');
                }
            } else {
                $ids = array_values(array_unique(array_map('intval', $userIds ?? [])));
                $users = User::query()->whereIn('id', $ids ?: [0])->orderBy('id')->get();
                foreach (array_diff($ids, $users->pluck('id')->map(fn ($v) => (int) $v)->all()) as $missing) {
                    $skipped[] = ['user_id' => (int) $missing, 'reason' => 'not_found'];
                }
            }

            if ((int) $session->assertions_issued + $users->count() > self::MAX_PER_SESSION) {
                throw EdgeSetupException::of(EdgeSetupException::ENROLLMENT_UNAVAILABLE, 'This setup session has issued its maximum number of assertions; create a new setup code.');
            }

            $assertions = [];
            foreach ($users as $user) {
                $reason = $this->skipReason($user, $branchId);
                if ($reason !== null) {
                    $skipped[] = ['user_id' => (int) $user->id, 'reason' => $reason];
                    continue;
                }
                try {
                    $assertion = $this->issuer->issue($tenant, $branchId, $device, $user, (int) $session->created_by_user_id);
                } catch (RuntimeException $e) {
                    $skipped[] = ['user_id' => (int) $user->id, 'reason' => 'refused'];
                    continue;
                }
                $payload = $assertion['payload'];
                Log::info('[edge-enrollment-issued]', [
                    'via'              => 'setup_session',
                    'setup_session'    => $session->public_uuid,
                    'issuer_user_id'   => (int) $session->created_by_user_id,
                    'target_user_id'   => (int) $user->id,
                    'tenant_id'        => (int) $tenant->id,
                    'branch_id'        => $branchId,
                    'device'           => $device->public_uuid,
                    'activation_epoch' => $payload['activation_epoch'] ?? null,
                    'jti'              => $payload['jti'] ?? null,
                    'expires_at'       => $payload['expires_at'] ?? null,
                ]);
                $assertions[] = [
                    'user_id'       => (int) $user->id,
                    'employee_code' => (string) $user->employee_code,
                    'name'          => (string) $user->name,
                    'expires_at'    => isset($payload['expires_at']) ? date(DATE_ATOM, (int) $payload['expires_at']) : null,
                    'assertion'     => $assertion,
                ];
            }

            if ($assertions !== []) {
                EdgeSetupSession::whereKey($session->id)->update([
                    'assertions_issued' => DB::raw('assertions_issued + ' . count($assertions)),
                    'last_used_at' => now(), 'updated_at' => now(),
                ]);
            }

            return ['assertions' => $assertions, 'skipped' => $skipped];
        } finally {
            $this->tenancy->deactivate();
        }
    }

    /** Global preconditions — a failure here is the whole call's (409/503), not one user's. */
    private function assertIssuable(Tenant $tenant, EdgeDevice $device): void
    {
        if ((string) config('edge.enrollment.signing_key') === '' || ! EdgeEnrollmentCrypto::available()) {
            throw EdgeSetupException::of(EdgeSetupException::NOT_CONFIGURED, null, ['reason' => 'no_signing_key']);
        }
        $current = $this->pairing->activeDeviceForBranch((int) $tenant->id, (int) $device->branch_id);
        if (! $current || (int) $current->id !== (int) $device->id) {
            throw EdgeSetupException::of(EdgeSetupException::DEVICE_NOT_CURRENT);
        }
        if (! $this->entitlement->featureIsEnabled() || ! $this->entitlement->tenantHasOfflineEdgeAccess()) {
            throw EdgeSetupException::of(EdgeSetupException::ENTITLEMENT);
        }
        if ($this->epoch->currentGeneration((int) $tenant->id, (int) $device->branch_id) < 1) {
            throw EdgeSetupException::of(EdgeSetupException::ENROLLMENT_UNAVAILABLE, 'The branch has no activation generation yet — finish the bootstrap first.', ['reason' => 'no_activation']);
        }
    }

    private function skipReason(User $user, int $branchId): ?string
    {
        if (! EdgeUserAuthz::isActive($user)) {
            return 'inactive';
        }
        if (! EdgeUserAuthz::isEdgeLoginEligible($user)) {
            return 'no_employee_code';
        }
        if (! EdgeUserAuthz::mayOperateBranch($user, $branchId)) {
            return 'not_on_branch';
        }

        return null;
    }
}
