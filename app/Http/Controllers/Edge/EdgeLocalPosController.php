<?php

namespace App\Http\Controllers\Edge;

use App\Exceptions\ShiftException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Combo;
use App\Models\Tenant\PaymentMethod;
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
    private const TERMINAL_SESSION_KEY = 'edge_pos_terminal_id';

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeLocalPosService $pos,
        private readonly ShiftService $shifts,
        private readonly EdgeOperationalBaselineService $baselines,
        private readonly \App\Services\Edge\EdgeTableReservationService $reservations,
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

        // Grid products: sellable, POS-visible, active — the same visibility truth the Online grid uses
        // (per-tile availability/variants/modifiers land in a later milestone; the SALE re-validates stock).
        $products = Product::on('tenant')
            ->where('status', 'active')->where('is_sellable', true)->where('is_pos_visible', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'category_id', 'default_selling_price'])
            ->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'name' => $p->name,
                'category_id' => $p->category_id ? (int) $p->category_id : null,
                'price' => (float) $p->default_selling_price,
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

        $categories = Category::on('tenant')->with('children:id,parent_id,name,sort_order')
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

    /** Close the selected terminal's open shift — the SHARED ShiftService::closeShift operation. */
    public function closeShift(Request $request): JsonResponse
    {
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
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
            $closed = $this->shifts->closeShift($open, (int) auth('tenant')->id(), (float) $data['counted_cash'], $data['closing_notes'] ?? null);
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

                    return [
                        'id' => $t->id, 'table_no' => $t->table_no, 'name' => $t->name, 'capacity' => $t->capacity,
                        'status' => $session ? ($session->status === 'bill_requested' ? 'bill_requested' : 'occupied') : $t->status,
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
        try {
            $result = $this->pos->cancelHeldSale($sale, (int) $data['reason_id'], isset($data['manager_approval_id']) ? (int) $data['manager_approval_id'] : null, auth('tenant')->user());
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
