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

        // Grid products: sellable, POS-visible, active and (CATEGORY-BRANCH-SCOPE-1, canonical efe2894) filed
        // under a category this branch may show — the same visibility truth the Online grid uses. The scope
        // sits INSIDE the visible branch so the open-bill escape hatch above is never narrowed by it. The SALE
        // re-validates stock/price; per-tile availability/variants/modifiers land in a later milestone.
        $products = Product::on('tenant')->with('category:id,branch_id')
            ->where(function ($q) use ($branchId, $liveOrderProductIds) {
                $q->where(function ($visible) use ($branchId) {
                    $visible->where('status', 'active')->where('is_sellable', true)->where('is_pos_visible', true)
                        ->where(fn ($scope) => $scope->whereNull('category_id')
                            ->orWhereHas('category', fn ($c) => $c->forBranch($branchId)));
                });
                if ($liveOrderProductIds->isNotEmpty()) {
                    $q->orWhereIn('id', $liveOrderProductIds->all());
                }
            })
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'category_id', 'default_selling_price', 'status', 'is_sellable', 'is_pos_visible'])
            ->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'name' => $p->name,
                'category_id' => $p->category_id ? (int) $p->category_id : null,
                'price' => (float) $p->default_selling_price,
                'hidden' => ! ($p->status === 'active' && $p->is_sellable && $p->is_pos_visible
                    && ($p->category === null || $p->category->branch_id === null || (int) $p->category->branch_id === $branchId)),
            ])->values();

        // DEAL POS TABS parity: combos are display-only tabs; a deal with a header product + components
        // sells as one line. Category on the combo picks the tab (null = the legacy flat "Deals" pill).
        $combos = Combo::on('tenant')->with('components:id,combo_id,product_id')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (Combo $c) => [
                'id' => (int) $c->id,
                'category_id' => $c->category_id ? (int) $c->category_id : null,
                'name' => $c->name,
                'price' => (float) $c->price,
                'component_count' => $c->components->count(),
            ])
            ->filter(fn ($c) => $c['component_count'] > 0)
            ->values();

        // CATEGORY-BRANCH-SCOPE-1: a category may belong to one branch (NULL = every branch).
        $categories = Category::on('tenant')->with('children:id,parent_id,name,sort_order')
            ->forBranch($branchId)
            ->whereNull('parent_id')->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'sort_order']);

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
            'products' => $products,
            'combos' => $combos,
            'waiters' => $waiters,
            'paymentMethods' => PaymentMethod::on('tenant')->where('is_active', true)
                ->where('method_type', 'cash')->orderBy('name')->get(['id', 'code', 'name']),
            'operationalStockReady' => $this->baselines->currentAccepted() !== null,
            // COMPLETE SALE PERMISSION parity: the button follows tenant.pos.store; the server enforces it too.
            'canCompleteSale' => (bool) $user?->can('tenant.pos.store'),
            // ONLINE BUTTON-GATING parity (audit R3.1 / R5.1 / A40): Online hides Return / Quick Report / approval entry points
            // without the permission; the server enforces the same keys (EdgeLocalReturnController, EdgeQuickReportController).
            'canSalesReturn' => (bool) $user?->can('tenant.sales-returns.store'),
            'canQuickReport' => (bool) $user?->can('tenant.pos.quick-report-send'),
            'canRequestManagerApproval' => (bool) $user?->can('tenant.api.manager-approvals.verify'),
            'canChangeOrderDetails' => (bool) $user?->can('tenant.held-sales.store'),
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
            'paymentMethods', 'operationalStockReady', 'canCompleteSale',
            'canSalesReturn', 'canQuickReport', 'canRequestManagerApproval', 'canChangeOrderDetails',
            'deliveryChannels', 'deliveryRiders', 'deliveryChargeLocked', 'defaultDeliveryCharge', 'manualDiscountNeedsManager',
        ]);

        return view('edge.pos.index', $page);
    }

    /** ONLINE-POS PARITY — Preview Bill: the running bill on the same sale truth, ZERO mutation. */
    public function previewBill(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_type' => ['nullable', 'string'],
            'discount_type' => ['nullable', 'string'],
            'discount_value' => ['nullable', 'numeric'],
            'promo_code' => ['nullable', 'string'],
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
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
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
            'order_type' => ['required', 'string'],
            'client_uuid' => ['required', 'string', 'max:36'],
            'discount_type' => ['nullable', 'string'],
            'discount_value' => ['nullable', 'numeric'],
            'promo_code' => ['nullable', 'string'],
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
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.tendered_amount' => ['nullable', 'numeric'],
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

        return response()->json([
            'sale_id' => $sale->id,
            'sale_no' => $sale->sale_no,
            'sale_uuid' => $sale->sale_uuid,
            'status' => $sale->status,
            'grand_total' => (float) $sale->grand_total,
            'paid_amount' => (float) $sale->paid_amount,
            'change_amount' => (float) $sale->payments()->first()?->change_amount,
            'edge_sync_state' => $sale->edge_sync_state,
        ], 201);
    }

    /**
     * CUSTOMER-UX parity: customers are looked up on demand (never the whole book in the page) from the SYNCED
     * customer book, with their saved addresses (ADDRESS-ATTACH: picking one attaches it to the order).
     */
    public function customers(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['customers' => []]);
        }
        $rows = \App\Models\Tenant\Customer::on('tenant')->where('status', 'active')
            ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%"))
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
