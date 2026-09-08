<?php

namespace App\Http\Controllers\Edge;

use App\Exceptions\ShiftException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Combo;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\Product;
use App\Models\Tenant\RestaurantWaiter;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeOperationalBaselineService;
use App\Services\Sales\ShiftService;
use App\Services\Security\UserDataScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EDGE-LOCAL-POS-1 — the branch_server-only local POS HTTP surface.
 *
 * Registered ONLY in routes/edge_runtime.php (absent — genuine 404 — on Cloud), behind `edge.auth`
 * (authenticated local tenant session) + `edge.branch` (bound appliance; request tenant/branch ids can never
 * override the binding). ALL authority comes from EdgeBranchContext + the authenticated principal +
 * EdgeLocalPosService's own transactional revalidation; this controller adds no authority of its own.
 * It never calls Cloud finance/inventory mutators (fenced anyway) and cannot bypass the accepted
 * operational-stock baseline (the service refuses before mutation). activation_ready stays false.
 *
 * The selected terminal is per-session (`edge_pos_terminal_id`) and re-validated on every use.
 */
class EdgeLocalPosController extends Controller
{
    public const TERMINAL_SESSION_KEY = 'edge_pos_terminal_id';

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeLocalPosService $pos,
        private readonly ShiftService $shifts,
        private readonly EdgeOperationalBaselineService $baselines,
        private readonly \App\Services\Edge\EdgeTableReservationService $reservations,
        private readonly \App\Services\Printing\PrintJobService $printJobs,
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

        return view('edge.pos.index', [
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
        ]);
    }

    /** ONLINE-POS PARITY — Preview Bill: the running bill on the same sale truth, ZERO mutation. */
    public function previewBill(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_type' => ['nullable', 'string'],
            'discount_type' => ['nullable', 'string'],
            'discount_value' => ['nullable', 'numeric'],
            'promo_code' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
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

    /** ONLINE-POS PARITY — reserve a table (walk-in or existing customer, booking time, note). */
    public function reserveTable(Request $request, int $table): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'reserved_for' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $r = $this->reservations->reserve($table, $data, auth('tenant')->user());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->reservationView($r), 201);
    }

    /** ONLINE-POS PARITY — view the active reservation on a table. */
    public function tableReservation(int $table): JsonResponse
    {
        $r = $this->reservations->activeFor($table);

        return response()->json(['reservation' => $r ? $this->reservationView($r) : null]);
    }

    /** ONLINE-POS PARITY — cancel the active reservation on a table. */
    public function cancelReservation(int $table): JsonResponse
    {
        try {
            $this->reservations->cancel($table, auth('tenant')->user());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'cancelled']);
    }

    private function reservationView(\App\Models\Edge\EdgeTableReservation $r): array
    {
        return [
            'reservation_uuid' => $r->reservation_uuid,
            'restaurant_table_id' => (int) $r->restaurant_table_id,
            'customer_id' => $r->customer_id !== null ? (int) $r->customer_id : null,
            'customer_name' => $r->customer_name,
            'customer_phone' => $r->customer_phone,
            'reserved_for' => $r->reserved_for?->toIso8601String(),
            'note' => $r->note,
            'status' => $r->status,
        ];
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

    /** Select the operating terminal for this session (must belong to the bound branch and be active). */
    public function selectTerminal(Request $request): JsonResponse
    {
        $data = $request->validate(['terminal_id' => ['required', 'integer']]);
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $terminal = Terminal::on('tenant')->where('id', (int) $data['terminal_id'])
            ->where('branch_id', $branchId)->where('status', 'active')->first();
        if (! $terminal) {
            return response()->json(['message' => 'Select an active terminal on this branch.'], 422);
        }
        $request->session()->put(self::TERMINAL_SESSION_KEY, $terminal->id);

        return response()->json(['selected_terminal_id' => $terminal->id]);
    }

    /** Current shift state for the selected terminal. */
    public function shiftStatus(Request $request): JsonResponse
    {
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $open = Shift::on('tenant')->where('terminal_id', $terminal->id)->where('status', 'open')->latest('id')->first();

        return response()->json([
            'terminal_id' => $terminal->id,
            'shift' => $open ? [
                'id' => $open->id,
                'shift_uuid' => $open->shift_uuid,
                'business_date' => $open->business_date?->toDateString(),
                'opened_at' => $open->opened_at?->toIso8601String(),
                'total_sales' => (float) $open->total_sales,
                'expected_cash' => (float) $open->expected_cash,
            ] : null,
        ]);
    }

    /** Open a shift on the selected terminal (the authenticated cashier is the opener). */
    public function openShift(Request $request): JsonResponse
    {
        $data = $request->validate(['opening_cash' => ['nullable', 'numeric', 'min:0']]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $branch = Branch::on('tenant')->find((int) $this->context->requireCurrent()->branch_id);
        try {
            $shift = $this->shifts->open($branch, $terminal, (int) auth('tenant')->id(), (float) ($data['opening_cash'] ?? 0));
        } catch (ShiftException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['shift_id' => $shift->id, 'shift_uuid' => $shift->shift_uuid, 'business_date' => $shift->business_date?->toDateString()], 201);
    }

    /**
     * SHIFT parity — what the Online Shift screens show, for this terminal and the branch: the operating
     * business date (OPERATING-DATE-1: the open shift's business_date, never the wall clock), the open shift
     * with its tender breakup and cancellations (SHIFT-CANCELLATIONS-1 / SHIFT-RECONCILE), the branch's other
     * open shifts (terminal lock), and HIDE-AMOUNTS (blind count) decided ONCE by the shared AmountVisibility
     * rule — figures are STRIPPED here, never merely hidden in the page.
     */
    public function shiftSummary(Request $request): JsonResponse
    {
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $branch = Branch::on('tenant')->findOrFail((int) $this->context->requireCurrent()->branch_id);
        $user = auth('tenant')->user();
        $maySeeAmounts = app(\App\Support\AmountVisibility::class)->allows($user, $branch);
        $money = fn ($v) => $maySeeAmounts ? (float) $v : null;

        $open = Shift::on('tenant')->where('terminal_id', $terminal->id)->where('status', 'open')->latest('id')->first();
        $breakup = null;
        if ($open) {
            $cancelled = SalesOrder::on('tenant')->where('shift_id', $open->id)->where('status', 'cancelled')
                ->selectRaw('COUNT(*) as bills, COALESCE(SUM(grand_total), 0) as amount')->first();
            $voided = DB::connection('tenant')->table('sales_order_line_cancellations as c')
                ->join('sales_orders as o', 'o.id', '=', 'c.sales_order_id')
                ->where('o.shift_id', $open->id)
                ->selectRaw('COUNT(*) as lines_count, COALESCE(SUM(c.quantity), 0) as units')->first();
            $breakup = [
                'opening_cash' => $money($open->opening_cash),
                'total_sales' => $money($open->total_sales),
                'cash' => $money($open->total_cash),
                'card' => $money($open->total_card),
                'bank' => $money($open->total_bank_transfer),
                'cheque' => $money($open->total_cheque),
                'expected_cash' => $money($open->expected_cash),
                // cancellation COUNTS stay visible to an operator (a bill was thrown away); amounts follow the rule.
                'cancelled_bills' => (int) ($cancelled->bills ?? 0),
                'cancelled_amount' => $money($cancelled->amount ?? 0),
                'voided_lines' => (int) ($voided->lines_count ?? 0),
                'voided_units' => (float) ($voided->units ?? 0),
            ];
        }

        $branchOpen = Shift::on('tenant')->with('terminal:id,name')->where('branch_id', $branch->id)->where('status', 'open')
            ->orderBy('terminal_id')->get()->map(fn (Shift $s) => [
                'terminal_id' => (int) $s->terminal_id, 'terminal_name' => $s->terminal?->name,
                'business_date' => $s->business_date?->toDateString(), 'opened_at' => $s->opened_at?->toIso8601String(),
                'is_current' => (int) $s->terminal_id === (int) $terminal->id,
            ])->values();

        return response()->json([
            'terminal_id' => $terminal->id,
            'operating_business_date' => app(\App\Support\TenantClock::class)->operatingBusinessDate($branch),
            'current_business_date' => app(\App\Support\TenantClock::class)->currentBusinessDate($branch),
            'may_see_amounts' => $maySeeAmounts,
            'shift' => $open ? [
                'id' => $open->id, 'shift_uuid' => $open->shift_uuid,
                'business_date' => $open->business_date?->toDateString(),
                'opened_at' => $open->opened_at?->toIso8601String(),
                'zero_drawer' => abs((float) $open->expected_cash) < 0.005, // ZERO-DRAWER-1: nothing to count
            ] : null,
            'breakup' => $breakup,
            'branch_open_shifts' => $branchOpen,
        ]);
    }

    /**
     * Close the selected terminal's open shift — the SHARED ShiftService::closeShift operation.
     * ZERO-DRAWER-1: an omitted count is passed down as NULL and resolved under the row lock (an
     * empty drawer closes; a drawer holding cash demands a typed count — 0 must be typed deliberately).
     */
    public function closeShift(Request $request): JsonResponse
    {
        $data = $request->validate([
            'counted_cash' => ['nullable', 'numeric', 'min:0'],
            'closing_notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $open = Shift::on('tenant')->where('terminal_id', $terminal->id)->where('status', 'open')->latest('id')->first();
        if (! $open) {
            return response()->json(['message' => 'No open shift on this terminal.'], 422);
        }
        try {
            $counted = $request->filled('counted_cash') ? (float) $data['counted_cash'] : null;
            $closed = $this->shifts->closeShift($open, (int) auth('tenant')->id(), $counted, $data['closing_notes'] ?? null);
        } catch (ShiftException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'shift_id' => $closed->id,
            'status' => $closed->status,
            'expected_cash' => (float) $closed->expected_cash,
            'counted_cash' => (float) $closed->counted_cash,
            'cash_variance' => (float) $closed->cash_variance,
        ]);
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
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

    // ═══════════════════════ EDGE-LOCAL-POS-1 — restaurant layer (dine-in / held / KOT) ═══════════════════════

    /** Table board data for the bound branch: floors → tables → open session + open-check summary. */
    public function restaurantBoard(): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $floors = \App\Models\Tenant\RestaurantFloor::on('tenant')->where('branch_id', $branchId)
            ->where('status', 'active')->orderBy('sort_order')->orderBy('name')
            ->with(['tables' => fn ($q) => $q->where('status', '!=', 'inactive')->orderBy('sort_order')->orderBy('table_no')
                ->with(['openSession' => fn ($s) => $s->with('waiter')])])
            ->get()
            ->map(fn ($floor) => [
                'id' => $floor->id, 'name' => $floor->name,
                'tables' => $floor->tables->map(function ($t) {
                    $session = $t->openSession;
                    $held = $session ? SalesOrder::on('tenant')->where('restaurant_table_session_id', $session->id)
                        ->where('status', 'held')->get(['id', 'sale_no', 'sale_uuid', 'grand_total']) : collect();

                    // ONLINE-POS PARITY: a free table carrying an ACTIVE Edge reservation shows as reserved
                    // (reservations live in the Edge-owned table, never on restaurant_tables config).
                    $reservation = $session ? null : $this->reservations->activeFor((int) $t->id);

                    return [
                        'id' => $t->id, 'table_no' => $t->table_no, 'name' => $t->name, 'capacity' => $t->capacity,
                        'status' => $session ? ($session->status === 'bill_requested' ? 'bill_requested' : 'occupied') : ($reservation ? 'reserved' : $t->status),
                        'reservation' => $reservation ? $this->reservationView($reservation) : null,
                        'session' => $session ? [
                            'id' => $session->id, 'session_uuid' => $session->session_uuid, 'session_no' => $session->session_no,
                            'guest_count' => $session->guest_count, 'status' => $session->status,
                            'business_date' => $session->business_date?->toDateString(),
                            'waiter_name' => $session->waiter?->name,
                            'held_orders' => $held->values(),
                        ] : null,
                    ];
                })->values(),
            ])->values();

        return response()->json(['branch_id' => $branchId, 'floors' => $floors]);
    }

    /** Open a dine-in table session on the selected terminal. */
    public function openTable(Request $request, int $table): JsonResponse
    {
        $data = $request->validate([
            'restaurant_waiter_id' => ['nullable', 'integer'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        try {
            $session = $this->pos->openTableSession($table, $data, auth('tenant')->user(), $terminal->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'session_id' => $session->id, 'session_uuid' => $session->session_uuid, 'session_no' => $session->session_no,
            'table_id' => $session->restaurant_table_id, 'status' => $session->status,
            'business_date' => $session->business_date?->toDateString(),
        ], 201);
    }

    /** Close/cancel a table session that has no remaining open orders. */
    public function closeTableSession(Request $request, int $session): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'in:closed,cancelled']]);
        try {
            $closed = $this->pos->closeTableSession($session, $data['status'] ?? 'closed', auth('tenant')->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['session_id' => $closed->id, 'status' => $closed->status]);
    }

    /** Create or revise (Add Round) a HELD sale. */
    public function storeHeldSale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'held_sale_id' => ['nullable', 'integer'],
            'order_type' => ['required', 'string'],
            'restaurant_table_session_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // POS-DRAFT-1 + PHASE 2b parity (offline): park as draft; quick-sale vehicle + waiter attribution.
            'save_as_draft' => ['nullable', 'boolean'],
            'vehicle_number' => ['nullable', 'string', 'max:50', 'required_if:order_type,quick_sale'],
            'restaurant_waiter_id' => ['nullable', 'integer', 'required_if:order_type,quick_sale'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['nullable', 'integer'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.product_variant_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.modifiers' => ['nullable', 'array'],
            'void_items' => ['nullable', 'array'],
            'void_items.*.old_line_id' => ['required_with:void_items', 'integer'],
            'void_items.*.quantity' => ['required_with:void_items', 'numeric', 'gt:0'],
            'void_items.*.reason_id' => ['required_with:void_items', 'integer'],
            'void_items.*.manager_approval_id' => ['nullable', 'integer'],
        ]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        try {
            $sale = $this->pos->holdOrReviseSale($data, auth('tenant')->user(), $terminal->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'sale_id' => $sale->id, 'sale_no' => $sale->sale_no, 'sale_uuid' => $sale->sale_uuid,
            'status' => $sale->status, 'is_draft' => (bool) $sale->is_draft, 'grand_total' => (float) $sale->grand_total,
            'restaurant_table_session_id' => $sale->restaurant_table_session_id,
            'lines' => $sale->lines()->get(['id', 'line_uuid', 'product_id', 'quantity', 'unit_price', 'kot_sent', 'kot_sent_quantity']),
        ], empty($data['held_sale_id']) ? 201 : 200);
    }

    /** Record the KOT business event for a held sale's unsent delta. */
    public function queueKot(Request $request, int $sale): JsonResponse
    {
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        try {
            $result = $this->pos->queueKotEvents($sale, auth('tenant')->user(), $terminal->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $batch = $result['batch'];

        return response()->json([
            'batch' => $batch ? [
                'id' => $batch->id, 'event_uuid' => $batch->event_uuid, 'sequence_no' => $batch->sequence_no,
                'event_type' => $batch->event_type,
                'lines' => $batch->lines()->get(['id', 'kot_line_uuid', 'source_line_uuid', 'product_name', 'quantity']),
            ] : null,
            'jobs' => collect($result['jobs'])->map(fn ($j) => ['id' => $j->id, 'logical_key' => $j->logical_key, 'print_status' => $j->fresh()->print_status])->values(),
            'message' => $batch ? null : 'No new items to send to kitchen.',
        ]);
    }

    /** Settle (pay) a held sale with cash — closes the table session when it was the last open check. */
    public function settleHeldSale(Request $request, int $sale): JsonResponse
    {
        $data = $request->validate([
            'client_uuid' => ['required', 'string', 'max:36'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.tendered_amount' => ['nullable', 'numeric'],
        ]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        if ($denied = $this->denyUnlessMayCompleteSale()) {
            return $denied;
        }
        try {
            $settled = $this->pos->settleHeldSale($sale, $data, auth('tenant')->user(), $terminal->id);
        } catch (\App\Exceptions\SaleIdempotencyConflictException $e) {
            throw $e; // renders its own 409/503
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'sale_id' => $settled->id, 'sale_no' => $settled->sale_no, 'sale_uuid' => $settled->sale_uuid,
            'status' => $settled->status, 'grand_total' => (float) $settled->grand_total,
            'paid_amount' => (float) $settled->paid_amount,
            'change_amount' => (float) $settled->payments()->first()?->change_amount,
            'edge_sync_state' => $settled->edge_sync_state,
        ]);
    }

    /** Cancel a whole held order (reason + branch-mode manager approval enforced by the real Cloud service). */
    public function cancelHeldSale(Request $request, int $sale): JsonResponse
    {
        $data = $request->validate([
            'reason_id' => ['required', 'integer'],
            'manager_approval_id' => ['nullable', 'integer'],
        ]);
        // POS-CANCEL-TERMINAL-1: the cancellation prints at the CURRENT counter (the operator's selected
        // terminal), while the order keeps its original terminal_id. No selection → no override.
        $current = (int) $request->session()->get(self::TERMINAL_SESSION_KEY, 0);
        try {
            $result = $this->pos->cancelHeldSale($sale, (int) $data['reason_id'], isset($data['manager_approval_id']) ? (int) $data['manager_approval_id'] : null, auth('tenant')->user(), $current > 0 ? $current : null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['sale_id' => $result['sale']->id, 'status' => $result['sale']->status]);
    }

    /**
     * Manager re-auth: the manager presents THEIR OWN Edge-local credential (employee code + local
     * password — never a Cloud manager PIN, which does not exist on an appliance) and receives a
     * single-use approval consumed by the action that needs it. The cashier session is untouched.
     */
    public function verifyManagerApproval(Request $request): JsonResponse
    {
        $data = $request->validate([
            'manager_employee_code' => ['required', 'string', 'max:64'],
            'manager_credential' => ['required', 'string', 'max:255'],
            'action_type' => ['required', 'string', 'max:80'],
            'payload' => ['nullable', 'array'],
        ]);
        try {
            $approval = $this->pos->verifyManagerApproval(
                $data['manager_employee_code'], $data['manager_credential'], $data['action_type'],
                auth('tenant')->user(), $data['payload'] ?? null
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['approval_id' => $approval->id, 'approval_no' => $approval->approval_no, 'approval_uuid' => $approval->approval_uuid], 201);
    }

    /** The session-selected terminal, re-validated against the bound branch on EVERY use. */
    // ═══════════════════════ EDGE-CASHIER-UI-2 — Recall / Dine-In browser workflow data ═══════════════════════

    /** RECALL parity — the open checks (held + draft) on the bound branch, limited to the order types this operator may run. */
    public function heldSales(): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $allowed = auth('tenant')->user()?->effectiveAllowedOrderTypes() ?? [];
        $sales = SalesOrder::on('tenant')->with(['restaurantTable:id,table_no,name', 'restaurantWaiter:id,name', 'lines:id,sales_order_id,quantity'])
            ->where('branch_id', $branchId)->where('status', 'held')
            ->when($allowed, fn ($q) => $q->whereIn('order_type', $allowed))
            ->orderByDesc('id')->limit(100)->get();

        return response()->json(['held_sales' => $sales->map(fn (SalesOrder $s) => $this->heldSaleView($s))->values()]);
    }

    /** One open check with its lines — what Recall / Add Round / Review & Pay load into the cart. */
    public function heldSale(int $sale): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $row = SalesOrder::on('tenant')->with(['restaurantTable:id,table_no,name', 'restaurantWaiter:id,name', 'lines'])
            ->where('branch_id', $branchId)->where('status', 'held')->find($sale);
        if (! $row) {
            return response()->json(['message' => 'No open check found.'], 404);
        }

        return response()->json(['held_sale' => $this->heldSaleView($row, true)]);
    }

    /** Active cancellation reasons (the shared VoidReason book) for cancel-order / void flows. */
    public function voidReasons(): JsonResponse
    {
        return response()->json(['reasons' => \App\Models\Tenant\VoidReason::on('tenant')->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'reason_type', 'requires_manager_approval'])]);
    }

    private function heldSaleView(SalesOrder $s, bool $withLines = false): array
    {
        $out = [
            'id' => (int) $s->id, 'sale_no' => $s->sale_no, 'sale_uuid' => $s->sale_uuid,
            'order_type' => $s->order_type, 'is_draft' => (bool) $s->is_draft, 'status' => $s->status,
            'subtotal' => (float) $s->subtotal, 'discount_amount' => (float) $s->discount_amount,
            'tax_amount' => (float) $s->tax_amount, 'service_charge_amount' => (float) $s->service_charge_amount,
            'grand_total' => (float) $s->grand_total,
            'customer_id' => $s->customer_id ? (int) $s->customer_id : null,
            'customer_name' => $s->customer_name, 'customer_phone' => $s->customer_phone,
            // The ORIGINAL counter — Recall never rewrites it (POS-RECALL-TERMINAL-1 / POS-CANCEL-TERMINAL-1).
            'terminal_id' => (int) $s->terminal_id,
            'vehicle_number' => $s->vehicle_number,
            'restaurant_table_session_id' => $s->restaurant_table_session_id ? (int) $s->restaurant_table_session_id : null,
            'table_no' => $s->restaurantTable?->table_no,
            'waiter_id' => $s->restaurant_waiter_id ? (int) $s->restaurant_waiter_id : null,
            'waiter_name' => $s->restaurantWaiter?->name,
            'item_count' => (float) $s->lines->sum('quantity'),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
        if ($withLines) {
            $out['lines'] = $s->lines->map(fn ($l) => [
                'id' => (int) $l->id, 'product_id' => (int) $l->product_id,
                'product_variant_id' => $l->product_variant_id ? (int) $l->product_variant_id : null,
                'product_name' => $l->product_name, 'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price, 'line_total' => (float) $l->line_total,
                'kot_sent_quantity' => (float) $l->kot_sent_quantity,
            ])->values();
        }

        return $out;
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

        return response()->json([
            'pending_sales' => $pending,
            'needs_attention' => $attention,
            'last_synced_at' => $snap['last_ack_at'] ?? null,
            'state' => $attention > 0 ? 'attention' : ($pending > 0 ? 'pending' : 'up_to_date'),
            'message' => $attention > 0
                ? 'Some sales need attention before they can sync — tell your manager.'
                : ($pending > 0 ? "{$pending} sale(s) waiting to sync — they are saved here and will sync when the connection returns." : 'All sales synced.'),
        ]);
    }

    // ═══════════════ EDGE-CASHIER-UI-4 — printing: receipt / KOT reprint / Recent Prints / Print Here ═══════════════

    /**
     * Queue the customer receipt through the SHARED PrintJobService — auto after payment is ensure-once
     * (a retry never duplicates the bill); `reprint` forces a fresh job. RECALL-REPRINT-TERMINAL parity:
     * routed at the CURRENT counter's receipt printer; the sale row keeps its own terminal.
     */
    public function queueReceipt(Request $request, int $sale): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $order = SalesOrder::on('tenant')->where('id', $sale)->where('branch_id', $branchId)->first();
        if (! $order) {
            return response()->json(['message' => 'No sale found.'], 404);
        }
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $reprint = $request->boolean('reprint');
        $job = $this->printJobs->queueReceipt($order, terminalId: (string) $terminal->id, ensureOnce: ! $reprint);

        return response()->json($this->printJobView($job->fresh()), 201);
    }

    /** Reprint the kitchen ticket (duplicate event) — the shared KOT path, so the stored copy fallback applies. */
    public function reprintKot(Request $request, int $sale): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $order = SalesOrder::on('tenant')->where('id', $sale)->where('branch_id', $branchId)->first();
        if (! $order) {
            return response()->json(['message' => 'No sale found.'], 404);
        }
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $jobs = $this->printJobs->queueKot($order, null, [], (string) $terminal->id, true);

        return response()->json(['jobs' => collect($jobs)->map(fn (PrintJob $j) => $this->printJobView($j->fresh()))->values()], 201);
    }

    /** Recent Prints — the branch's latest print jobs (optionally one sale's), newest first. */
    public function printJobs(Request $request): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $q = PrintJob::on('tenant')->with('printer')->where('branch_id', $branchId)->orderByDesc('id')->limit(50);
        if ($request->filled('sale_id')) {
            $q->where('reference_type', 'sales_order')->where('reference_id', (int) $request->input('sale_id'));
        }

        return response()->json(['jobs' => $q->get()->map(fn (PrintJob $j) => $this->printJobView($j))->values()]);
    }

    /**
     * Print Here — the document rendered for the browser (receipt / KOT / reminder) by the CANONICAL
     * renderer, so the appliance prints the same paper as Online (KOT deal names, snapshot fallback, layout).
     */
    public function printDocument(int $job)
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $printJob = PrintJob::on('tenant')->where('id', $job)->where('branch_id', $branchId)->firstOrFail();
        // The canonical documents carry a "Mark Printed" form aimed at the Cloud print-jobs route; on the
        // appliance that button must land on the Edge endpoint instead (the Blade reads this when set).
        view()->share('edgeMarkPrintedUrl', url('/edge/local/pos/print-jobs/' . $printJob->id . '/printed'));

        return app(\App\Http\Controllers\Tenant\PrintDocumentController::class)->preview($printJob);
    }

    /** The operator confirms a browser (fallback) print — network jobs are completed by the print worker only. */
    public function markPrinted(Request $request, int $job)
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $printJob = PrintJob::on('tenant')->where('id', $job)->where('branch_id', $branchId)->first();
        if (! $printJob) {
            return response()->json(['message' => 'No print job found.'], 404);
        }
        if ($printJob->printer_id) {
            return response()->json(['message' => 'This job prints on a network printer — the print worker completes it.'], 422);
        }
        $this->printJobs->markPrinted($printJob);

        // The document's own "Mark Printed" form (a plain POST from the print window) returns to the document.
        if (! $request->expectsJson()) {
            return redirect(url('/edge/local/pos/print-jobs/' . $printJob->id . '/document'));
        }

        return response()->json($this->printJobView($printJob->fresh()));
    }

    /** Retry a terminally-failed local network delivery (Edge print authority). */
    public function retryPrintJob(int $job): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $printJob = PrintJob::on('tenant')->where('id', $job)->where('branch_id', $branchId)->first();
        if (! $printJob) {
            return response()->json(['message' => 'No print job found.'], 404);
        }
        try {
            app(\App\Services\Edge\EdgeLocalPrintDeliveryService::class)->retryTerminalFailed($printJob->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->printJobView($printJob->fresh()));
    }

    private function printJobView(PrintJob $j): array
    {
        $j->loadMissing('printer');

        return [
            'id' => (int) $j->id, 'job_no' => $j->job_no,
            'document_type' => $j->document_type, 'print_status' => $j->print_status,
            'event_type' => data_get($j->payload, 'kot_event_type'),
            'printer_name' => $j->printer?->name ?? 'Print here (browser)',
            'printer_type' => $j->printer?->printer_type ?? 'browser',
            'fallback' => empty($j->printer_id),
            'terminal_id' => $j->terminal_id !== null ? (int) $j->terminal_id : null,
            'reference_no' => $j->reference_no,
            'reference_id' => $j->reference_id ? (int) $j->reference_id : null,
            'preview_url' => url('/edge/local/pos/print-jobs/' . $j->id . '/document'),
            'created_at' => $j->created_at?->toIso8601String(),
        ];
    }

    /**
     * COMPLETE SALE PERMISSION parity (canonical f12f1fc): taking payment is gated on `tenant.pos.store`,
     * separately from discount/approval permissions. The page hides the button; the SERVER refuses regardless.
     * `User::can()` resolves from the synced per-user effective permission set (EDGE_OFFLINE_PERMISSION_AUTHORITY).
     */
    private function denyUnlessMayCompleteSale(): ?JsonResponse
    {
        if (auth('tenant')->user()?->can('tenant.pos.store')) {
            return null;
        }

        return response()->json(['message' => 'Taking payment needs the Complete Sale permission — apply any discount, then Hold; a counter will close the bill.'], 403);
    }

    private function selectedTerminal(Request $request): Terminal|JsonResponse
    {
        $terminalId = (int) $request->session()->get(self::TERMINAL_SESSION_KEY, 0);
        if ($terminalId <= 0) {
            return response()->json(['message' => 'Select a terminal first.'], 422);
        }
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $terminal = Terminal::on('tenant')->where('id', $terminalId)->where('branch_id', $branchId)->where('status', 'active')->first();
        if (! $terminal) {
            $request->session()->forget(self::TERMINAL_SESSION_KEY);

            return response()->json(['message' => 'The selected terminal is no longer available — select a terminal.'], 422);
        }

        return $terminal;
    }
}
