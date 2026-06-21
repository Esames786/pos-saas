<?php

namespace App\Http\Controllers\Tenant\Ajax;

use App\Http\Controllers\Controller;
use App\Models\Tenant\ProductionOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-safe AJAX production-order lookup for Select2 dropdowns.
 *
 * Runs on the tenant connection only. Open orders by default (not completed /
 * cancelled). Searches order_no, finished product name/SKU, and customer name.
 * Paginated so large order histories never load fully into a dropdown.
 */
class ProductionOrderLookupController extends Controller
{
    private const PER_PAGE = 25;

    public function __invoke(Request $request): JsonResponse
    {
        $q    = trim((string) $request->input('q', ''));
        $page = max(1, (int) $request->input('page', 1));

        $query = ProductionOrder::query()
            ->with(['product:id,sku,name', 'manufacturingCustomer:id,name']);

        if ($request->boolean('only_open', true)) {
            $query->open();
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('order_no', 'like', "%{$q}%")
                  ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%"))
                  ->orWhereHas('manufacturingCustomer', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            });
        }

        $total   = (clone $query)->count();
        $records = $query->orderByDesc('id')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $results = $records->map(function (ProductionOrder $o) {
            $label = $o->order_no;
            if ($o->product) {
                $label .= ' — ' . ($o->product->sku ? $o->product->sku . ' ' : '') . $o->product->name;
            }
            return ['id' => $o->id, 'text' => $label];
        });

        return response()->json([
            'results'    => $results,
            'pagination' => ['more' => ($page * self::PER_PAGE) < $total],
        ]);
    }
}
