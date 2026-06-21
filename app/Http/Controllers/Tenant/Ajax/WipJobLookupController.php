<?php

namespace App\Http\Controllers\Tenant\Ajax;

use App\Http\Controllers\Controller;
use App\Models\Tenant\WipJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-safe AJAX WIP-job lookup for Select2 dropdowns.
 *
 * Runs on the tenant connection only. Open jobs by default (not completed /
 * cancelled). Searches wip_no, production order no, finished product name/SKU,
 * and customer name. Paginated so large histories never load fully.
 */
class WipJobLookupController extends Controller
{
    private const PER_PAGE = 25;

    public function __invoke(Request $request): JsonResponse
    {
        $q    = trim((string) $request->input('q', ''));
        $page = max(1, (int) $request->input('page', 1));

        $query = WipJob::query()
            ->with(['productionOrder:id,order_no', 'finishedProduct:id,sku,name']);

        if ($request->boolean('only_open', true)) {
            $query->open();
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('wip_no', 'like', "%{$q}%")
                  ->orWhereHas('productionOrder', fn ($p) => $p->where('order_no', 'like', "%{$q}%"))
                  ->orWhereHas('finishedProduct', fn ($p) => $p->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%"))
                  ->orWhereHas('manufacturingCustomer', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            });
        }

        $total   = (clone $query)->count();
        $records = $query->orderByDesc('id')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $results = $records->map(function (WipJob $j) {
            $label = $j->wip_no;
            if ($j->finishedProduct) {
                $label .= ' — ' . ($j->finishedProduct->sku ? $j->finishedProduct->sku . ' ' : '') . $j->finishedProduct->name;
            }
            return ['id' => $j->id, 'text' => $label];
        });

        return response()->json([
            'results'    => $results,
            'pagination' => ['more' => ($page * self::PER_PAGE) < $total],
        ]);
    }
}
