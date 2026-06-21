<?php

namespace App\Http\Controllers\Tenant\Ajax;

use App\Http\Controllers\Controller;
use App\Models\Tenant\MaterialRequisition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-safe AJAX material-requisition lookup for Select2 dropdowns.
 *
 * Runs on the tenant connection only. Open requisitions by default (not
 * cancelled / closed). Searches mrc_no, production order no, and customer name.
 * Paginated so large histories never load fully into a dropdown.
 */
class MaterialRequisitionLookupController extends Controller
{
    private const PER_PAGE = 25;

    public function __invoke(Request $request): JsonResponse
    {
        $q    = trim((string) $request->input('q', ''));
        $page = max(1, (int) $request->input('page', 1));

        $query = MaterialRequisition::query()
            ->with(['productionOrder:id,order_no', 'manufacturingCustomer:id,name']);

        if ($request->boolean('only_open', true)) {
            $query->open();
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('mrc_no', 'like', "%{$q}%")
                  ->orWhereHas('productionOrder', fn ($p) => $p->where('order_no', 'like', "%{$q}%"))
                  ->orWhereHas('manufacturingCustomer', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            });
        }

        $total   = (clone $query)->count();
        $records = $query->orderByDesc('id')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $results = $records->map(function (MaterialRequisition $m) {
            $label = $m->mrc_no;
            if ($m->productionOrder) {
                $label .= ' — ' . $m->productionOrder->order_no;
            }
            return ['id' => $m->id, 'text' => $label];
        });

        return response()->json([
            'results'    => $results,
            'pagination' => ['more' => ($page * self::PER_PAGE) < $total],
        ]);
    }
}
