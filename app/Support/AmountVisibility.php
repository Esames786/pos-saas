<?php

namespace App\Support;

use App\Models\Tenant\Branch;
use App\Models\Tenant\User;

/**
 * HIDE-AMOUNTS-1 — one place that answers "may this person read the money?".
 *
 * Three screens ask it (Close Branch, Close Shift, the dashboard tiles). One arithmetic, so the
 * three cannot drift apart the way the quotation and the kitchen sheet once did.
 *
 * Two switches, and BOTH must move before anything is hidden:
 *   - the branch has `hide_amounts_from_operators` on, AND
 *   - the user does not hold `tenant.shifts.view-amounts`.
 *
 * The flag ships off and the permission ships granted to every role, so on the day this deploys
 * nothing changes for anybody.
 */
class AmountVisibility
{
    public const MASK = '*****';

    /** May this user read money figures for this branch? */
    public function allows(?User $user, ?Branch $branch): bool
    {
        if (! $branch || ! $branch->hide_amounts_from_operators) {
            return true;
        }

        return (bool) $user?->can('tenant.shifts.view-amounts');
    }

    /**
     * The dashboard's branch selector can say "All Branches", so there is not always one branch to
     * ask about. Fail CLOSED: if any branch in view is restricted and the user cannot read money,
     * the tiles are masked. Showing the money because the question was ambiguous is the one answer
     * that cannot be defended.
     */
    public function allowsAcross(?User $user, iterable $branches): bool
    {
        foreach ($branches as $branch) {
            if (! $this->allows($user, $branch)) {
                return false;
            }
        }

        return true;
    }

    /** The figure, or the mask — for a Blade that should never have to decide. */
    public function format(bool $allowed, float|int|null $value, int $decimals = 2): string
    {
        return $allowed ? number_format((float) $value, $decimals) : self::MASK;
    }
}
