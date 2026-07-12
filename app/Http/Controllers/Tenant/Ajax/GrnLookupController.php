<?php

namespace App\Http\Controllers\Tenant\Ajax;

use App\Http\Controllers\Controller;
use App\Models\Tenant\GoodsReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PURCHASE-RETURNS-1 — Select2 lookup for goods receipts (return sourcing).
 * Filter by supplier/branch; search by GRN no.
 */
class GrnLookupController extends Controller
{
    private const PER_PAGE = 20;

    public function __invoke(Request $request): JsonResponse
    {
        $q    = trim((string) $request->input('q', ''));
        $page = max(1, (int) $request->input('page', 1));

        $query = GoodsReceipt::query()
            ->with(['supplier:id,name', 'branch:id,name'])
            ->when($request->filled('supplier_id'), fn ($s) => $s->where('supplier_id', (int) $request->input('supplier_id')))
            ->when($request->filled('branch_id'), fn ($s) => $s->where('branch_id', (int) $request->input('branch_id')))
            ->when($q !== '', fn ($s) => $s->where('grn_no', 'like', "%{$q}%"))
            ->orderByDesc('id');

        $total   = (clone $query)->count();
        $records = $query->forPage($page, self::PER_PAGE)->get();

        return response()->json([
            'results' => $records->map(fn (GoodsReceipt $grn) => [
                'id'   => $grn->id,
                'text' => $grn->grn_no
                    . ' — ' . ($grn->supplier?->name ?? '')
                    . ' — ' . ($grn->branch?->name ?? '')
                    . ' (' . $grn->receipt_date?->format('Y-m-d') . ')',
            ])->values(),
            'pagination' => ['more' => ($page * self::PER_PAGE) < $total],
        ]);
    }
}
