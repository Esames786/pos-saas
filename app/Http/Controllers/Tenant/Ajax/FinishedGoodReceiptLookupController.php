<?php

namespace App\Http\Controllers\Tenant\Ajax;

use App\Http\Controllers\Controller;
use App\Models\Tenant\FinishedGoodReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-safe AJAX finished-goods-receipt lookup for Select2 dropdowns.
 *
 * Runs on the tenant connection only. Open receipts by default (not cancelled /
 * closed). Searches fg_no, WIP no, production order no, finished product name/SKU,
 * and customer name. Paginated so large histories never load fully.
 */
class FinishedGoodReceiptLookupController extends Controller
{
    private const PER_PAGE = 25;

    public function __invoke(Request $request): JsonResponse
    {
        $q    = trim((string) $request->input('q', ''));
        $page = max(1, (int) $request->input('page', 1));

        $query = FinishedGoodReceipt::query()
            ->with(['wipJob:id,wip_no', 'productionOrder:id,order_no', 'finishedProduct:id,sku,name']);

        if ($request->boolean('only_open', true)) {
            $query->open();
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('fg_no', 'like', "%{$q}%")
                  ->orWhereHas('wipJob', fn ($j) => $j->where('wip_no', 'like', "%{$q}%"))
                  ->orWhereHas('productionOrder', fn ($p) => $p->where('order_no', 'like', "%{$q}%"))
                  ->orWhereHas('finishedProduct', fn ($p) => $p->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%"))
                  ->orWhereHas('manufacturingCustomer', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            });
        }

        $total   = (clone $query)->count();
        $records = $query->orderByDesc('id')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $results = $records->map(function (FinishedGoodReceipt $r) {
            $label = $r->fg_no;
            if ($r->finishedProduct) {
                $label .= ' — ' . ($r->finishedProduct->sku ? $r->finishedProduct->sku . ' ' : '') . $r->finishedProduct->name;
            }
            return ['id' => $r->id, 'text' => $label];
        });

        return response()->json([
            'results'    => $results,
            'pagination' => ['more' => ($page * self::PER_PAGE) < $total],
        ]);
    }
}
