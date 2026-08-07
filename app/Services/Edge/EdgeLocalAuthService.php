<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeAuthAudit;
use App\Models\Edge\EdgeLocalUserCredential;
use App\Models\Tenant\User;
use App\Support\EdgeRuntime;
use App\Support\EdgeUserAuthz;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EDGE-LOCAL-AUTH-1 (Sections 8/10/11) — the Branch Server's Edge-specific authentication boundary.
 *
 * Verifies ONLY the Edge credential (edge_local_user_credentials, Argon2id) — NEVER the Cloud
 * users.password / auth('tenant')->attempt(). Requires branch_server + a bootstrap binding; the user
 * must be active, branch-authorized (bound branch is authority), and their credential must belong to
 * the current activation epoch. Applies per-user lockout. Reuses the existing Tenant\User identity +
 * Spatie roles/permissions (no new role system). Never queries the Cloud master DB.
 */
class EdgeLocalAuthService
{
    public const GUARD = 'tenant';
    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_SECONDS = 300;

    public function __construct(private readonly EdgeBranchContext $context)
    {
    }

    /** Verify for LOGIN and return the User. Throws with a generic message on any failure. */
    public function verifyForLogin(string $employeeCode, string $credential, ?string $ip = null): User
    {
        $user = $this->verify($employeeCode, $credential, EdgeAuthAudit::E_LOGIN_FAIL, $ip);
        EdgeAuthAudit::record(EdgeAuthAudit::E_LOGIN_OK, ['user_id' => $user->id, 'branch_id' => $this->context->boundBranchId(), 'ip' => $ip]);

        return $user;
    }

    /** Establish the local Laravel session for the verified user (session regen is the controller's job). */
    public function login(User $user): void
    {
        auth(self::GUARD)->login($user);
    }

    public function logout(?int $userId = null, ?string $ip = null): void
    {
        auth(self::GUARD)->logout();
        EdgeAuthAudit::record(EdgeAuthAudit::E_LOGOUT, ['user_id' => $userId, 'ip' => $ip]);
    }

    /**
     * Section 11 — offline MANAGER re-authentication. The manager authenticates with THEIR OWN Edge
     * credential and must hold $requiredPermission. Returns the manager User (the approval identity is
     * the manager, not the requesting cashier).
     */
    public function verifyManager(string $employeeCode, string $credential, string $requiredPermission, ?string $ip = null): User
    {
        $manager = $this->verify($employeeCode, $credential, EdgeAuthAudit::E_MGR_FAIL, $ip);
        if (! $manager->can($requiredPermission)) {
            EdgeAuthAudit::record(EdgeAuthAudit::E_MGR_FAIL, ['user_id' => $manager->id, 'branch_id' => $this->context->boundBranchId(), 'detail' => 'missing_permission', 'ip' => $ip]);
            throw new RuntimeException('This user is not authorized to approve that action.');
        }
        EdgeAuthAudit::record(EdgeAuthAudit::E_MGR_OK, ['user_id' => $manager->id, 'branch_id' => $this->context->boundBranchId(), 'detail' => $requiredPermission, 'ip' => $ip]);

        return $manager;
    }

    // ── core ───────────────────────────────────────────────────────────────

    private function verify(string $employeeCode, string $credential, string $failEvent, ?string $ip): User
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('Local authentication only runs on a Branch Server.');
        }
        $meta = $this->context->requireCurrent(); // EdgeNotBoundException if uninitialised
        $branchId = (int) $meta->branch_id;

        $fail = function (string $why) use ($failEvent, $branchId, $ip): never {
            EdgeAuthAudit::record($failEvent, ['branch_id' => $branchId, 'detail' => $why, 'ip' => $ip]); // best-effort
            throw new RuntimeException('Invalid credentials.'); // generic — never leak which check failed
        };

        // User resolution + eligibility do not need the credential row lock.
        $user = User::on('tenant')->where('employee_code', $employeeCode)->first();
        if (! $user || ! EdgeUserAuthz::isActive($user) || ! EdgeUserAuthz::isEdgeLoginEligible($user)) {
            $fail('user_ineligible_or_missing');
        }
        if (! EdgeUserAuthz::mayOperateBranch($user, $branchId)) {
            $fail('user_not_branch_authorized');
        }

        // Concurrency-safe: lock the credential row so simultaneous attempts serialise — no lost
        // failed_attempts increment and the lockout transition happens exactly once. The counter/lockout
        // (and its durable audit) COMMIT even on a failed attempt; the throw happens AFTER the txn.
        $outcome = DB::connection('tenant')->transaction(function () use ($user, $meta, $branchId, $credential, $ip) {
            $cred = EdgeLocalUserCredential::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $cred || ! $cred->isActive()) {
                return 'no_credential';
            }
            if ((int) $cred->activation_epoch !== (int) $meta->activation_epoch) {
                return 'stale_epoch'; // credential belongs to a superseded appliance generation
            }
            if ($cred->isLocked()) {
                return 'locked';
            }
            if (! password_verify($credential, (string) $cred->credential_hash)) {
                $attempts = (int) $cred->failed_attempts + 1;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $cred->update(['failed_attempts' => 0, 'locked_until' => now()->addSeconds(self::LOCKOUT_SECONDS)]);
                    EdgeAuthAudit::recordDurable(EdgeAuthAudit::E_LOCKOUT, ['user_id' => $user->id, 'branch_id' => $branchId, 'ip' => $ip]);

                    return 'locked_now';
                }
                $cred->update(['failed_attempts' => $attempts]);

                return 'bad_credential';
            }
            $cred->update(['failed_attempts' => 0, 'locked_until' => null, 'last_authenticated_at' => now()]);

            return 'ok';
        });

        if ($outcome === 'ok') {
            return $user;
        }
        if ($outcome === 'locked' || $outcome === 'locked_now') {
            EdgeAuthAudit::record($failEvent, ['user_id' => $user->id, 'branch_id' => $branchId, 'detail' => 'locked', 'ip' => $ip]);
            throw new RuntimeException('This account is temporarily locked. Try again later.');
        }
        $fail($outcome); // no_credential | stale_epoch | bad_credential
    }
}
