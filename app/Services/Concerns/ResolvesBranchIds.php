<?php

namespace App\Services\Concerns;

/**
 * Resolves a report service's branch filter into an array<int> of branch IDs
 * (or null = all branches), accepting either the new multi-select `branch_ids`
 * or the legacy single `branch_id`. Keeps report services backward compatible
 * with any caller that still passes only `branch_id`.
 */
trait ResolvesBranchIds
{
    protected function resolveBranchIds(array $filters): ?array
    {
        if (! empty($filters['branch_ids']) && is_array($filters['branch_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['branch_ids'])));
            return $ids ?: null;
        }

        if (! empty($filters['branch_id'])) {
            return [(int) $filters['branch_id']];
        }

        return null;
    }
}
