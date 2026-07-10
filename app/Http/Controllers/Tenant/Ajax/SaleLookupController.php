<?php

namespace App\Http\Controllers\Tenant\Ajax;

use App\Http\Controllers\Controller;
use App\Models\Tenant\SalesOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SALES-RETURN-UX-1 — Select2 lookup for returnable sales.
 *
 * Searches paid / partially_returned sales by sale no, customer name, or
 * phone. Branch-scoped: a user with explicit branch assignments only sees
 * sales of those branches (a user with none — e.g. Owner — sees all).
 */
class SaleLookupController extends Controller
{
    private const PER_PAGE = 20;

    public function __invoke(Request $request): JsonResponse
    {
        $q    = trim((string) $request->input('q', ''));
        $page = max(1, (int) $request->input('page', 1));

        $user      = auth('tenant')->user();
        $branchIds = $user ? $user->branches()->pluck('branches.id') : collect();

        $query = SalesOrder::query()
            ->with(['branch:id,name', 'customer:id,name'])
            ->whereIn('status', ['paid', 'partially_returned'])
            ->when($branchIds->isNotEmpty(), fn ($s) => $s->whereIn('branch_id', $branchIds))
            ->when($request->filled('branch_id'), fn ($s) => $s->where('branch_id', (int) $request->input('branch_id')))
            ->when($q !== '', function ($s) use ($q) {
                $s->where(function ($w) use ($q) {
                    $w->where('sale_no', 'like', "%{$q}%")
                      ->orWhere('customer_name', 'like', "%{$q}%")
                      ->orWhere('customer_phone', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('id');

        $total   = (clone $query)->count();
        $records = $query->forPage($page, self::PER_PAGE)->get();

        return response()->json([
            'results' => $records->map(fn (SalesOrder $sale) => [
                'id'   => $sale->id,
                'text' => $sale->sale_no
                    . ' — ' . ($sale->branch?->name ?? '')
                    . ' — ' . number_format((float) $sale->grand_total, 2)
                    . ($sale->customer_name ? ' — ' . $sale->customer_name : '')
                    . ' (' . $sale->sale_date?->format('Y-m-d') . ')',
            ])->values(),
            'pagination' => ['more' => ($page * self::PER_PAGE) < $total],
        ]);
    }
}
