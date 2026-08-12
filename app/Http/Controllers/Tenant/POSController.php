<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Combo;
use App\Models\Tenant\Customer;
use App\Models\Tenant\DeliveryChannel;
use App\Models\Tenant\DeliveryRider;
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
use App\Services\Security\UserDataScope;
use Illuminate\Http\Request;

class POSController extends Controller
{
    public function index(Request $request)
    {
        $user = auth('tenant')->user();
        $allowedOrderTypes = $user?->effectiveAllowedOrderTypes() ?? array_keys(\App\Models\Tenant\User::ORDER_TYPES);
        $scope = app(UserDataScope::class);
        $branches = $scope->branchesForPos($user);
        abort_if($branches->isEmpty(), 403, 'Your account has no active branch access.');

        $requestedBranchId = (int) $request->branch_id;
        $selectedBranchId = $branches->contains('id', $requestedBranchId)
            ? $requestedBranchId
            : (int) $branches->first()->id;

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
                ->whereIn('branch_id', $branches->pluck('id'))
                ->find($request->held_sale_id);
        }

        $tableSession = null;

        if ($request->filled('table_session_id')) {
            $tableSession = RestaurantTableSession::with(['table.floor', 'waiter', 'salesOrders' => fn ($query) => $query->where('status', 'held')])
                ->whereIn('status', ['open', 'bill_requested'])
                ->whereIn('branch_id', $branches->pluck('id'))
                ->find($request->table_session_id);
        }

        if (!$tableSession && $heldSale?->restaurant_table_session_id) {
            $tableSession = RestaurantTableSession::with(['table.floor', 'waiter', 'salesOrders' => fn ($query) => $query->where('status', 'held')])
                ->whereIn('status', ['open', 'bill_requested'])
                ->find($heldSale->restaurant_table_session_id);
        }

        $requestedMode = $tableSession || $heldSale?->restaurant_table_session_id
            ? 'dine_in'
            : $request->input('mode', $user?->effectiveDefaultOrderType() ?? 'quick_sale');
        if (!in_array($requestedMode, $allowedOrderTypes, true)) {
            $requestedMode = $user?->effectiveDefaultOrderType() ?? ($allowedOrderTypes[0] ?? 'quick_sale');
        }
        if (($tableSession || $heldSale?->restaurant_table_session_id) && !in_array('dine_in', $allowedOrderTypes, true)) {
            abort(403, 'Your account is not allowed to use Dine In orders.');
        }

        $floors = $this->loadBoardFloors($selectedBranchId);

        $waiters = RestaurantWaiter::where(function ($query) use ($selectedBranchId) {
                $query->whereNull('branch_id')
                    ->orWhere('branch_id', $selectedBranchId);
            })
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $deliveryChannels = DeliveryChannel::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // All active riders; POS JS filters by the selected branch (data-branch attr).
        $deliveryRiders = DeliveryRider::where('status', 'active')
            ->orderBy('name')
            ->get();

        $products = Product::with([
                'category',
                'unit',
                'defaultVariant',
                'variants.barcodes',
                'barcodes',
                'branchPrices',
                'modifierGroups.modifiers',
                'modifierGroups.branch',
                // Recipe data drives proactive "makeable" availability for service products.
                'activeRecipe.ingredients.product.unit',
                'activeRecipe.ingredients.unit',
                'activeRecipe.ingredients.variant',
            ])
            ->where('status', 'active')
            ->where('is_sellable', true)
            ->where('is_pos_visible', true)   // PRODUCT-BOUNDARY-2: hide manufacturing/internal items
            // KHATRI-MENU-2: explicit small→large tile ordering, name as tiebreaker.
            ->orderBy('sort_order')
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
            $modifierGroups = $product->modifierGroups
                ? $product->modifierGroups->map(function ($group) {
                    return [
                        'id'          => (int) $group->id,
                        'branch_id'   => $group->branch_id ? (int) $group->branch_id : null,
                        'name'        => $group->name,
                        'min_select'  => (int) $group->min_select,
                        'max_select'  => $group->max_select !== null ? (int) $group->max_select : null,
                        'is_required' => (bool) $group->is_required,
                        'sort_order'  => (int) ($group->pivot?->sort_order ?? $group->sort_order ?? 0),
                        'modifiers'   => $group->modifiers
                            ->where('status', 'active')
                            ->map(fn ($modifier) => [
                                'id'                => (int) $modifier->id,
                                'name'              => $modifier->name,
                                'price_delta'       => (float) $modifier->price_delta,
                                'linked_product_id' => $modifier->linked_product_id ? (int) $modifier->linked_product_id : null,
                                'is_default'        => (bool) $modifier->is_default,
                                'sort_order'        => (int) $modifier->sort_order,
                            ])
                            ->values(),
                    ];
                })->values()
                : collect();

            return [
                'id'                => (int) $product->id,
                'name'              => $product->name,
                'sku'               => $product->sku,
                // POS-UX-1: tile image (public disk URL) — null keeps initials avatar.
                'image_url'         => $product->image_path ? asset('storage/' . $product->image_path) : null,
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
                'modifier_groups'   => $modifierGroups,
                'variants'          => $variants,
                'stock_by_branch'   => $stockMap,
                'is_recipe'                     => $recipe['is_recipe'],
                'makeable_by_branch'            => $recipe['makeable'],
                'limiting_ingredient_by_branch' => $recipe['limiting'],
            ];
        })->values();

        $combosPayload = Combo::with(['components.product.unit', 'components.variant'])
            ->where('status', 'active')
            ->where(function ($query) use ($selectedBranchId) {
                $query->whereNull('branch_id')->orWhere('branch_id', $selectedBranchId);
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (Combo $combo) {
                $components = $combo->components
                    ->filter(fn ($component) => $component->product)
                    ->map(fn ($component) => [
                        'id' => (int) $component->id,
                        'product_id' => (int) $component->product_id,
                        'product_variant_id' => $component->product_variant_id ? (int) $component->product_variant_id : null,
                        'product_name' => $component->product?->name,
                        'variant_name' => $component->variant?->name,
                        'unit_code' => $component->product?->unit?->code,
                        'quantity' => (float) $component->quantity,
                        'sort_order' => (int) $component->sort_order,
                    ])
                    ->values();

                return [
                    'id' => (int) $combo->id,
                    'branch_id' => $combo->branch_id ? (int) $combo->branch_id : null,
                    'code' => $combo->code,
                    'name' => $combo->name,
                    'price' => (float) $combo->price,
                    'sort_order' => (int) $combo->sort_order,
                    'description' => $combo->description,
                    'header_product_id' => (int) ($components->first()['product_id'] ?? 0),
                    'components' => $components,
                ];
            })
            ->filter(fn ($combo) => $combo['header_product_id'] > 0 && count($combo['components']) > 0)
            ->values();

        return view('tenant.pos.index', [
            'branches'         => $branches,
            'selectedBranchId' => $selectedBranchId,
            'terminals'        => $scope->terminalsForPos($user, $branches->pluck('id')->map(fn ($id) => (int) $id)->all()),
            // CUSTOMER-UX-1: customers are looked up on demand via /ajax/customers — rendering
            // the whole book into the page hung the POS once a tenant passed a few hundred rows.
            'categories'       => Category::with('children')
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'productsPayload'  => $productsPayload,
            'combosPayload'    => $combosPayload,
            'paymentMethods'   => PaymentMethod::where('is_active', true)
                ->orderByRaw("CASE WHEN method_type = 'cash' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get(),
            'floors'              => $floors,
            'waiters'             => $waiters,
            'deliveryChannels'    => $deliveryChannels,
            'deliveryRiders'      => $deliveryRiders,
            'allowedOrderTypes'   => $allowedOrderTypes,
            'tableSession'        => $tableSession,
            'heldSale'            => $heldSale,
            // Per-branch RECEIPT layout so the Current Cart Preview matches the receipt look.
            'receiptLayouts'      => \App\Models\Tenant\ReceiptLayoutSetting::where('document_type', 'receipt')
                ->get()
                ->keyBy('branch_id')
                ->map(fn ($l) => [
                    'header_text'      => $l->header_text,
                    'footer_text'      => $l->footer_text,
                    'paper_size'       => $l->paper_size,
                    'show_branch_name' => (bool) $l->show_branch_name,
                ]),
            'terminalPrintConfig' => TerminalPrinterSetting::all()
                ->keyBy('terminal_id')
                ->map(fn ($s) => [
                    'auto_print_receipt' => (bool) $s->auto_print_receipt,
                    'auto_print_kot'     => (bool) $s->auto_print_kot,
                ]),
            'activeMode'       => $requestedMode,
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
        $branches = app(UserDataScope::class)->branchesForPos(auth('tenant')->user());
        abort_if($branches->isEmpty(), 403, 'Your account has no active branch access.');
        $requestedBranchId = (int) $request->branch_id;
        $selectedBranchId = $branches->contains('id', $requestedBranchId)
            ? $requestedBranchId
            : (int) $branches->first()->id;

        $tableSession = null;
        if ($request->filled('selected_session_id')) {
            $tableSession = RestaurantTableSession::with(['table.floor', 'waiter'])
                ->whereIn('status', ['open', 'bill_requested'])
                ->where('branch_id', $selectedBranchId)
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

    /**
     * BILL-PREVIEW-PARITY-1 (2026-08-11): the POS "Bill / Preview" used to be hand-built HTML in
     * JavaScript, so it drifted from the real bill. It now renders the SAME receipt template with
     * the SAME saved layout as the printed receipt, from a TRANSIENT (unsaved) sale built out of
     * the current cart — nothing is persisted, no number is issued.
     */
    public function billPreview(Request $request, SalesTotalsService $totalsService)
    {
        $data = $request->validate([
            'branch_id'               => ['required', 'exists:branches,id'],
            'terminal_id'             => ['nullable', 'exists:terminals,id'],
            'order_type'              => ['required', 'in:quick_sale,takeaway,dine_in,delivery'],
            'discount_type'           => ['nullable', 'in:none,fixed,percent'],
            'discount_value'          => ['nullable', 'numeric', 'min:0'],
            'promo_code'              => ['nullable', 'string', 'max:50'],
            'tip_amount'              => ['nullable', 'numeric', 'min:0'],
            'delivery_charge_amount'  => ['nullable', 'numeric', 'min:0'],
            'customer_name'           => ['nullable', 'string', 'max:190'],
            'customer_phone'          => ['nullable', 'string', 'max:50'],
            'delivery_address'        => ['nullable', 'string', 'max:500'],
            'vehicle_number'          => ['nullable', 'string', 'max:50'],
            'lines'                   => ['required', 'array', 'min:1'],
            'lines.*.product_id'      => ['nullable', 'integer'],
            'lines.*.product_name'    => ['nullable', 'string', 'max:190'],
            'lines.*.category_id'     => ['nullable', 'integer'],
            'lines.*.quantity'        => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_amount'      => ['nullable', 'numeric', 'min:0'],
            // Combo + modifier structure (display only) so the preview matches the printed bill.
            'lines.*.client_line_key' => ['nullable', 'string', 'max:190'],
            'lines.*.parent_line_key' => ['nullable', 'string', 'max:190'],
            'lines.*.line_kind'       => ['nullable', 'string', 'max:30'],
            'lines.*.variant_name'    => ['nullable', 'string', 'max:190'],
            'lines.*.modifiers'       => ['nullable', 'array'],
        ]);

        abort_unless(auth('tenant')->user()?->allowsOrderType($data['order_type']), 403);
        app(UserDataScope::class)->assertPosSelection(
            auth('tenant')->user(),
            (int) $data['branch_id'],
            ! empty($data['terminal_id']) ? (int) $data['terminal_id'] : null
        );

        $branch = Branch::findOrFail((int) $data['branch_id']);
        $orderType = (string) $data['order_type'];

        $resolvedLines = collect($data['lines'])
            ->filter(fn ($line) => (float) ($line['quantity'] ?? 0) > 0)
            ->map(fn ($line) => [
                'product_id'      => (int) ($line['product_id'] ?? 0),
                'category_id'     => (int) ($line['category_id'] ?? 0),
                'quantity'        => (float) ($line['quantity'] ?? 0),
                'unit_price'      => (float) ($line['unit_price'] ?? 0),
                'discount_amount' => (float) ($line['discount_amount'] ?? 0),
                'tax_amount'      => (float) ($line['tax_amount'] ?? 0),
            ])->values();

        abort_if($resolvedLines->isEmpty(), 422, 'Cart is empty.');

        $totals = $totalsService->calculate(
            resolvedLines: $resolvedLines->all(),
            discountType:  $data['discount_type'] ?? 'none',
            discountValue: (float) ($data['discount_value'] ?? 0),
            branchId:      $branch->id,
            orderType:     $orderType,
            promoCode:     $data['promo_code'] ?? null,
            tipAmount:     (float) ($data['tip_amount'] ?? 0),
            deliveryCharge: $orderType === 'delivery'
                ? ($branch->delivery_charge_locked
                    ? (float) $branch->default_delivery_charge
                    : (float) ($data['delivery_charge_amount'] ?? 0))
                : 0,
        );

        // Transient sale — never saved. Relations are set in memory so the receipt view renders
        // exactly as it will when the bill is really printed.
        $sale = new \App\Models\Tenant\SalesOrder([
            'branch_id' => $branch->id,
            'terminal_id' => $data['terminal_id'] ?? null,
            'order_type' => $orderType,
            'customer_name' => $data['customer_name'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null,
            'delivery_address' => $orderType === 'delivery' ? ($data['delivery_address'] ?? null) : null,
            'vehicle_number' => $orderType === 'quick_sale' ? ($data['vehicle_number'] ?? null) : null,
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'tax_amount' => $totals['tax_amount'],
            'service_charge_amount' => $totals['service_charge_amount'],
            'delivery_charge_amount' => $totals['delivery_charge_amount'] ?? 0,
            'tip_amount' => $totals['tip_amount'],
            'grand_total' => $totals['grand_total'],
            'paid_amount' => 0,
            'change_amount' => 0,
        ]);
        $sale->sale_no = 'PREVIEW';
        $sale->sale_date = now();
        $sale->setRelation('branch', $branch);
        $sale->setRelation('customer', null);
        $sale->setRelation('createdBy', auth('tenant')->user());
        $sale->setRelation('payments', collect());
        $sale->setRelation('restaurantTable', null);
        $sale->setRelation('restaurantWaiter', null);
        $sale->setRelation('deliveryChannel', null);
        $sale->setRelation('deliveryRider', null);
        $sale->setRelation('shift', null);

        // Display lines are rebuilt from the ORIGINAL payload (not the simplified totals rows) so
        // they carry combo linkage + modifiers — the preview then renders combos and modifiers
        // exactly as the printed bill does. Component rows print name-only under their header.
        $displayLines = collect($data['lines'])->filter(fn ($l) => (float) ($l['quantity'] ?? 0) > 0)->values();
        $products = \App\Models\Tenant\Product::with('unit')
            ->whereIn('id', $displayLines->pluck('product_id')->filter()->all())->get()->keyBy('id');

        // client_line_key -> the id we assign this row, so a component can point at its header.
        $keyToId = [];
        foreach ($displayLines as $index => $l) {
            if (! empty($l['client_line_key'])) {
                $keyToId[$l['client_line_key']] = $index + 1;
            }
        }

        $sale->setRelation('lines', $displayLines->map(function ($line, $index) use ($products, $keyToId) {
            $product = $products->get($line['product_id'] ?? null);
            $qty = (float) ($line['quantity'] ?? 0);
            $price = (float) ($line['unit_price'] ?? 0);
            $saleLine = new \App\Models\Tenant\SalesOrderLine([
                'product_id' => ($line['product_id'] ?? null) ?: null,
                'product_name' => $product?->name ?: ($line['product_name'] ?? 'Item'),
                'variant_name' => $line['variant_name'] ?? null,
                'unit_code' => $product?->unit?->code,
                'line_kind' => $line['line_kind'] ?? 'standard',
                'quantity' => $qty,
                'unit_price' => $price,
                'discount_amount' => (float) ($line['discount_amount'] ?? 0),
                'tax_amount' => (float) ($line['tax_amount'] ?? 0),
                'line_total' => $qty * $price - (float) ($line['discount_amount'] ?? 0) + (float) ($line['tax_amount'] ?? 0),
            ]);
            $saleLine->id = $index + 1;                 // stable key for the component lookup
            $saleLine->parent_sales_order_line_id = ! empty($line['parent_line_key'])
                ? ($keyToId[$line['parent_line_key']] ?? null)
                : null;
            $saleLine->modifiers = $line['modifiers'] ?? [];
            $saleLine->setRelation('product', $product);

            return $saleLine;
        }));

        $layout = \App\Models\Tenant\ReceiptLayoutSetting::where('document_type', 'receipt')
            ->where(function ($q) use ($branch) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            })
            ->orderByDesc('branch_id')
            ->first();

        return response()->json([
            'ok' => true,
            'html' => view('tenant.printing.documents.receipt', [
                'job' => null,
                'salesOrder' => $sale,
                'layout' => $layout,
                'isPreview' => true,
            ])->render(),
        ]);
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
            'delivery_charge_amount'  => ['nullable', 'numeric', 'min:0'],
            'lines'                   => ['nullable', 'array'],
            'lines.*.product_id'      => ['nullable', 'integer'],
            'lines.*.category_id'     => ['nullable', 'integer'],
            'lines.*.quantity'        => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_amount'      => ['nullable', 'numeric', 'min:0'],
        ]);

        if (!auth('tenant')->user()?->allowsOrderType($data['order_type'])) {
            abort(403, 'Your account is not allowed to use this order type.');
        }
        app(UserDataScope::class)->assertPosSelection(auth('tenant')->user(), (int) $data['branch_id'], null);

        $resolvedLines = collect($data['lines'] ?? [])
            ->filter(fn ($line) => (float) ($line['quantity'] ?? 0) > 0)
            ->map(fn ($line) => [
                'product_id'      => (int) ($line['product_id'] ?? 0),
                'category_id'     => (int) ($line['category_id'] ?? 0),
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
            // CUSTOMER-UX-1: locked branches quote their configured default, never client input.
            deliveryCharge: $data['order_type'] === 'delivery'
                ? (($quoteBranch = \App\Models\Tenant\Branch::find((int) $data['branch_id']))?->delivery_charge_locked
                    ? (float) $quoteBranch->default_delivery_charge
                    : (float) ($data['delivery_charge_amount'] ?? 0))
                : 0,
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
            'delivery_charge_amount'     => $totals['delivery_charge_amount'],
            'grand_total'                => $totals['grand_total'],
        ]);
    }

    /**
     * Recent COMPLETED sales for the branch — powers the POS "Completed Orders"
     * modal (reprint receipt/KOT, view). Read-only; never mutates anything.
     */
    public function recentSales(Request $request)
    {
        $branchId = $request->integer('branch_id');
        if ($branchId) {
            app(UserDataScope::class)->assertPosSelection(auth('tenant')->user(), $branchId, null);
        }

        // Only the order types this operator is allowed to run — a delivery counter never sees
        // dine-in/takeaway orders. An optional filter narrows further, but never widens.
        $allowedTypes = auth("tenant")->user()?->effectiveAllowedOrderTypes() ?? [];
        $filterType = (string) $request->input('order_type', '');

        $sales = SalesOrder::with(['customer', 'deliveryRider', 'branch', 'shift'])
            ->where('status', '!=', 'held')
            ->whereNotNull('sale_no')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($allowedTypes, fn ($q) => $q->whereIn('order_type', $allowedTypes))
            ->when($filterType !== '' && in_array($filterType, $allowedTypes, true),
                fn ($q) => $q->where('order_type', $filterType))
            ->tap(fn ($query) => app(UserDataScope::class)->applyToSales($query, auth('tenant')->user()))
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'sales' => $sales->map(function ($s) {
                $printState = $s->direct_pay_print_state;
                $resumePrinting = $printState && (
                    in_array($printState['kot_status'] ?? null, ['pending', 'pending_retry'], true)
                    || in_array($printState['receipt_status'] ?? null, ['pending', 'pending_retry'], true)
                    || in_array($printState['reminder_status'] ?? null, ['pending_retry', 'awaiting_confirmation'], true)
                );

                return [
                'id'             => (int) $s->id,
                'sale_no'        => $s->sale_no,
                'order_type'     => $s->order_type,
                'status'         => $s->status,
                'payment_status' => $s->payment_status,
                'customer'       => $s->customer_name ?: $s->customer?->name ?: 'Walk-in',
                'rider'          => $s->order_type === 'delivery' ? ($s->deliveryRider?->name ?? 'Unassigned') : null,
                'total'          => number_format((float) $s->grand_total, 2),
                'time'           => app(\App\Support\TenantClock::class)->formatSale($s, 'd M, h:i A'),
                'ago'            => optional($s->sale_date ?? $s->created_at)->diffForHumans(),
                'printing'       => $printState ? [
                    'kot' => $printState['kot_status'] ?? null,
                    'receipt' => $printState['receipt_status'] ?? null,
                    'reminder' => $printState['reminder_status'] ?? null,
                    'resume_available' => $resumePrinting,
                ] : null,
                ];
            })->values(),
        ]);
    }
}
