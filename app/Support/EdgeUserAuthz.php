<?php

namespace App\Support;

use App\Models\Tenant\User;

/**
 * EDGE-LOCAL-AUTH-1 — the ONE canonical "may this user operate on this branch?" rule for Edge auth.
 *
 * The Cloud codebase is inconsistent (User::canAccessBranch ignores the pivot is_active; the manager
 * approval path requires it). Edge auth uses the STRICT, consistent rule: the user is authorized for
 * a branch iff it is their default branch OR they have an ACTIVE branch_user assignment to it. Used by
 * the enrollment issuer, the local enrollment consumer, and the local auth service.
 */
class EdgeUserAuthz
{
    public static function mayOperateBranch(User $user, int $branchId): bool
    {
        if ((int) $user->default_branch_id === $branchId) {
            return true;
        }

        return $user->branches()
            ->where('branches.id', $branchId)
            ->wherePivot('is_active', true)
            ->exists();
    }

    public static function isActive(User $user): bool
    {
        return $user->status === 'active';
    }

    /**
     * EDGE-LOCAL-AUTH-1 (Section 8) — an Edge-login-capable user MUST have a non-empty employee_code,
     * because the appliance identifies users by employee_code (email/password are never shipped). A
     * user without one can never identify themselves at local login, so enrollment must be refused and
     * readiness must not count them.
     */
    public static function isEdgeLoginEligible(User $user): bool
    {
        return is_string($user->employee_code) && trim($user->employee_code) !== '';
    }

    /**
     * Phase 3 (approver eligibility) — may this user act as the APPROVING manager for an offline POS approval?
     * The ONE Edge approver marker is the Cloud-authoritative bootstrap flag `may_approve_pos` (edge-bootstrap-v8:
     * active manager PIN AND active user on the Cloud; stored on the appliance in the Edge-only users.may_approve_pos
     * column and refreshed with every config revision). It is NOT a permission: holding tenant.pos.void-kot-item (or
     * any other permission) never makes a user an approver — the approver must ALSO hold the permission the approved
     * action needs, which EdgeLocalAuthService::verifyManager checks separately.
     */
    public static function mayApprovePos(User $user): bool
    {
        return self::isActive($user) && (bool) $user->getAttribute('may_approve_pos');
    }
}
