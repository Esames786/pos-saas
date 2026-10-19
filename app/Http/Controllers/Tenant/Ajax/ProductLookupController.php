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
        $q = trim((string) $request->input('q', ''));
        $page = max(1, (int) $request->input('page', 1));

        // PURCHASING-UX-1: the purchase/GRN pickers need richer data (unit cost, unit,
        // default variant, current branch stock) to fill each line + fix the stale-cost bug.
        // DEPT-2: department_stock context also returns custody figures (allocated,
        // available-to-issue, source-department on hand, destination mapping match).
        $context = (string) $request->input('context', '');
        $rich = $request->boolean('rich') || in_array($context, ['purchase', 'inventory', 'department_stock'], true);
        $branchId = (int) $request->input('branch_id', 0);
        $fromDeptId = (int) $request->input('from_department_id', 0);
        $toDeptId = (int) $request->input('to_department_id', 0);
        // PURCHASING-V2-1: the new entry area asks for each item's packs, finds a box by its own barcode and
        // shows the last price paid to this supplier — only when it asks; older screens get the same answer.
        $withPacks = $request->boolean('packs') && \App\Support\PackUnits::enabled();
        $supplierId = (int) $request->input('supplier_id', 0);

        $query = $rich
            ? Product::query()->with(['unit:id,code,name,unit_type', 'variants'])
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
            case 'department_stock': // DEPT-2: custody documents move stock-tracked products only.
                $query->where('is_stock_tracked', true);
                break;
                // KASHIF-CATERING-STORE-2: what the store counter can physically hand
                // over. A dish is not issuable — you cannot take biryani out of the
                // store, you take the rice and the chicken that make it. Mirrors the
                // list CateringStoreIssueController already built in PHP, so the
                // searchable picker offers exactly what the plain select did.
            case 'catering_store_issue':
                $query->where('is_stock_tracked', true)
                    ->whereIn('product_kind', [
                        \App\Models\Tenant\Product::KIND_RAW_MATERIAL,
                        \App\Models\Tenant\Product::KIND_PACKAGING_MATERIAL,
                    ]);
                break;
                // 'manufacturing_report' and '' → no extra restriction.
        }

        // PURCHASING-UX-1-LIVE-QA: fetch a single known product (used to refresh a line's
        // branch stock after the branch changes, without re-searching by text).
        if ($pid = (int) $request->input('product_id', 0)) {
            $query->where('id', $pid);
        }

        if ($q !== '') {
            // KASHIF-ORDER-PUNCH: a fully NUMERIC query is a code — a legacy
            // item id (361) or a scanned barcode — never a fragment to find in
            // the middle of a hashed SKU (KM-4323012112 matching "112" buried
            // Chips above the item actually coded 112). Numeric queries match
            // codes by prefix and SKUs by prefix only, with the EXACT code
            // match ranked first; text queries keep the broad behaviour.
            $isCode = ctype_digit($q);
            $query->where(function ($w) use ($q, $isCode, $withPacks) {
                if ($isCode) {
                    $w->where('sku', 'like', "{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', 'like', "{$q}%"));
                } else {
                    $w->where('sku', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', 'like', "%{$q}%"));
                }
                if ($withPacks) {
                    $w->orWhereHas('packUnits', fn ($b) => $b->where('barcode', $q));
                }
            });
            if ($isCode) {
                $query->orderByRaw(
                    'EXISTS(SELECT 1 FROM product_barcodes pb WHERE pb.product_id = products.id AND pb.barcode = ?) DESC',
                    [$q]
                );
            }
        }

        // The dropdown shows a product's SHORT NUMERIC CODE (the legacy item
        // id an operator actually knows — "361") wherever one exists, never a
        // hashed SKU. Real scan barcodes (EAN-8/13) are longer than 5 digits
        // and are deliberately NOT promoted to display codes.
        $query->with('barcodes:id,product_id,barcode');
        if ($withPacks) {
            $query->with('packUnits');
        }
        if ($request->boolean('packs')) {
            // The new purchase screens offer the product's "bought in" unit (a 50 kg sack) with or without packs.
            $query->with('purchaseUnit:id,name');
        }

        $total = (clone $query)->count();

        // PRODUCT-SEARCH-RELEVANCE-1 — the thing you named comes first.
        //
        // The match is a CONTAINS match, which is right, but the order was plain
        // alphabetical and the page holds 25. On a 909-item catalogue typing
        // "chicken" therefore opened with Aaloo Gosht Chicken, Aaloo Qorma
        // Chicken, Achar Gosht Chicken — every dish with the word buried in it —
        // while the dishes actually CALLED "Chicken …" sat hundreds of rows down,
        // pages past anything the operator would scroll. The catalogue was whole;
        // it just could not be reached, and it read as "my products are missing".
        //
        // Five bands, alphabetical inside each: the EXACT CODE first, then the
        // exact name, then names that BEGIN with the term, then the term as a
        // whole word anywhere, then the rest.
        //
        // The code band is the one an operator feels most sharply, because a
        // code search is a PREFIX search: typing "51" matches 51, 513, 514, 515,
        // 517, 518, and alphabetical order put "513 Karahi Chicken Live" above
        // the "51 Taftan -" that was actually asked for. The operator typed the
        // exact id of the thing they wanted and it came fifth. Now an exact code
        // wins outright, and the prefix family follows underneath it.
        if ($q !== '') {
            $like = str_replace(['%', '_'], ['\%', '\_'], $q);
            $query->orderByRaw(
                'CASE
                    WHEN products.sku = ? THEN 0
                    WHEN products.name = ? THEN 1
                    WHEN products.name LIKE ? THEN 2
                    WHEN products.name LIKE ? THEN 3
                    ELSE 4
                 END',
                [$q, $q, $like.'%', '% '.$like.'%']
            );
        }

        $records = $query->orderBy('name')->orderBy('sku')
            ->forPage($page, self::PER_PAGE)
            ->get();

        // DEPT-2: build custody helpers once per request (cheap; page is max 25 rows).
        $deptInventory = $context === 'department_stock'
            ? app(\App\Services\Departments\DepartmentInventoryService::class)
            : null;
        $destResolver = ($context === 'department_stock' && $toDeptId > 0 && $branchId > 0)
            ? \App\Services\Departments\DepartmentMappingService::forBranch($branchId)
            : null;

        $isCode = $isCode ?? false;
        $results = $records->map(function (Product $p) use ($rich, $branchId, $context, $deptInventory, $destResolver, $fromDeptId, $toDeptId, $isCode, $q, $withPacks, $supplierId, $request) {
            // Display code: the barcode the query matched, else the product's
            // shortest short-numeric code (the human item id), else the SKU.
            $codeLabel = null;
            if ($p->relationLoaded('barcodes')) {
                if ($isCode) {
                    $codeLabel = $p->barcodes->first(fn ($b) => str_starts_with($b->barcode, $q))?->barcode;
                }
                $codeLabel ??= $p->barcodes
                    ->filter(fn ($b) => ctype_digit((string) $b->barcode) && strlen((string) $b->barcode) <= 5)
                    ->sortBy(fn ($b) => strlen((string) $b->barcode))
                    ->first()?->barcode;
            }
            // "361 — Biryani Masala Beef" where a human code exists; the plain
            // NAME where it does not. A hashed import SKU (KM-b03682ebfd) is
            // machinery, and machinery has no business in an operator's list.
            $isHashSku = $p->sku && preg_match('/^KM-[0-9a-f]{6,}$/i', (string) $p->sku);
            $label = $codeLabel ?: ($isHashSku ? null : $p->sku);
            $base = [
                'id' => $p->id,
                'text' => $label ? ($label.' — '.$p->name) : $p->name,
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

            // INVENTORY-UX-1: saved batches for this branch+product so adjustment/
            // wastage screens offer a DROPDOWN of real batches instead of free text.
            $batches = [];
            if ($branchId > 0 && $context === 'inventory') {
                $batches = StockBalance::query()
                    ->with('batch:id,batch_no,expiry_date')
                    ->where('product_id', $p->id)
                    ->where('branch_id', $branchId)
                    ->whereNotNull('inventory_batch_id')
                    ->where('quantity_on_hand', '>', 0)
                    ->get()
                    ->map(fn ($b) => [
                        'id' => (int) $b->inventory_batch_id,
                        'batch_no' => $b->batch?->batch_no,
                        'expiry' => $b->batch?->expiry_date?->format('Y-m-d'),
                        'qty' => (float) $b->quantity_on_hand,
                    ])->values()->all();
            }
            $stockLabel = $stock === null
                ? null
                : rtrim(rtrim(number_format($stock, 3), '0'), '.').($unitCode ? ' '.$unitCode : '');

            return array_merge($base, [
                'sku' => $p->sku,
                'name' => $p->name,
                'unit_code' => $unitCode,
                'unit_type' => $p->unit?->unit_type ?? 'quantity',
                'purchase_price' => (float) ($p->default_purchase_price ?? 0),
                'requires_batch' => (bool) $p->requires_batch,
                'has_expiry' => (bool) $p->has_expiry,
                'is_purchasable' => (bool) $p->is_purchasable,
                'is_stock_tracked' => (bool) $p->is_stock_tracked,
                'variant_id' => $default?->id,
                'variants' => $p->variants->map(fn ($v) => [
                    'id' => (int) $v->id,
                    'name' => $v->name,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'purchase_price' => (string) ($v->purchase_price ?? 0),
                    'is_default' => (bool) $v->is_default,
                    'is_active' => (bool) $v->is_active,
                ])->values(),
                'current_stock' => $stock,
                'stock_label' => $stockLabel,
                'allow_decimal' => ($p->unit?->unit_type ?? 'quantity') !== 'quantity',
                'batches' => $batches,
            ] + ($deptInventory && $branchId > 0 ? [
                // DEPT-2 custody figures (variant-level; batch granularity not used here).
                'branch_on_hand' => $branchOnHand = $deptInventory->officialBranchOnHand($branchId, $p->id, $default?->id),
                'department_allocated' => $allocated = $deptInventory->allocatedDepartmentOnHand($branchId, $p->id, $default?->id),
                'available_to_issue' => $branchOnHand - $allocated,
                'source_department_on_hand' => $fromDeptId > 0
                    ? $deptInventory->departmentOnHand($fromDeptId, $p->id, $default?->id)
                    : null,
                'destination_department_match' => $destResolver
                    ? in_array($toDeptId, $destResolver->matchingDepartmentIds($p->id, $p->category_id), true)
                    : null,
                'branch_average_cost' => $deptInventory->officialAverageCost($branchId, $p->id, $default?->id),
            ] : []) + ($request->boolean('packs') ? [
                // PURCHASING-V2-1: biggest pack first; 'key' is what the page posts back (a pack id, or "base").
                'unit_name'    => $p->unit?->name ?: 'Piece',
                'packs'        => collect(\App\Support\PackLines::packsOf($p, $withPacks))->map(fn ($pk, $key) => $pk + ['key' => (string) $key])->values()->all(),
                'unit_set'     => (bool) $p->unit_id,
                'scanned_pack' => $withPacks && $q !== '' ? (string) ($p->packUnits->firstWhere('barcode', $q)?->id ?? '') : '',
                'last_cost'    => $supplierId > 0 ? self::lastCost($p->id, $supplierId) : null,
                // PURCHASE-DISCOUNTS-1: the item's usual buying discount and scheme (product screen).
                'buy_disc'     => $p->purchase_discount_value !== null && (float) $p->purchase_discount_value > 0
                    ? ['type' => $p->purchase_discount_type === 'amount' ? 'amount' : 'percent', 'value' => (float) $p->purchase_discount_value] : null,
                'buy_scheme'   => $p->purchase_scheme_buy ? $p->purchase_scheme_buy . '+' . (int) $p->purchase_scheme_free : null,
            ] : []));
        });

        return response()->json([
            'results' => $results,
            'pagination' => ['more' => ($page * self::PER_PAGE) < $total],
        ]);
    }

    /** PURCHASING-V2-1 — the last price paid to this supplier for one unit of the item, and when. */
    private static function lastCost(int $productId, int $supplierId): ?array
    {
        $row = \Illuminate\Support\Facades\DB::connection('tenant')->table('goods_receipt_lines as l')
            ->join('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')
            ->where('g.supplier_id', $supplierId)->where('g.status', 'posted')
            ->where('l.product_id', $productId)->where('l.unit_cost', '>', 0)
            ->orderByDesc('g.receipt_date')->orderByDesc('l.id')
            ->first(['l.unit_cost', 'g.receipt_date', 'l.entry_json']);
        if (! $row) {
            return null;
        }
        // PURCHASE-DISCOUNTS-1: the new screens keep what was typed (pack, price, disc, scheme) — "the last deal".
        $deal = $row->entry_json ? json_decode((string) $row->entry_json, true) : null;

        return ['unit_cost' => (float) $row->unit_cost, 'date' => (string) $row->receipt_date, 'deal' => is_array($deal) ? $deal : null];
    }
}
