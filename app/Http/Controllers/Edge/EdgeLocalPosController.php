<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Edge\Concerns\ResolvesEdgePosContext;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Combo;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\Product;
use App\Models\Tenant\RestaurantWaiter;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeOperationalBaselineService;
use App\Services\Security\UserDataScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EDGE-LOCAL-POS-1 — the branch_server-only local POS HTTP surface (page, catalogue view-model, terminal, sale).
 *
 * Registered ONLY in routes/edge_runtime.php (absent — genuine 404 — on Cloud), behind `edge.auth`
 * (authenticated local tenant session) + `edge.branch` (bound appliance; request tenant/branch ids can never
 * override the binding). ALL authority comes from EdgeBranchContext + the authenticated principal +
 * EdgeLocalPosService's own transactional revalidation; this controller adds no authority of its own.
 * It never calls Cloud finance/inventory mutators (fenced anyway) and cannot bypass the accepted
 * operational-stock baseline (the service refuses before mutation). activation_ready stays false.
 *
 * W0c (20 Sep 2026): the former single controller is split by team ownership — shifts (EdgeLocalShiftController),
 * tables/reservations (EdgeLocalRestaurantController), held checks (EdgeLocalHeldSalesController), manager approval
 * (EdgeLocalManagerApprovalController), returns (EdgeLocalReturnController), printing (EdgeLocalPrintJobController).
 * The shared authority helpers (selected terminal, route-permission gate, terminal authority, Complete Sale gate)
 * live in Concerns\ResolvesEdgePosContext. Team 2 owns this file (menu + sale).
 */
class EdgeLocalPosController extends Controller
{
    use ResolvesEdgePosContext;

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeLocalPosService $pos,
        private readonly EdgeOperationalBaselineService $baselines,
    ) {
    }

    /**
     * EDGE-CASHIER-UI-1 — render the Branch-Server browser cashier POS.
     *
     * Serves the SAME operator experience as the current Online POS (the locked functional spec) but
     * every mutation the page issues targets the Edge-local JSON APIs (edge.local.pos.*), never a Cloud
     * posting/finance/inventory controller. The view-model is assembled from the bound branch only, using
     * the tenant connection; it is the Edge analogue of Tenant\POSController@index (default terminal,
     * terminal-switch authority, order types, category/deal tabs, grid products, cash payment methods,
     * waiters) so Online→Offline does not feel like a different product. No Vite/build assets — the page
     * is self-contained so it renders on the appliance with no Internet.
     */
    public function screen(Request $request): View
    {
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $branch = Branch::on('tenant')->findOrFail($branchId);
        $user = auth('tenant')->user();
        // ONLINE ROUTE-PERMISSION parity (W0b): the Online POS page is `tenant.pos.index` behind EnsureRoutePermission.
        abort_unless((bool) $user?->can('tenant.pos.index'), 403, 'Permission denied — your account may not open the POS.');

        // DEFAULT-TERMINAL + TERMINAL-SWITCH-AUTH parity: a pinned operator (no change-terminal permission)
        // is offered ONLY his assigned terminal; the page auto-selects the default rather than "first seen".
        $canChangeTerminal = (bool) $user?->can(UserDataScope::CHANGE_TERMINAL_PERMISSION);
        $defaultTerminalId = $user?->default_terminal_id ? (int) $user->default_terminal_id : null;
        $terminals = Terminal::on('tenant')->where('branch_id', $branchId)->where('status', 'active')
            ->orderBy('name')->get(['id', 'code', 'name'])
            ->when(! $canChangeTerminal && $defaultTerminalId,
                fn ($list) => $list->where('id', $defaultTerminalId)->values());

        // Order types: the user's effective set intersected with what Edge can execute offline. All four
        // canonical types can be presented; the sale/held authority refuses anything unsupported.
        $allowedOrderTypes = $user?->effectiveAllowedOrderTypes() ?? array_keys(\App\Models\Tenant\User::ORDER_TYPES);
        $orderTypes = array_values(array_intersect(array_keys(\App\Models\Tenant\User::ORDER_TYPES), $allowedOrderTypes));
        $defaultOrderType = $user?->effectiveDefaultOrderType() ?? ($orderTypes[0] ?? 'quick_sale');
        if (! in_array($defaultOrderType, $orderTypes, true)) {
            $defaultOrderType = $orderTypes[0] ?? 'quick_sale';
        }

        // HIDDEN-PRODUCT-HELD-BILL-1 parity (canonical cba7e09): a product hidden AFTER it landed on an open
        // held/draft bill must still be recallable + payable — Recall reads every line's product out of this
        // payload, so such products ship flagged `hidden` (never on the grid, always resolvable).
        $liveOrderProductIds = DB::connection('tenant')->table('sales_order_lines as l')
            ->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->where('o.branch_id', $branchId)->where('o.status', 'held')
            ->pluck('l.product_id')->map(fn ($id) => (int) $id)->unique()->values();

        // Deal components ride in the payload (grid-hidden) so a deal tile can show its availability the way Online's
        // comboAvailability does (Online keeps combo-component products in its payload for exactly this reason).
        $comboComponentProductIds = DB::connection('tenant')->table('combo_components as cc')
            ->join('combos as c', 'c.id', '=', 'cc.combo_id')->where('c.status', 'active')
            ->where(fn ($q) => $q->whereNull('c.branch_id')->orWhere('c.branch_id', $branchId))
            ->pluck('cc.product_id')->map(fn ($id) => (int) $id)->unique()->values();

        // Grid products: sellable, POS-visible, active and (CATEGORY-BRANCH-SCOPE-1, canonical efe2894) filed
        // under a category this branch may show — the same visibility truth the Online grid uses. The scope
        // sits INSIDE the visible branch so the open-bill escape hatch above is never narrowed by it. The SALE
        // re-validates stock/price/options; the tile payload below mirrors Online POSController@index (W2).
        $products = Product::on('tenant')
            ->with(['category:id,branch_id', 'unit:id,code,name,unit_type', 'variants', 'barcodes'])
            ->where(function ($q) use ($branchId, $liveOrderProductIds, $comboComponentProductIds) {
                $q->where(function ($visible) use ($branchId) {
                    $visible->where('status', 'active')->where('is_sellable', true)->where('is_pos_visible', true)
                        ->where(fn ($scope) => $scope->whereNull('category_id')
                            ->orWhereHas('category', fn ($c) => $c->forBranch($branchId)));
                });
                $escape = $liveOrderProductIds->merge($comboComponentProductIds)->unique()->values();
                if ($escape->isNotEmpty()) {
                    $q->orWhereIn('id', $escape->all());
                }
            })
            ->orderBy('sort_order')->orderBy('name')
            ->get();
        $menu = $this->menuPayload($products, $branch);

        $products = $products->map(fn (Product $p) => array_merge([
                'id' => (int) $p->id,
                'name' => $p->name,
                'category_id' => $p->category_id ? (int) $p->category_id : null,
                'price' => (float) $p->default_selling_price,
                'hidden' => ! ($p->status === 'active' && $p->is_sellable && $p->is_pos_visible
                    && ($p->category === null || $p->category->branch_id === null || (int) $p->category->branch_id === $branchId)),
            ], $menu[(int) $p->id] ?? []))->values();

        // DEAL POS TABS parity: combos are display-only tabs; a deal with a header product + components
        // sells as one line. Category on the combo picks the tab (null = the legacy flat "Deals" pill).
        $productNames = $products->pluck('name', 'id');
        $combos = Combo::on('tenant')->with('components')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (Combo $c) => [
                'id' => (int) $c->id,
                'category_id' => $c->category_id ? (int) $c->category_id : null,
                'code' => $c->code,
                'name' => $c->name,
                'price' => (float) $c->price,
                'component_count' => $c->components->count(),
                // A9: Online combosPayload components — the deal tile's "N items · makes M" / limiting component.
                'components' => $c->components->sortBy('sort_order')->map(fn ($cc) => [
                    'product_id' => (int) $cc->product_id,
                    'product_variant_id' => $cc->product_variant_id ? (int) $cc->product_variant_id : null,
                    'product_name' => $productNames[(int) $cc->product_id] ?? null,
                    'quantity' => (float) $cc->quantity,
                ])->values(),
            ])
            ->filter(fn ($c) => $c['component_count'] > 0)
            ->values();

        // CATEGORY-BRANCH-SCOPE-1: a category may belong to one branch (NULL = every branch). Children are the ACTIVE
        // child categories this branch may show — the Online child strip (A3).
        $categories = Category::on('tenant')
            ->with(['children' => fn ($q) => $q->where('is_active', true)->forBranch($branchId)->select(['id', 'parent_id', 'name', 'sort_order'])])
            ->forBranch($branchId)
            ->whereNull('parent_id')->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'sort_order']);
        // POS-COMBO-CATEGORY-1 + HIDE-EMPTY-TABS + EMPTY-DEAL-PILL-1 (Online POSController@index): pills only for categories
        // (self or a child) holding a grid-visible product or a deal; the flat "Deals" pill only while uncategorised deals exist.
        $contentCategoryIds = $products->where('hidden', false)->pluck('category_id')
            ->merge($combos->pluck('category_id'))->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $pillCategoryIds = $categories->filter(fn ($parent) => collect([$parent->id])->merge($parent->children->pluck('id'))
            ->map(fn ($id) => (int) $id)->intersect($contentCategoryIds)->isNotEmpty())
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $hasUncategorizedCombos = $combos->contains(fn ($c) => empty($c['category_id']));

        $waiters = RestaurantWaiter::on('tenant')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->where('status', 'active')->orderBy('name')->get(['id', 'name']);

        $page = [
            'branchId' => $branchId,
            'branchName' => $branch->name,
            'userName' => $user?->name,
            'terminals' => $terminals->values(),
            'defaultTerminalId' => $defaultTerminalId,
            'canChangeTerminal' => $canChangeTerminal,
            'orderTypes' => $orderTypes,
            'defaultOrderType' => $defaultOrderType,
            'orderTypeLabels' => \App\Models\Tenant\User::ORDER_TYPES,
            'categories' => $categories,
            'pillCategoryIds' => $pillCategoryIds,
            'contentCategoryIds' => $contentCategoryIds->all(),
            'hasUncategorizedCombos' => $hasUncategorizedCombos,
            'products' => $products,
            'combos' => $combos,
            'waiters' => $waiters,
            // NEGATIVE-STOCK-SETTING-1B parity: Online tiles/adds read the branch allow_negative_stock (Backorder vs Out).
            'allowNegativeStock' => (bool) $branch->allow_negative_stock,
            'paymentMethods' => PaymentMethod::on('tenant')->where('is_active', true)
                ->where('method_type', 'cash')->orderBy('name')->get(['id', 'code', 'name']),
            // A20/A21: the Online payment_method select lists every active method (cash first). Edge takes CASH only: card /
            // provider = accepted ONLINE_REQUIRED; bank transfer / cheque / other = OWNER DECISION — listed, disabled, labelled.
            'tenderMethods' => PaymentMethod::on('tenant')->where('is_active', true)
                ->orderByRaw("CASE WHEN method_type = 'cash' THEN 0 ELSE 1 END")->orderBy('name')
                ->get(['id', 'code', 'name', 'method_type'])
                ->map(fn ($m) => ['id' => (int) $m->id, 'name' => $m->name, 'method_type' => $m->method_type,
                    'offline' => $m->method_type === 'cash',
                    'hint' => $m->method_type === 'cash' ? null : (in_array($m->method_type, ['card', 'wallet', 'provider', 'credit'], true)
                        ? 'Card / provider payments run on the Online POS (accepted Cloud-only).'
                        : 'Awaiting owner decision — offline ' . str_replace('_', ' ', (string) $m->method_type) . ' is not enabled on the Branch Server.')])
                ->values(),
            // A17 contract boundary: tips are quoted but a paid Branch Server sale cannot carry one until the envelope does.
            'tipsSyncable' => EdgeLocalPosService::TIPS_SYNC_CONTRACT_READY,
            'operationalStockReady' => $this->baselines->currentAccepted() !== null,
            // COMPLETE SALE PERMISSION parity: the button follows tenant.pos.store; the server enforces it too.
            'canCompleteSale' => (bool) $user?->can('tenant.pos.store'),
            // ONLINE BUTTON-GATING parity (audit R3.1 / R5.1 / A40): Online hides Return / Quick Report / approval entry points
            // without the permission; the server enforces the same keys (EdgeLocalReturnController, EdgeQuickReportController).
            'canSalesReturn' => (bool) $user?->can('tenant.sales-returns.store'),
            'canQuickReport' => (bool) $user?->can('tenant.pos.quick-report-send'),
            'canRequestManagerApproval' => (bool) $user?->can('tenant.api.manager-approvals.verify'),
            'canChangeOrderDetails' => (bool) $user?->can('tenant.held-sales.store'),
            // Team 3 request — table / lifecycle button gating, the Online route permission of each action (server re-checks).
            'canOpenTable' => (bool) $user?->can('tenant.restaurant.table-sessions.open'),
            'canCloseTable' => (bool) $user?->can('tenant.restaurant.table-sessions.close'),
            'canRequestBill' => (bool) $user?->can('tenant.restaurant.table-sessions.bill-requested'),
            'canMoveTable' => (bool) $user?->can('tenant.restaurant.table-sessions.move'),
            'canMergeTables' => (bool) $user?->can('tenant.restaurant.table-sessions.merge'),
            'canViewSession' => (bool) $user?->can('tenant.restaurant.table-sessions.show'),
            'canReattachTable' => (bool) $user?->can('tenant.held-sales.reattach-table'),
            'canCancelHeld' => (bool) $user?->can('tenant.held-sales.cancel'),
            'canSplitBill' => (bool) $user?->can('tenant.sales-orders.split-bill.store'),
            // F2 SUPPLIER FINANCE parity: the header entry points follow the Online permissions; the server enforces them too.
            'canSupplierFinance' => (bool) ($user?->can(\App\Services\Edge\EdgeLocalSupplierFinanceService::PERM_LEDGER) || $user?->can(\App\Services\Edge\EdgeLocalSupplierFinanceService::PERM_PAYMENT)),
            'canManualJournal' => (bool) $user?->can(\App\Services\Edge\EdgeLocalSupplierFinanceService::PERM_JOURNAL),
            // F3 PURCHASE RETURN parity: the header entry point follows the Online permissions; the server enforces them too.
            'canPurchaseReturn' => (bool) ($user?->can(\App\Services\Edge\EdgeLocalPurchaseReturnService::PERM_STORE) || $user?->can(\App\Services\Edge\EdgeLocalPurchaseReturnService::PERM_POST)),
            // DELIVERY / DISCOUNT parity data (synced): channels, riders, the branch charge lock, approval mode.
            'deliveryChannels' => \App\Models\Tenant\DeliveryChannel::on('tenant')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'type']),
            'deliveryRiders' => \App\Models\Tenant\DeliveryRider::on('tenant')->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))->orderBy('name')->get(['id', 'name']),
            'deliveryChargeLocked' => (bool) $branch->delivery_charge_locked,
            'defaultDeliveryCharge' => (float) ($branch->default_delivery_charge ?? 0),
            'manualDiscountNeedsManager' => ($branch->manual_discount_approval_mode ?? Branch::MANUAL_DISCOUNT_MANAGER_REQUIRED) !== Branch::MANUAL_DISCOUNT_AUTO_APPROVE,
        ];
        // W0: the bootstrap view-model the page's JS reads (`#edge-pos-data`) — built here, not in Blade, so the
        // control-census and any future partial see ONE definition. Header-only flags (finance entry points) stay out.
        $page['vm'] = Arr::only($page, [
            'branchId', 'branchName', 'userName', 'terminals', 'defaultTerminalId', 'canChangeTerminal',
            'orderTypes', 'defaultOrderType', 'orderTypeLabels', 'categories', 'products', 'combos', 'waiters',
            'pillCategoryIds', 'contentCategoryIds', 'hasUncategorizedCombos', 'allowNegativeStock', 'tenderMethods', 'tipsSyncable',
            'paymentMethods', 'operationalStockReady', 'canCompleteSale',
            'canSalesReturn', 'canQuickReport', 'canRequestManagerApproval', 'canChangeOrderDetails',
            'canOpenTable', 'canCloseTable', 'canRequestBill', 'canMoveTable', 'canMergeTables', 'canViewSession', 'canReattachTable', 'canCancelHeld', 'canSplitBill',
            'deliveryChannels', 'deliveryRiders', 'deliveryChargeLocked', 'defaultDeliveryCharge', 'manualDiscountNeedsManager',
        ]);

        return view('edge.pos.index', $page);
    }

    /**
     * W2 — the Online tile payload (POSController@index productsPayload) built from the SYNCED menu on the appliance:
     * sku / image / unit + measurable flags (A6) / tax / branch-resolved price (the SAME SalePricingService rule the sale
     * charges) / product + variant barcodes (A5) / variants with their own price, sku, barcodes and stock (A8) / modifier
     * groups with active options (A7) / stock from the Edge OPERATIONAL balances of the accepted baseline (A4 — the stock
     * this appliance actually refuses on). Keyed by product id; merged into the grid rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function menuPayload(\Illuminate\Support\Collection $products, Branch $branch): array
    {
        $branchId = (int) $branch->id;
        $pricing = app(\App\Services\Sales\SalePricingService::class);
        $baseline = $this->baselines->currentAccepted();
        $stock = [];
        if ($baseline) {
            DB::connection('tenant')->table('edge_operational_stock_balances')->where('baseline_id', $baseline->id)
                ->get(['product_id', 'product_variant_id', 'quantity_on_hand'])
                ->each(function ($b) use (&$stock) {
                    $stock[(int) $b->product_id . '-' . (int) ($b->product_variant_id ?? 0)] = (float) $b->quantity_on_hand;
                });
        }
        $ids = $products->pluck('id')->all() ?: [0];
        // Modifier groups attached to these products that this branch may use (Online activeModifierGroups).
        $groupRows = DB::connection('tenant')->table('product_modifier_group as pmg')
            ->join('modifier_groups as g', 'g.id', '=', 'pmg.modifier_group_id')
            ->whereIn('pmg.product_id', $ids)->where('g.status', 'active')
            ->where(fn ($q) => $q->whereNull('g.branch_id')->orWhere('g.branch_id', $branchId))
            ->orderBy('pmg.sort_order')->orderBy('g.sort_order')
            ->get(['pmg.product_id', 'pmg.sort_order as pivot_sort', 'g.id', 'g.branch_id', 'g.name', 'g.min_select', 'g.max_select', 'g.is_required', 'g.sort_order']);
        $options = DB::connection('tenant')->table('modifiers')->whereIn('modifier_group_id', $groupRows->pluck('id')->unique()->all() ?: [0])
            ->where('status', 'active')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'modifier_group_id', 'name', 'price_delta', 'linked_product_id', 'is_default', 'sort_order'])->groupBy('modifier_group_id');
        $groupsByProduct = $groupRows->groupBy('product_id');

        $qtyFor = function (Product $p, ?int $variantId) use ($stock) {
            if (! ($p->inventory_consumption_method === 'stock_item' && $p->is_stock_tracked)) {
                return null; // recipe / service / none — the Edge refusal is not a plain on-hand count (see stock_kind)
            }

            return (float) ($stock[$p->id . '-' . ($variantId ?? 0)] ?? 0);
        };

        $out = [];
        foreach ($products as $p) {
            $variants = $p->variants->filter(fn ($v) => (bool) ($v->is_active ?? true))
                ->sortBy(fn ($v) => ($v->is_default ? '0' : '1') . sprintf('%09d', $v->id))->values();
            $barcodes = $p->barcodes;
            $default = $variants->firstWhere('is_default', true);
            $unitType = $p->unit?->unit_type ?? 'quantity';
            $measurable = $p->unit !== null && $unitType !== 'quantity';
            $groups = ($groupsByProduct->get($p->id) ?? collect())->map(fn ($g) => [
                'id' => (int) $g->id,
                'branch_id' => $g->branch_id ? (int) $g->branch_id : null,
                'name' => $g->name,
                'min_select' => (int) $g->min_select,
                'max_select' => $g->max_select !== null ? (int) $g->max_select : null,
                'is_required' => (bool) $g->is_required,
                'sort_order' => (int) $g->pivot_sort,
                'modifiers' => ($options->get($g->id) ?? collect())->map(fn ($m) => [
                    'id' => (int) $m->id, 'name' => $m->name, 'price_delta' => (float) $m->price_delta,
                    'linked_product_id' => $m->linked_product_id ? (int) $m->linked_product_id : null,
                    'is_default' => (bool) $m->is_default, 'sort_order' => (int) $m->sort_order,
                ])->values(),
            ])->filter(fn ($g) => count($g['modifiers']) > 0)->values();
            $imageUrl = null;
            if ($p->image_path && is_file(public_path('storage/' . ltrim((string) $p->image_path, '/')))) {
                $imageUrl = asset('storage/' . ltrim((string) $p->image_path, '/')); // only a file that is ON the appliance
            }

            $out[(int) $p->id] = [
                'sku' => $p->sku,
                'image_url' => $imageUrl,
                'unit_code' => $p->unit?->code,
                'unit_type' => $unitType,
                'allow_decimal_qty' => $measurable,
                'quantity_step' => $measurable ? 0.001 : 1,
                'is_stock_tracked' => (bool) $p->is_stock_tracked,
                'stock_kind' => ($p->inventory_consumption_method === 'stock_item' && $p->is_stock_tracked) ? 'tracked'
                    : ($p->inventory_consumption_method === 'recipe' ? 'recipe' : 'service'),
                'stock' => $qtyFor($p, $default?->id),
                'is_taxable' => (bool) ($p->is_taxable ?? false),
                'tax_rate_percent' => (float) ($p->tax_rate_percent ?? 0),
                // The price the SALE will charge (branch price → default variant → catalog), not a display guess.
                'price' => (float) $pricing->resolveSellingPrice($p, $default, $branchId, null),
                'default_variant_id' => $default?->id ? (int) $default->id : null,
                'barcodes' => $barcodes->whereNull('product_variant_id')->pluck('barcode')->filter()->map(fn ($b) => (string) $b)->values(),
                'variants' => $variants->map(fn ($v) => [
                    'id' => (int) $v->id,
                    'name' => $v->name,
                    'sku' => $v->sku,
                    'is_default' => (bool) $v->is_default,
                    'price' => (float) $pricing->resolveSellingPrice($p, $v, $branchId, null),
                    'stock' => $qtyFor($p, (int) $v->id),
                    'barcodes' => $barcodes->where('product_variant_id', $v->id)->pluck('barcode')
                        ->push($v->barcode)->filter()->map(fn ($b) => (string) $b)->unique()->values(),
                ])->values(),
                'modifier_groups' => $groups,
            ];
        }

        return $out;
    }

    /** ONLINE-POS PARITY — Preview Bill: the running bill on the same sale truth, ZERO mutation. */
    public function previewBill(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Online validates order_type `in:` the four canonical types (SalesOrderController::validateSale).
            'order_type' => ['nullable', 'string', 'in:quick_sale,takeaway,dine_in,delivery'],
            'discount_type' => ['nullable', 'string'],
            'discount_value' => ['nullable', 'numeric'],
            'promo_code' => ['nullable', 'string', 'max:50'],
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
            'manager_approval_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'delivery_channel_id' => ['nullable', 'integer'],
            'delivery_rider_id' => ['nullable', 'integer'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_charge_amount' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required_without:lines.*.combo_id', 'nullable', 'integer'],
            'lines.*.combo_id' => ['nullable', 'integer'],
            'lines.*.product_variant_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.modifiers' => ['nullable', 'array'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.kitchen_note' => ['nullable', 'string', 'max:500'],
        ]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        try {
            $preview = $this->pos->previewBill($data, auth('tenant')->user(), $terminal->id);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($preview);
    }

    /** Terminal-selection data: the bound branch's active terminals + open-shift state + current selection. */
    public function terminals(Request $request): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $terminals = Terminal::on('tenant')->where('branch_id', $branchId)->where('status', 'active')
            ->orderBy('name')->get()->map(function (Terminal $t) {
                $open = Shift::on('tenant')->where('terminal_id', $t->id)->where('status', 'open')->latest('id')->first();

                return [
                    'id' => $t->id,
                    'code' => $t->code,
                    'name' => $t->name,
                    'open_shift_id' => $open?->id,
                    'open_shift_uuid' => $open?->shift_uuid,
                    'business_date' => $open?->business_date?->toDateString(),
                ];
            })->values();

        // (slice 1.1) never echo a stale selection: if the stored terminal is gone/inactive/wrong-branch,
        // clear it so the UX starts from "select a terminal" (EdgeLocalPosService stays the sale authority).
        $selectedId = (int) $request->session()->get(self::TERMINAL_SESSION_KEY, 0);
        if ($selectedId > 0 && ! $terminals->contains(fn ($t) => (int) $t['id'] === $selectedId)) {
            $request->session()->forget(self::TERMINAL_SESSION_KEY);
            $selectedId = 0;
        }

        return response()->json([
            'branch_id' => $branchId,
            'terminals' => $terminals,
            'selected_terminal_id' => $selectedId > 0 ? $selectedId : null,
            'operational_stock_ready' => $this->baselines->currentAccepted() !== null,
            'payment_methods' => PaymentMethod::on('tenant')->where('is_active', true)->where('method_type', 'cash')
                ->get(['id', 'code', 'name', 'method_type']),
        ]);
    }

    /** Select the operating terminal for this session (must belong to the bound branch, be active, and be the operator's). */
    public function selectTerminal(Request $request): JsonResponse
    {
        $data = $request->validate(['terminal_id' => ['required', 'integer']]);
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $terminal = Terminal::on('tenant')->where('id', (int) $data['terminal_id'])
            ->where('branch_id', $branchId)->where('status', 'active')->first();
        if (! $terminal) {
            return response()->json(['message' => 'Select an active terminal on this branch.'], 422);
        }
        // TERMINAL-SWITCH-AUTH + assignment parity (W0b, Online UserDataScope): a pinned operator (no change-terminal
        // permission, default terminal set) may only work on that terminal; a terminal-assigned operator only on
        // an assigned terminal. Enforced HERE (not only hidden in the page) and re-checked on every use.
        if ($denied = $this->denyUnlessMayOperateTerminal($terminal)) {
            return $denied;
        }
        $request->session()->put(self::TERMINAL_SESSION_KEY, $terminal->id);

        return response()->json(['selected_terminal_id' => $terminal->id]);
    }

    /** Local paid Direct Pay (quick_sale/takeaway, cash) through EdgeLocalPosService. */
    public function storeSale(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Online SalesOrderController::validateSale: order_type `in:` the four canonical types.
            'order_type' => ['required', 'string', 'in:quick_sale,takeaway,dine_in,delivery'],
            'client_uuid' => ['required', 'string', 'max:36'],
            'discount_type' => ['nullable', 'string'],
            'discount_value' => ['nullable', 'numeric'],
            'promo_code' => ['nullable', 'string', 'max:50'],
            // A17 (Online `tip_amount` min:0) — refused on a paid sale until the sync contract carries it (see the service).
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
            // Online Direct Pay print intents (tenant.pos.store) — persisted as the shared DirectPayPrintOrchestrator state.
            'kot_print_intent' => ['nullable', 'in:print,skip'],
            'receipt_print_intent' => ['nullable', 'in:print,skip'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'manager_approval_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'delivery_channel_id' => ['nullable', 'integer'],
            'delivery_rider_id' => ['nullable', 'integer'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_charge_amount' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required_without:lines.*.combo_id', 'nullable', 'integer'],
            'lines.*.combo_id' => ['nullable', 'integer'],
            'lines.*.product_variant_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.modifiers' => ['nullable', 'array'],
            // W2 (A10): Online accepts lines.*.discount_amount (min:0); per-line kitchen note → sales_order_lines.kitchen_note.
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.kitchen_note' => ['nullable', 'string', 'max:500'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.tendered_amount' => ['nullable', 'numeric'],
            // A20 Online "Reference / Card / Bank" field (payments.*.transaction_ref max:190) — stored on the payment + envelope.
            'payments.*.transaction_ref' => ['nullable', 'string', 'max:190'],
            // PHASE 2b parity: a Quick Sale requires vehicle + waiter (same required_if as the Cloud POS).
            'vehicle_number' => ['nullable', 'string', 'max:50', 'required_if:order_type,quick_sale'],
            'restaurant_waiter_id' => ['nullable', 'integer', 'required_if:order_type,quick_sale'],
        ]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        if ($denied = $this->denyUnlessMayCompleteSale()) {
            return $denied;
        }

        try {
            $sale = $this->pos->completePaidSale($data, auth('tenant')->user(), $terminal->id);
        } catch (\App\Exceptions\SaleIdempotencyConflictException $e) {
            throw $e; // renders itself (409 conflict / 503 pending) — must NOT collapse into a generic 422
        } catch (RuntimeException $e) {
            // controlled operational refusal (no baseline / stock / conversion / shift) — never a 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }
        // T2-1 (Team 5): Direct Pay printing AFTER the paid sale committed (and on an idempotent replay) — the shared orchestrator
        // state drives it; a printing failure is recorded as retryable state and NEVER unwinds the sale (Online semantics).
        $printing = null;
        if ($sale->direct_pay_print_state && class_exists(\App\Services\Edge\EdgeLocalPrintDirectPayService::class)) {
            try {
                $printing = app(\App\Services\Edge\EdgeLocalPrintDirectPayService::class)
                    ->afterPaidSale($sale, $sale->direct_pay_print_state['kot_intent'] ?? null, $sale->direct_pay_print_state['receipt_intent'] ?? null);
            } catch (\Throwable $e) {
                report($e);
                $printing = null; // the page falls back to print_intents → POST /sales/{sale}/printing/retry
            }
        }

        return response()->json([
            'printing' => $printing,
            'sale_id' => $sale->id,
            'sale_no' => $sale->sale_no,
            'sale_uuid' => $sale->sale_uuid,
            'status' => $sale->status,
            'grand_total' => (float) $sale->grand_total,
            'paid_amount' => (float) $sale->paid_amount,
            'change_amount' => (float) $sale->payments()->first()?->change_amount,
            'edge_sync_state' => $sale->edge_sync_state,
            // Online parity: the page follows the chosen Direct Pay print intents (Team 5 drives KOT/receipt from these).
            'print_intents' => $sale->direct_pay_print_state
                ? ['kot' => $sale->direct_pay_print_state['kot_intent'] ?? null, 'receipt' => $sale->direct_pay_print_state['receipt_intent'] ?? null]
                : null,
        ], 201);
    }

    /**
     * CUSTOMER-UX parity: customers are looked up on demand (never the whole book in the page) from the SYNCED
     * customer book, with their saved addresses (ADDRESS-ATTACH: picking one attaches it to the order).
     */
    public function customers(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $id = (int) $request->input('id', 0);
        if ($id <= 0 && mb_strlen($q) < 2) {
            return response()->json(['customers' => []]);
        }
        // Team 1 request: `?id=N` = the exact customer (the Online `/pos?customer_id=` deep link preselects one).
        $rows = \App\Models\Tenant\Customer::on('tenant')->where('status', 'active')
            ->when($id > 0, fn ($w) => $w->whereKey($id), fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'phone', 'address']);
        $addresses = \App\Models\Tenant\CustomerAddress::on('tenant')->whereIn('customer_id', $rows->pluck('id'))
            ->orderByDesc('is_default')->orderBy('id')->get(['id', 'customer_id', 'label', 'address', 'is_default'])->groupBy('customer_id');

        return response()->json(['customers' => $rows->map(fn ($c) => [
            'id' => (int) $c->id, 'name' => $c->name, 'phone' => $c->phone, 'address' => $c->address,
            'addresses' => ($addresses->get($c->id) ?? collect())->map(fn ($a) => ['id' => (int) $a->id, 'label' => $a->label, 'address' => $a->address, 'is_default' => (bool) $a->is_default])->values(),
        ])->values()]);
    }

    /**
     * Business-friendly sync state for the cashier header ("Pending sync: N"). Deliberately exposes NO
     * engineering internals (no lease, hash, activation epoch, baseline uuid) — those live on the operator
     * sync console, not the till.
     */
    public function syncSummary(): JsonResponse
    {
        $snap = app(\App\Services\Edge\EdgeSyncStatusService::class)->snapshot();
        $outbox = $snap['outbox'] ?? [];
        $pending = (int) ($outbox['pending'] ?? 0) + (int) ($outbox['leased'] ?? 0);
        $attention = (int) ($outbox['failed_permanent'] ?? 0);

        $authority = app(\App\Services\Edge\EdgeAuthorityService::class)->cashierState();

        return response()->json([
            'pending_sales' => $pending,
            'needs_attention' => $attention,
            'last_synced_at' => $snap['last_ack_at'] ?? null,
            // P0 lease + Q state machine: the connection state the cashier should see (ONLINE / INTERNET CONNECTION
            // UNSTABLE / INTERNET CONNECTION LOST / PREPARING LOCAL MODE / LOCAL MODE ACTIVE / CONNECTION RESTORED /
            // SYNCHRONIZING / RETURNING TO ONLINE) — labels only, no internals.
            'connection' => $authority['label'],
            'state' => $attention > 0 ? 'attention' : ($pending > 0 ? 'pending' : 'up_to_date'),
            'message' => $attention > 0
                ? 'Some sales need attention before they can sync — tell your manager.'
                : ($pending > 0 ? "{$pending} sale(s) waiting to sync — they are saved here and will sync when the connection returns." : 'All sales synced.'),
        ]);
    }
}
