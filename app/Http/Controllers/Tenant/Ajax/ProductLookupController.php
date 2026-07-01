<?php

namespace App\Http\Controllers\Tenant\Ajax;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
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

        // PURCHASING-UX-1: the purchase/GRN pickers need richer data (unit cost, unit,
        // default variant, current branch stock) to fill each line + fix the stale-cost bug.
        $rich     = $request->boolean('rich') || in_array((string) $request->input('context', ''), ['purchase', 'inventory'], true);
        $branchId = (int) $request->input('branch_id', 0);

        $query = $rich
            ? Product::query()->with(['unit:id,code,unit_type', 'variants'])
            : Product::query()->select(['id', 'sku', 'name']);

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

        $results = $records->map(function (Product $p) use ($rich, $branchId) {
            $base = [
                'id'   => $p->id,
                'text' => $p->sku ? ($p->sku . ' — ' . $p->name) : $p->name,
            ];

            if (! $rich) {
                return $base;
            }

            $default = $p->variants->firstWhere('is_default', true) ?? $p->variants->first();
            $unitCode = $p->unit?->code;

            $stock = null;
            if ($branchId > 0) {
                $stock = (float) StockBalance::where('product_id', $p->id)
                    ->where('branch_id', $branchId)
                    ->sum('quantity_on_hand');
            }
            $stockLabel = $stock === null
                ? null
                : rtrim(rtrim(number_format($stock, 3), '0'), '.') . ($unitCode ? ' ' . $unitCode : '');

            return array_merge($base, [
                'sku'             => $p->sku,
                'name'            => $p->name,
                'unit_code'       => $unitCode,
                'unit_type'       => $p->unit?->unit_type ?? 'quantity',
                'purchase_price'  => (float) ($p->default_purchase_price ?? 0),
                'requires_batch'  => (bool) $p->requires_batch,
                'has_expiry'      => (bool) $p->has_expiry,
                'is_purchasable'  => (bool) $p->is_purchasable,
                'is_stock_tracked'=> (bool) $p->is_stock_tracked,
                'variant_id'      => $default?->id,
                'variants'        => $p->variants->map(fn ($v) => [
                    'id'             => (int) $v->id,
                    'name'           => $v->name,
                    'sku'            => $v->sku,
                    'barcode'        => $v->barcode,
                    'purchase_price' => (string) ($v->purchase_price ?? 0),
                    'is_default'     => (bool) $v->is_default,
                    'is_active'      => (bool) $v->is_active,
                ])->values(),
                'current_stock'   => $stock,
                'stock_label'     => $stockLabel,
            ]);
        });

        return response()->json([
            'results'    => $results,
            'pagination' => ['more' => ($page * self::PER_PAGE) < $total],
        ]);
    }
}
