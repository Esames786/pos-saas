<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Customer;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\Product;
use App\Models\Tenant\RestaurantFloor;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\RestaurantWaiter;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\TerminalPrinterSetting;
use App\Services\Kitchen\UnitConversionService;
use App\Services\Sales\SalesTotalsService;
use Illuminate\Http\Request;

class POSController extends Controller
{
    public function index(Request $request)
    {
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        $selectedBranchId = (int) (
            $request->branch_id
            ?: optional($branches->first())->id
        );

        $heldSale = null;

        if ($request->filled('held_sale_id')) {
            $heldSale = SalesOrder::with([
                    'lines',
                    'customer',
                    'restaurantTableSession.table.floor',
                    'restaurantTableSession.waiter',
                    'restaurantTable',
                    'restaurantWaiter',
                ])
                ->where('status', 'held')
                ->find($request->held_sale_id);
        }

        $tableSession = null;

        if ($request->filled('table_session_id')) {
            $tableSession = RestaurantTableSession::with(['table.floor', 'waiter'])
                ->whereIn('status', ['open', 'bill_requested'])
                ->find($request->table_session_id);
        }

        if (!$tableSession && $heldSale?->restaurant_table_session_id) {
            $tableSession = RestaurantTableSession::with(['table.floor', 'waiter'])
                ->whereIn('status', ['open', 'bill_requested'])
                ->find($heldSale->restaurant_table_session_id);
        }

        $floors = $this->loadBoardFloors($selectedBranchId);

        $waiters = RestaurantWaiter::where(function ($query) use ($selectedBranchId) {
                $query->whereNull('branch_id')
                    ->orWhere('branch_id', $selectedBranchId);
            })
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $products = Product::with([
                'category',
                'unit',
                'defaultVariant',
                'variants.barcodes',
                'barcodes',
                'branchPrices',
                // Recipe data drives proactive "makeable" availability for service products.
                'activeRecipe.ingredients.product.unit',
                'activeRecipe.ingredients.unit',
                'activeRecipe.ingredients.variant',
            ])
            ->where('status', 'active')
            ->where('is_sellable', true)
            ->orderBy('name')
            ->get();

        $stockRows = StockBalance::query()
            ->selectRaw('branch_id, product_id, product_variant_id, SUM(quantity_on_hand) as qty')
            ->groupBy('branch_id', 'product_id', 'product_variant_id')
            ->get();

        $stockByProduct = $stockRows
            ->groupBy(fn ($row) => $row->branch_id . ':' . $row->product_id . ':' . ($row->product_variant_id ?: 0));

        // Fast [branchId][productId][variantId] => qty lookup for recipe ingredient stock.
        $stockLookup = [];
        foreach ($stockRows as $row) {
            $stockLookup[(int) $row->branch_id][(int) $row->product_id][(int) ($row->product_variant_id ?: 0)] = (float) $row->qty;
        }

        $unitConversion = app(UnitConversionService::class);

        $productsPayload = $products->map(function ($product) use ($stockByProduct, $branches, $stockLookup, $unitConversion) {
            $defaultVariant = $product->defaultVariant ?: $product->variants->first();

            $barcodes = $product->barcodes
                ? $product->barcodes->pluck('barcode')->filter()->values()
                : collect();

            $branchPrices = $product->branchPrices
                ? $product->branchPrices->map(function ($price) {
                    return [
                        'branch_id'          => (int) $price->branch_id,
                        'product_variant_id' => $price->product_variant_id ? (int) $price->product_variant_id : null,
                        'selling_price'      => (float) $price->selling_price,
                    ];
                })->values()
                : collect();

            $variants = $product->variants->map(function ($variant) use ($product, $stockByProduct) {
                $stockMap = [];

                foreach ($stockByProduct as $rows) {
                    foreach ($rows as $row) {
                        if (
                            (int) $row->product_id === (int) $product->id
                            && (int) ($row->product_variant_id ?: 0) === (int) $variant->id
                        ) {
                            $stockMap[(int) $row->branch_id] = (float) $row->qty;
                        }
                    }
                }

                return [
                    'id'              => (int) $variant->id,
                    'name'            => $variant->name,
                    'sku'             => $variant->sku,
                    'selling_price'   => (float) ($variant->selling_price ?? $product->default_selling_price ?? $product->selling_price ?? 0),
                    'stock_by_branch' => $stockMap,
                    'barcodes'        => $variant->barcodes->pluck('barcode')->filter()->values(),
                ];
            })->values();

            $stockMap = [];

            foreach ($stockByProduct as $rows) {
                foreach ($rows as $row) {
                    if (
                        (int) $row->product_id === (int) $product->id
                        && (int) ($row->product_variant_id ?: 0) === 0
                    ) {
                        $stockMap[(int) $row->branch_id] = (float) $row->qty;
                    }
                }
            }

            $recipe = $this->recipeAvailability($product, $branches, $stockLookup, $unitConversion);

            return [
                'id'                => (int) $product->id,
                'name'              => $product->name,
                'sku'               => $product->sku,
                'category_id'       => $product->category_id ? (int) $product->category_id : null,
                'category_name'     => $product->category?->name,
                'unit_id'           => $product->unit_id ? (int) $product->unit_id : null,
                'unit_name'         => $product->unit?->name,
                'unit_code'         => $product->unit?->code,
                'unit_type'         => $product->unit?->unit_type ?? 'quantity',
                'allow_decimal_qty' => $product->unit && $product->unit->unit_type !== 'quantity',
                'quantity_step'     => $product->unit && $product->unit->unit_type !== 'quantity' ? 0.001 : 1,
                'price'             => (float) ($defaultVariant?->selling_price ?? $product->default_selling_price ?? $product->selling_price ?? 0),
                'is_stock_tracked'  => (bool) $product->is_stock_tracked,
                'is_taxable'        => (bool) ($product->is_taxable ?? false),
                'tax_rate_percent'  => (float) ($product->tax_rate_percent ?? 0),
                'barcodes'          => $barcodes,
                'branch_prices'     => $branchPrices,
                'variants'          => $variants,
                'stock_by_branch'   => $stockMap,
                'is_recipe'                     => $recipe['is_recipe'],
                'makeable_by_branch'            => $recipe['makeable'],
                'limiting_ingredient_by_branch' => $recipe['limiting'],
            ];
        })->values();

        return view('tenant.pos.index', [
            'branches'         => $branches,
            'selectedBranchId' => $selectedBranchId,
            'terminals'        => Terminal::where('status', 'active')->with('branch')->orderBy('name')->get(),
            'customers'        => Customer::where('status', 'active')->orderBy('name')->get(),
            'categories'       => Category::with('children')
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'productsPayload'  => $productsPayload,
            'paymentMethods'   => PaymentMethod::where('is_active', true)
                ->orderByRaw("CASE WHEN method_type = 'cash' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get(),
            'floors'              => $floors,
            'waiters'             => $waiters,
            'tableSession'        => $tableSession,
            'heldSale'            => $heldSale,
            'terminalPrintConfig' => TerminalPrinterSetting::all()
                ->keyBy('terminal_id')
                ->map(fn ($s) => [
                    'auto_print_receipt' => (bool) $s->auto_print_receipt,
                    'auto_print_kot'     => (bool) $s->auto_print_kot,
                ]),
            'activeMode'       => $tableSession || $heldSale?->restaurant_table_session_id
                ? 'dine_in'
                : ($request->input('mode', 'quick_sale')),
        ]);
    }

    /**
     * Eager-load the dine-in board for a branch. Shared by the full POS page render
     * and the AJAX board-refresh endpoint so the tile markup has one source of truth.
     */
    private function loadBoardFloors(int $branchId)
    {
        return RestaurantFloor::with([
                'tables.openSession.waiter',
                'tables.openSession.salesOrders' => function ($query) {
                    $query->whereIn('status', ['held', 'paid']);
                },
            ])
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * AJAX: re-render just the live table board for a branch (no full page reload).
     * Returns the same partial used on first load, with the given session highlighted,
     * so the board stays accurate after open/continue/select actions.
     */
    public function tableBoard(Request $request)
    {
        $selectedBranchId = (int) (
            $request->branch_id
            ?: optional(Branch::where('status', 'active')->orderBy('name')->first())->id
        );

        $tableSession = null;
        if ($request->filled('selected_session_id')) {
            $tableSession = RestaurantTableSession::with(['table.floor', 'waiter'])
                ->whereIn('status', ['open', 'bill_requested'])
                ->find($request->selected_session_id);
        }

        $html = view('tenant.pos.partials.table-board', [
            'floors'           => $this->loadBoardFloors($selectedBranchId),
            'selectedBranchId' => $selectedBranchId,
            'tableSession'     => $tableSession,
        ])->render();

        return response()->json(['ok' => true, 'html' => $html]);
    }

    /**
     * Proactive availability for recipe/service products: how many can be MADE per
     * branch from current ingredient stock, plus the limiting ingredient name.
     * Mirrors RecipeConsumptionService (same unit conversion) so the POS preview
     * matches what checkout would actually consume. Backend remains authoritative.
     *
     * @return array{is_recipe:bool, makeable:array<int,int>, limiting:array<int,string>}
     */
    private function recipeAvailability(Product $product, $branches, array $stockLookup, UnitConversionService $unitConversion): array
    {
        $blank = ['is_recipe' => false, 'makeable' => [], 'limiting' => []];

        if ($product->inventory_consumption_method !== 'recipe') {
            return $blank;
        }

        $recipe = $product->activeRecipe;
        if (! $recipe) {
            return $blank;
        }

        // Only stock-tracked ingredients constrain how many we can make.
        $ingredients = $recipe->ingredients->filter(
            fn ($ing) => $ing->product && $ing->product->is_stock_tracked
        );

        if ($ingredients->isEmpty()) {
            return $blank; // recipe with no stock-tracked ingredients → unlimited (plain service)
        }

        $yield = (float) ($recipe->yield_quantity ?: 1) ?: 1;

        $makeable = [];
        $limiting = [];

        foreach ($branches as $branch) {
            $branchId = (int) $branch->id;
            $minUnits = null;
            $limitName = null;

            foreach ($ingredients as $ing) {
                $ip = $ing->product;

                $requiredPerBatch = (float) $ing->quantity;

                // Convert ingredient unit → ingredient product base unit (as consumption does).
                if ($ing->unit_id && $ip->unit_id && $ing->unit_id !== $ip->unit_id && $ing->unit && $ip->unit) {
                    try {
                        $requiredPerBatch = $unitConversion->convert($requiredPerBatch, $ing->unit, $ip->unit);
                    } catch (\Throwable) {
                        // no conversion path — use as-is
                    }
                }

                if ($requiredPerBatch <= 0) {
                    continue;
                }

                $variantId = (int) ($ing->product_variant_id ?: 0);
                $stock = $stockLookup[$branchId][(int) $ip->id][$variantId] ?? 0.0;

                $units = ($stock / $requiredPerBatch) * $yield;

                if ($minUnits === null || $units < $minUnits) {
                    $minUnits = $units;
                    $limitName = $ip->name;
                }
            }

            $makeable[$branchId]  = (int) floor(max(0, $minUnits ?? 0));
            $limiting[$branchId]  = $limitName ?? '';
        }

        return ['is_recipe' => true, 'makeable' => $makeable, 'limiting' => $limiting];
    }

    public function quoteTotals(Request $request, SalesTotalsService $totalsService)
    {
        $data = $request->validate([
            'branch_id'               => ['required', 'exists:branches,id'],
            'order_type'              => ['required', 'in:quick_sale,takeaway,dine_in,delivery'],
            'discount_type'           => ['nullable', 'in:none,fixed,percent'],
            'discount_value'          => ['nullable', 'numeric', 'min:0'],
            'promo_code'              => ['nullable', 'string', 'max:50'],
            'tip_amount'              => ['nullable', 'numeric', 'min:0'],
            'lines'                   => ['nullable', 'array'],
            'lines.*.quantity'        => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_amount'      => ['nullable', 'numeric', 'min:0'],
        ]);

        $resolvedLines = collect($data['lines'] ?? [])
            ->filter(fn ($line) => (float) ($line['quantity'] ?? 0) > 0)
            ->map(fn ($line) => [
                'quantity'        => (float) ($line['quantity'] ?? 0),
                'unit_price'      => (float) ($line['unit_price'] ?? 0),
                'discount_amount' => (float) ($line['discount_amount'] ?? 0),
                'tax_amount'      => (float) ($line['tax_amount'] ?? 0),
            ])
            ->values()
            ->toArray();

        $totals = $totalsService->calculate(
            resolvedLines: $resolvedLines,
            discountType:  $data['discount_type'] ?? 'none',
            discountValue: (float) ($data['discount_value'] ?? 0),
            branchId:      (int) $data['branch_id'],
            orderType:     $data['order_type'],
            promoCode:     $data['promo_code'] ?? null,
            tipAmount:     (float) ($data['tip_amount'] ?? 0),
        );

        return response()->json([
            'ok'                         => true,
            'subtotal'                   => $totals['subtotal'],
            'discount_amount'            => $totals['discount_amount'],
            'promotion_id'               => $totals['promotion_id'],
            'promo_code'                 => $totals['promo_code'],
            'promotion_discount_amount'  => $totals['promotion_discount_amount'],
            'tax_amount'                 => $totals['tax_amount'],
            'service_charge_amount'      => $totals['service_charge_amount'],
            'tip_amount'                 => $totals['tip_amount'],
            'grand_total'                => $totals['grand_total'],
        ]);
    }
}
