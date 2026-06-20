<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Normalises branch filter input for report / filter screens into a single
 * shape: an array<int> of branch IDs, or null meaning "all branches".
 *
 * Multi-select reports submit `branch_ids[]`. Older single-branch screens
 * submit `branch_id`. This trait accepts either, so report controllers stay
 * backward compatible while moving to multi-select.
 *
 * NOTE: This is for reports/filters only. Transactional records (sales,
 * expenses, purchases, production orders, stock movements, etc.) belong to a
 * single branch and must keep using a plain `branch_id` field — do not use
 * this helper to persist a transaction's branch.
 */
trait NormalizesBranchIds
{
    protected function normalizeBranchIds(Request $request): ?array
    {
        if ($request->filled('branch_ids')) {
            $ids = array_values(array_filter(array_map('intval', (array) $request->input('branch_ids'))));
            return $ids ?: null;
        }

        if ($request->filled('branch_id')) {
            return [(int) $request->input('branch_id')];
        }

        return null;
    }
}
