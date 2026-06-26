<?php

namespace App\Http\Controllers\Tenant\Ajax;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-safe AJAX product lookup for Select2 dropdowns.
 *
 * All queries run on the tenant connection (Product uses $connection = 'tenant'),
 * so there is no cross-tenant exposure. Results are paginated (25/page) so large
 * catalogues never load fully into a dropdown.
 */
class ProductLookupController extends Controller
{
    private const PER_PAGE = 25;

    public function __invoke(Request $request): JsonResponse
    {
        $q    = trim((string) $request->input('q', ''));
        $page = max(1, (int) $request->input('page', 1));

        $query = Product::query()->select(['id', 'sku', 'name']);

        if ($request->boolean('only_active', true)) {
            $query->where('status', 'active');
        }
        if ($request->boolean('stock_tracked')) {
            $query->where('is_stock_tracked', true);
        }
        if ($request->boolean('sellable')) {
            $query->where('is_sellable', true);
        }
        if ($request->boolean('purchasable')) {
            $query->where('is_purchasable', true);
        }

        // PRODUCT-BOUNDARY-2: optional role context so each screen only offers the right
        // products. Unknown / empty context keeps the original (safe) behaviour.
        switch ((string) $request->input('context', '')) {
            case 'pos':
                $query->where('is_sellable', true)->where('is_pos_visible', true);
                break;
            case 'sales':
                $query->where('is_sellable', true);
                break;
            case 'purchase':
                $query->where('is_purchasable', true);
                break;
            case 'bom_component':
            case 'consumption':
                $query->where('can_be_bom_component', true);
                break;
            case 'bom_output':
            case 'production_order':
            case 'finished_goods':
                $query->where('can_be_bom_output', true);
                break;
            case 'inventory':
                $query->where('is_stock_tracked', true);
                break;
            // 'manufacturing_report' and '' → no extra restriction.
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('sku', 'like', "%{$q}%")
                  ->orWhere('name', 'like', "%{$q}%")
                  ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', 'like', "%{$q}%"));
            });
        }

        $total   = (clone $query)->count();
        $records = $query->orderBy('name')->orderBy('sku')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $results = $records->map(fn (Product $p) => [
            'id'   => $p->id,
            'text' => $p->sku ? ($p->sku . ' — ' . $p->name) : $p->name,
        ]);

        return response()->json([
            'results'    => $results,
            'pagination' => ['more' => ($page * self::PER_PAGE) < $total],
        ]);
    }
}
