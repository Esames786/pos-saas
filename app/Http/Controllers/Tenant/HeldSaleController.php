<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Customer;
use App\Models\Tenant\DeliveryChannel;
use App\Models\Tenant\DeliveryRider;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Services\Sales\SalesTotalsService;
use App\Services\Sales\KotCancellationService;
use App\Services\Sales\ShiftService;
use App\Services\Security\UserDataScope;
use App\Exceptions\ShiftException;
use App\Support\TenantClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HeldSaleController extends Controller
{
    public function index(Request $request)
    {
        $query = SalesOrder::with(['branch', 'customer', 'restaurantTable', 'restaurantWaiter'])
            ->where('status', 'held')
            ->orderByDesc('updated_at');
        app(UserDataScope::class)->applyToSales($query, auth('tenant')->user());

        if ($request->filled('table_session_id')) {
            $query->where('restaurant_table_session_id', $request->table_session_id);
        }

        $heldSales = $query->paginate(25);

        return view('tenant.held-sales.index', compact('heldSales'));
    }

    /** AJAX: list held sales for a branch (used by POS modal) */
    public function ajaxList(Request $request)
    {
        $branchId = $request->integer('branch_id');
        if ($branchId) {
            app(UserDataScope::class)->assertPosSelection(auth('tenant')->user(), $branchId, null);
        }

        // Same rule as Recent Orders: an operator only ever sees the order types he may run.
        $allowedTypes = auth("tenant")->user()?->effectiveAllowedOrderTypes() ?? [];
        $filterType = (string) $request->input('order_type', '');

        $sales = SalesOrder::with(['customer', 'lines'])
            ->where('status', 'held')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($allowedTypes, fn ($q) => $q->whereIn('order_type', $allowedTypes))
            ->when($filterType !== '' && in_array($filterType, $allowedTypes, true),
                fn ($q) => $q->where('order_type', $filterType))
            ->tap(fn ($query) => app(UserDataScope::class)->applyToSales($query, auth('tenant')->user()))
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        return response()->json([
            'sales' => $sales->map(fn ($s) => [
                'id'                          => $s->id,
                'sale_no'                     => $s->sale_no,
                'order_type'                  => $s->order_type,
                'branch_id'                   => (int) $s->branch_id,
                'terminal_id'                 => $s->terminal_id,
                'restaurant_table_session_id' => $s->restaurant_table_session_id,
                'restaurant_table_id'         => $s->restaurant_table_id,
                'delivery_channel_id'         => $s->delivery_channel_id,
                'delivery_rider_id'           => $s->delivery_rider_id,
                'delivery_address'            => $s->delivery_address,
                'vehicle_number'              => $s->vehicle_number,
                'customer'                    => $s->customer_name ?: $s->customer?->name ?: 'Walk-in',
                // CUSTOMER-UX-1: recall must restore the attached customer (chip + hidden fields).
                'customer_id'                 => $s->customer_id,
                'customer_name'               => $s->customer_name ?: $s->customer?->name,
                'customer_phone'              => $s->customer_phone ?: $s->customer?->phone,
                'total'                       => number_format($s->grand_total, 2),
                'items'                       => $s->lines->count(),
                'time'                        => $s->created_at->diffForHumans(),
                'notes'                       => $s->notes,
                'lines'                       => $s->lines->map(fn ($l) => [
                    'id'                 => (int) $l->id,
                    'product_id'         => (int) $l->product_id,
                    'product_variant_id' => $l->product_variant_id ? (int) $l->product_variant_id : null,
                    'parent_sales_order_line_id' => $l->parent_sales_order_line_id ? (int) $l->parent_sales_order_line_id : null,
                    'line_kind'          => $l->line_kind ?? 'standard',
                    'combo_id'           => $l->combo_id ? (int) $l->combo_id : null,
                    'quantity'           => (float) $l->quantity,
                    'unit_price'         => (float) $l->unit_price,
                    'discount_amount'    => (float) $l->discount_amount,
                    'tax_amount'         => (float) $l->tax_amount,
                    'line_total'         => (float) $l->line_total,
                    'product_name'       => $l->product_name,
                    'variant_name'       => $l->variant_name,
                    'unit_code'          => $l->unit_code,
                    'modifiers'          => $l->modifiers ?? [],
                    'kot_sent'           => (bool) $l->kot_sent,
                    'kot_sent_quantity'  => (float) ($l->kot_sent_quantity ?? 0),
                    'kitchen_note'       => $l->kitchen_note,
                ])->values(),
            ]),
        ]);
    }

    /** AJAX: tables for branch (used by Change Order modal — auto-creates session on hold) */
    public function ajaxTableSessions(Request $request)
    {
        $branchId = $request->integer('branch_id');
        $scope = app(UserDataScope::class);
        if ($branchId) {
            $scope->assertPosSelection(auth('tenant')->user(), $branchId, null);
        }

        $tables = RestaurantTable::with(['openSession.waiter', 'floor'])
            ->where('status', 'active')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when(! $branchId && $scope->hasBranchAssignments(auth('tenant')->user()),
                fn ($q) => $q->whereIn('branch_id', $scope->branchIds(auth('tenant')->user())))
            ->orderBy('table_no')
            ->limit(100)
            ->get();

        return response()->json([
            'sessions' => $tables->map(fn ($t) => [
                'table_id'   => $t->id,
                'session_id' => $t->openSession?->id,
                'label'      => 'Table ' . $t->table_no
                              . ($t->floor ? ' — ' . $t->floor->name : '')
                              . ($t->name && $t->name !== $t->table_no ? ' (' . $t->name . ')' : ''),
                'waiter'     => $t->openSession?->waiter?->name,
                'has_session' => !is_null($t->openSession),
            ])->values()->all(),
        ]);
    }

    /** AJAX: open held orders for a specific table session */
    public function tableSessionOpenOrders(RestaurantTableSession $restaurantTableSession)
    {
        app(UserDataScope::class)->assertPosSelection(
            auth('tenant')->user(),
            (int) $restaurantTableSession->branch_id,
            null
        );
        $restaurantTableSession->loadMissing(['table', 'waiter']);

        $orders = SalesOrder::with(['lines'])
            ->where('restaurant_table_session_id', $restaurantTableSession->id)
            ->where('status', 'held')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'ok'               => true,
            'table_session_id' => $restaurantTableSession->id,
            'branch_id'        => $restaurantTableSession->branch_id,
            // Session detail lets the POS paint the session bar in place when continuing.
            'session'          => $this->sessionPayload($restaurantTableSession),
            // Full line payload lets the POS recall an order client-side (no reload).
            'orders'           => $orders->map(fn ($sale) => $this->openOrderPayload($sale, $restaurantTableSession))->values(),
        ]);
    }

    private function sessionPayload(RestaurantTableSession $session): array
    {
        $session->loadMissing(['salesOrders' => fn ($query) => $query->whereIn('status', ['held', 'paid'])]);

        return [
            'id'          => (int) $session->id,
            'session_no'  => $session->session_no,
            'table_id'    => (int) $session->restaurant_table_id,
            'table_no'    => $session->table?->table_no,
            'waiter_name' => $session->waiter?->name,
            'guest_count' => $session->guest_count,
            'status'      => $session->status,
            'branch_id'   => (int) $session->branch_id,
            'open_check'  => (float) $session->salesOrders->where('status', 'held')->sum('grand_total'),
        ];
    }

    private function openOrderPayload(SalesOrder $sale, RestaurantTableSession $session): array
    {
        return [
            'id'                          => (int) $sale->id,
            'sale_no'                     => $sale->sale_no,
            'order_type'                  => $sale->order_type,
            'terminal_id'                 => $sale->terminal_id,
            'restaurant_table_session_id' => $sale->restaurant_table_session_id,
            'restaurant_table_id'         => $sale->restaurant_table_id,
            'grand_total'                 => (float) $sale->grand_total,
            'grand_total_formatted'       => number_format((float) $sale->grand_total, 2),
            'items_count'                 => $sale->lines->count(),
            'created_at'                  => $sale->created_at?->format('d M Y H:i'),
            'updated_at'                  => $sale->updated_at?->diffForHumans(),
            'recall_url'                  => url('/pos?held_sale_id=' . $sale->id
                . '&table_session_id=' . $session->id
                . '&mode=dine_in&branch_id=' . $session->branch_id),
            'lines'                       => $sale->lines->map(fn ($l) => [
                'id'                 => (int) $l->id,
                'product_id'         => (int) $l->product_id,
                'product_variant_id' => $l->product_variant_id ? (int) $l->product_variant_id : null,
                'parent_sales_order_line_id' => $l->parent_sales_order_line_id ? (int) $l->parent_sales_order_line_id : null,
                'line_kind'          => $l->line_kind ?? 'standard',
                'combo_id'           => $l->combo_id ? (int) $l->combo_id : null,
                'quantity'           => (float) $l->quantity,
                'unit_price'         => (float) $l->unit_price,
                'discount_amount'    => (float) $l->discount_amount,
                'tax_amount'         => (float) $l->tax_amount,
                'line_total'         => (float) $l->line_total,
                'product_name'       => $l->product_name,
                'variant_name'       => $l->variant_name,
                'unit_code'          => $l->unit_code,
                'modifiers'          => $l->modifiers ?? [],
                'kot_sent'           => (bool) $l->kot_sent,
                'kot_sent_quantity'  => (float) ($l->kot_sent_quantity ?? 0),
                'kitchen_note'       => $l->kitchen_note,
            ])->values(),
        ];
    }

    public function create()
    {
        $branches      = Branch::where('status', 'active')->orderBy('name')->get();
        $terminals     = Terminal::orderBy('name')->get();
        $customers     = Customer::where('status', 'active')->orderBy('name')->get();
        $products      = Product::with(['barcodes', 'variants', 'branchPrices'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
        $tableSessions = RestaurantTableSession::with(['table.floor', 'waiter'])
            ->where('status', 'open')
            ->orderByDesc('opened_at')
            ->get();

        return view('tenant.held-sales.create', compact(
            'branches', 'terminals', 'customers', 'products', 'tableSessions'
        ));
    }

    public function store(Request $request, SalesTotalsService $totalsService, KotCancellationService $cancellationService)
    {
        $data = $request->validate([
            'held_sale_id'                => 'nullable|exists:sales_orders,id',
            'branch_id'                   => 'required|exists:branches,id',
            'terminal_id'                 => 'nullable|exists:terminals,id',
            'order_type'                  => 'required|in:quick_sale,takeaway,dine_in,delivery',
            'delivery_channel_id'         => 'nullable|exists:delivery_channels,id',
            'delivery_rider_id'           => 'nullable|exists:delivery_riders,id',
            'delivery_address'            => 'nullable|string|max:500',
            'vehicle_number'              => 'nullable|string|max:50',
            'restaurant_table_session_id' => 'nullable|exists:restaurant_table_sessions,id',
            'restaurant_table_id'         => 'nullable|exists:restaurant_tables,id',
            'customer_id'                 => 'nullable|exists:customers,id',
            'customer_name'               => 'nullable|string|max:200',
            'customer_phone'              => 'nullable|string|max:50',
            'customer_email'              => 'nullable|email',
            'discount_type'               => 'required|in:none,fixed,percent',
            'discount_value'              => 'nullable|numeric|min:0',
            'promo_code'                  => 'nullable|string|max:50',
            'notes'                       => 'nullable|string',
            'lines'                       => 'required|array|min:1',
            'lines.*.product_id'          => 'required_with:lines.*.quantity|nullable|exists:products,id',
            'lines.*.sales_order_line_id' => 'nullable|integer',
            'lines.*.product_variant_id'  => 'nullable|exists:product_variants,id',
            'lines.*.client_line_key'      => 'nullable|string|max:120',
            'lines.*.parent_client_line_key' => 'nullable|string|max:120',
            'lines.*.line_kind'            => 'nullable|in:standard,combo_header,component,modifier',
            'lines.*.combo_id'             => 'nullable|exists:combos,id',
            'lines.*.line_name'            => 'nullable|string|max:190',
            'lines.*.quantity'            => 'nullable|numeric|min:0.001',
            'lines.*.unit_price'          => 'nullable|numeric|min:0',
            'lines.*.modifiers'           => 'nullable|string',
            'void_items'                  => 'nullable|array',
            'void_items.*.old_line_id'    => 'nullable|integer',
            'void_items.*.reason_id'      => 'required_with:void_items.*.old_line_id|exists:void_reasons,id',
            'void_items.*.quantity'       => 'required_with:void_items.*.old_line_id|numeric|gt:0',
            'void_items.*.manager_approval_id' => 'nullable|exists:manager_approvals,id',
            'void_items.*.product_name'   => 'nullable|string',
            'create_separate_order'       => 'nullable|boolean',
        ]);

        $data = $this->validateDeliveryAttribution($data);

        if (!auth('tenant')->user()?->allowsOrderType($data['order_type'])) {
            abort(403, 'Your account is not allowed to use this order type.');
        }

        app(\App\Services\Security\UserDataScope::class)->assertPosSelection(
            auth('tenant')->user(),
            (int) $data['branch_id'],
            ! empty($data['terminal_id']) ? (int) $data['terminal_id'] : null
        );
        if (! empty($data['held_sale_id'])) {
            $held = SalesOrder::where('status', 'held')->findOrFail((int) $data['held_sale_id']);
            abort_unless((int) $held->branch_id === (int) $data['branch_id'], 422, 'The recalled order belongs to another branch.');
            abort_if(app(UserDataScope::class)->deniesSale(auth('tenant')->user(), $held), 403);
        }

        // BRANCH-OPERATING-MODE-1: block cloud held-sale mutation for an active Local POS branch.
        app(\App\Services\Edge\BranchOperatingModeService::class)
            ->assertSaleMutationAllowed(\App\Models\Tenant\Branch::findOrFail($data['branch_id']));

        // SHIFT-TIMEZONE-BUSINESS-DATE-HARDEN-1: holding an order requires an open shift (universal,
        // no requires_shift bypass). The AUTHORITATIVE check is a row-locked re-validation INSIDE the
        // transaction (lockOpenShiftForTerminal, below) so a concurrent close cannot race between
        // check and write. ShiftException is caught around the transaction and returned as a clean
        // 422 (never a rolled-back 500).
        $terminal = !empty($data['terminal_id']) ? Terminal::find($data['terminal_id']) : null;

        $lines = collect($data['lines'])->filter(fn ($l) => !empty($l['product_id']) && !empty($l['quantity']));

        if ($lines->isEmpty()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'At least one product line is required.'], 422);
            }
            return back()->withErrors(['lines' => 'At least one product line is required.'])->withInput();
        }

        $productsById = Product::whereIn('id', $lines->pluck('product_id')->filter()->unique())
            ->get(['id', 'category_id'])
            ->keyBy('id');

        // Build resolved lines for totals calculation (held sales use submitted prices)
        $resolvedLines = $lines->map(fn ($l) => [
            'product_id'      => (int) ($l['product_id'] ?? 0),
            'category_id'     => (int) ($productsById->get((int) ($l['product_id'] ?? 0))?->category_id ?? 0),
            'quantity'        => (float) ($l['quantity'] ?? 0),
            'unit_price'      => (float) ($l['unit_price'] ?? 0),
            'discount_amount' => 0,
            'tax_amount'      => 0,
        ])->values()->toArray();

        // Tip is always 0 on held sales — only applied at payment time
        $totals = $totalsService->calculate(
            resolvedLines: $resolvedLines,
            discountType:  $data['discount_type'],
            discountValue: (float) ($data['discount_value'] ?? 0),
            branchId:      (int) $data['branch_id'],
            orderType:     $data['order_type'],
            promoCode:     $data['promo_code'] ?? null,
            tipAmount:     0,
        );

        try {
        return DB::connection('tenant')->transaction(function () use (
            $request,
            $data,
            $lines,
            $totals,
            $cancellationService,
            $terminal,
        ) {

        // HARDEN-1: lock the open shift FIRST (before table-session / held-order locks) so all POS
        // writes acquire locks in a consistent order (shift -> record) and this can never commit
        // against a shift that a concurrent request has just closed.
        $shift = app(ShiftService::class)->lockOpenShiftForTerminal($terminal);

        $tableSession = null;
        if (!empty($data['restaurant_table_session_id'])) {
            $tableSession = RestaurantTableSession::with('table')
                ->where('branch_id', $data['branch_id'])
                ->lockForUpdate()
                ->find($data['restaurant_table_session_id']);
            $data['order_type'] = 'dine_in';
        } elseif (!empty($data['restaurant_table_id'])) {
            $tableSession = RestaurantTableSession::with('table')
                ->where('branch_id', $data['branch_id'])
                ->where('restaurant_table_id', $data['restaurant_table_id'])
                ->whereIn('status', ['open', 'bill_requested'])
                ->lockForUpdate()
                ->latest('opened_at')
                ->first();

            if (!$tableSession) {
                $tableSession = RestaurantTableSession::create([
                    'session_no'          => 'SES-' . now()->format('YmdHis') . '-' . random_int(100, 999),
                    'branch_id'           => $data['branch_id'],
                    'restaurant_table_id' => $data['restaurant_table_id'],
                    'opened_by_user_id'   => Auth::id(),
                    // Anchor the check to the shift that opened it; its business_date rides every
                    // round and never rolls over at midnight.
                    'opened_shift_id'     => $shift->id,
                    'business_date'       => $shift->business_date->toDateString(),
                    'status'              => 'open',
                    'opened_at'           => now(),
                ]);
                $tableSession->load('table');
            }

            $data['order_type'] = 'dine_in';
        }

        if ($data['order_type'] === 'dine_in' && !$tableSession) {
            throw ValidationException::withMessages([
                'restaurant_table_session_id' => 'Select an open table before saving a dine-in order.',
            ]);
        }
        if ($tableSession && !auth('tenant')->user()?->allowsOrderType('dine_in')) {
            abort(403, 'Your account is not allowed to use Dine In orders.');
        }

        // Business date for this round: an existing open check keeps its OWN business date (Add
        // Round never rolls it forward); a fresh check/quick hold inherits the current shift's.
        $businessDate = ($tableSession && $tableSession->business_date)
            ? $tableSession->business_date->toDateString()
            : $shift->business_date->toDateString();

        // Guard: prevent accidental duplicate held sales on the same table session
        // A table has one cashier-facing open check. Additional kitchen rounds
        // update that check; split billing remains the explicit separation flow.
        $createSeparateOrder = false;

        if ($tableSession && empty($data['held_sale_id']) && !$createSeparateOrder) {
            $openOrders = $this->tableOpenOrdersPayload($tableSession);

            if ($openOrders->isNotEmpty()) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'ok'               => false,
                        'code'             => 'TABLE_HAS_OPEN_ORDERS',
                        'message'          => 'This table already has an open check. Continue it to add the next round.',
                        'table_session_id' => $tableSession->id,
                        'branch_id'        => $tableSession->branch_id,
                        'session'          => $this->sessionPayload($tableSession->loadMissing(['table', 'waiter'])),
                        'orders'           => $openOrders,
                    ], 409);
                }

                return redirect(url('/restaurant/table-sessions/' . $tableSession->id . '/bill-preview'))
                    ->withErrors(['table' => 'This table already has an open check. Continue it to add the next round.']);
            }
        }

        $kotSentKeys = [];
        $kotSentByLineId = [];
        $cancellationBatch = null;

        if (!empty($data['held_sale_id'])) {
            $sale = SalesOrder::where('status', 'held')
                ->lockForUpdate()
                ->findOrFail($data['held_sale_id']);

            if ((int) $sale->branch_id !== (int) $data['branch_id']) {
                throw ValidationException::withMessages([
                    'branch_id' => 'A recalled order cannot be moved to another branch.',
                ]);
            }

            $existingLines = $sale->lines()->get()->keyBy('id');
            $providedLineIds = $lines->pluck('sales_order_line_id')->filter()->map(fn ($id) => (int) $id)->unique();
            $foreignLineIds = $providedLineIds->diff($existingLines->keys()->map(fn ($id) => (int) $id));
            if ($foreignLineIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'One or more recalled line references do not belong to this order.',
                ]);
            }
            $submittedQuantities = $lines
                ->filter(fn ($line) => !empty($line['sales_order_line_id']))
                ->mapWithKeys(fn ($line) => [(int) $line['sales_order_line_id'] => (float) $line['quantity']]);
            $voidItems = collect($data['void_items'] ?? [])->keyBy(fn ($item) => (int) ($item['old_line_id'] ?? 0));
            $detectedCancellations = [];

            foreach ($existingLines as $existingLine) {
                $sentQuantity = (float) $existingLine->kot_sent_quantity;
                if ($sentQuantity <= 0) {
                    continue;
                }

                $newQuantity = (float) $submittedQuantities->get($existingLine->id, 0);
                $cancelQuantity = max($sentQuantity - min($newQuantity, $sentQuantity), 0);
                if ($cancelQuantity <= 0) {
                    continue;
                }

                $voidItem = $voidItems->get($existingLine->id);
                if (!$voidItem || abs((float) $voidItem['quantity'] - $cancelQuantity) > 0.000001) {
                    throw ValidationException::withMessages([
                        'lines' => "A reason and exact cancellation quantity are required for {$existingLine->product_name} because it was sent to kitchen.",
                    ]);
                }

                $detectedCancellations[] = [
                    'line_id' => $existingLine->id,
                    'quantity' => $cancelQuantity,
                    'reason_id' => (int) $voidItem['reason_id'],
                    'manager_approval_id' => !empty($voidItem['manager_approval_id']) ? (int) $voidItem['manager_approval_id'] : null,
                ];
            }

            $cancellationResult = $cancellationService->recordLineCancellations(
                $sale,
                $detectedCancellations,
                (int) auth('tenant')->id()
            );
            $cancellationBatch = $cancellationResult['batch'] ?? null;

            // BUG-014 FIX: include line_kind in the KOT key so a standalone product
            // and the same product appearing as a combo component never share a key
            // and incorrectly inherit each other's kot_sent status.
            $kotSentKeys = $sale->lines()
                ->where('kot_sent', true)
                ->get(['product_id', 'product_variant_id', 'quantity', 'kot_sent_quantity', 'line_kind', 'combo_id'])
                ->mapWithKeys(function ($l) {
                    $sentQty = (float) $l->kot_sent_quantity > 0
                        ? (float) $l->kot_sent_quantity
                        : (float) $l->quantity;
                    $key = $l->product_id
                        . ':' . ($l->product_variant_id ?? 0)
                        . ':' . ($l->line_kind ?? 'standard')
                        . ':' . ($l->combo_id ?? 0);
                    return [$key => $sentQty];
                })
                ->all();

            $kotSentByLineId = $sale->lines()
                ->where('kot_sent_quantity', '>', 0)
                ->pluck('kot_sent_quantity', 'id')
                ->map(fn ($quantity) => (float) $quantity)
                ->all();

            $sale->lines()->delete();
            $sale->update([
                'branch_id'                   => $data['branch_id'],
                'terminal_id'                 => $data['terminal_id'] ?? null,
                // Add Round keeps the order's original shift + business date (preserve if the held
                // order predates the shift columns).
                'shift_id'                    => $sale->shift_id ?? $shift->id,
                'business_date'               => $sale->business_date?->toDateString() ?? $businessDate,
                'order_type'                  => $data['order_type'],
                'delivery_channel_id'         => $data['order_type'] === 'delivery' ? ($data['delivery_channel_id'] ?? null) : null,
                'delivery_rider_id'           => $data['order_type'] === 'delivery' ? ($data['delivery_rider_id'] ?? null) : null,
                'delivery_address'            => $data['order_type'] === 'delivery' ? ($data['delivery_address'] ?? null) : null,
                'vehicle_number'              => $data['order_type'] === 'quick_sale' ? ($data['vehicle_number'] ?? null) : null,
                'customer_id'                 => $data['customer_id'] ?? null,
                'customer_name'               => $data['customer_name'] ?? null,
                'customer_phone'              => $data['customer_phone'] ?? null,
                'customer_email'              => $data['customer_email'] ?? null,
                'promotion_id'                => $totals['promotion_id'],
                'promo_code'                  => $totals['promo_code'],
                'subtotal'                    => $totals['subtotal'],
                'discount_type'               => $data['discount_type'],
                'discount_value'              => $data['discount_value'] ?? 0,
                'discount_amount'             => $totals['discount_amount'],
                'tax_amount'                  => $totals['tax_amount'],
                'service_charge_amount'       => $totals['service_charge_amount'],
                'tip_amount'                  => 0,
                'grand_total'                 => $totals['grand_total'],
                'notes'                       => $data['notes'] ?? null,
                'restaurant_table_session_id' => $tableSession?->id ?? $sale->restaurant_table_session_id,
                'restaurant_table_id'         => $tableSession?->restaurant_table_id ?? $sale->restaurant_table_id,
            ]);
        } else {
            $saleNo = 'HS-' . now()->format('YmdHis') . '-' . random_int(100, 999);
            $sale = SalesOrder::create([
                'sale_no'                     => $saleNo,
                'branch_id'                   => $data['branch_id'],
                'terminal_id'                 => $data['terminal_id'] ?? null,
                'shift_id'                    => $shift->id,
                'business_date'               => $businessDate,
                'order_source'                => 'pos',
                'order_type'                  => $data['order_type'],
                'delivery_channel_id'         => $data['order_type'] === 'delivery' ? ($data['delivery_channel_id'] ?? null) : null,
                'delivery_rider_id'           => $data['order_type'] === 'delivery' ? ($data['delivery_rider_id'] ?? null) : null,
                'delivery_address'            => $data['order_type'] === 'delivery' ? ($data['delivery_address'] ?? null) : null,
                'vehicle_number'              => $data['order_type'] === 'quick_sale' ? ($data['vehicle_number'] ?? null) : null,
                'customer_id'                 => $data['customer_id'] ?? null,
                'customer_name'               => $data['customer_name'] ?? null,
                'customer_phone'              => $data['customer_phone'] ?? null,
                'customer_email'              => $data['customer_email'] ?? null,
                'promotion_id'                => $totals['promotion_id'],
                'promo_code'                  => $totals['promo_code'],
                'sale_date'                   => now(),
                'subtotal'                    => $totals['subtotal'],
                'discount_type'               => $data['discount_type'],
                'discount_value'              => $data['discount_value'] ?? 0,
                'discount_amount'             => $totals['discount_amount'],
                'tax_amount'                  => $totals['tax_amount'],
                'service_charge_amount'       => $totals['service_charge_amount'],
                'tip_amount'                  => 0,
                'grand_total'                 => $totals['grand_total'],
                'status'                      => 'held',
                'inventory_posted'            => false,
                'created_by_user_id'          => Auth::id(),
                'notes'                       => $data['notes'] ?? null,
                'restaurant_table_session_id' => $tableSession?->id,
                'restaurant_table_id'         => $tableSession?->restaurant_table_id,
                'restaurant_floor_id'         => $tableSession?->table?->restaurant_floor_id,
                'restaurant_waiter_id'        => $tableSession?->restaurant_waiter_id,
            ]);
        }

        $createdLinesByClientKey = [];
        $savedLinePayload = [];

        foreach ($lines as $line) {
            $product = Product::with('unit')->find($line['product_id']);
            $variant = !empty($line['product_variant_id'])
                ? ProductVariant::find($line['product_variant_id'])
                : null;

            $lineTotal = (float) $line['quantity'] * (float) ($line['unit_price'] ?? 0);

            // BUG-014 FIX: use the same 4-part key as the lookup above.
            $kotKey    = $line['product_id']
                . ':' . (($line['product_variant_id'] ?? null) ?? 0)
                . ':' . ($line['line_kind'] ?? 'standard')
                . ':' . (($line['combo_id'] ?? null) ?? 0);
            $oldLineId = !empty($line['sales_order_line_id']) ? (int) $line['sales_order_line_id'] : null;
            $sentQty   = $oldLineId ? ($kotSentByLineId[$oldLineId] ?? 0) : ($kotSentKeys[$kotKey] ?? 0);
            $newQty    = (float) $line['quantity'];
            $kotSent   = $sentQty > 0 && $newQty <= $sentQty;
            $kotSentQty = min($sentQty, $newQty);
            $parentClientKey = $line['parent_client_line_key'] ?? null;

            $createdLine = $sale->lines()->create([
                'parent_sales_order_line_id' => $parentClientKey && isset($createdLinesByClientKey[$parentClientKey])
                    ? $createdLinesByClientKey[$parentClientKey]->id
                    : null,
                'line_kind'          => $line['line_kind'] ?? 'standard',
                'combo_id'           => $line['combo_id'] ?? null,
                'product_id'         => $line['product_id'],
                'product_variant_id' => $line['product_variant_id'] ?? null,
                'product_name'       => (($line['line_kind'] ?? 'standard') === 'combo_header' && !empty($line['line_name']))
                    ? $line['line_name']
                    : ($product?->name ?? ''),
                'variant_name'       => $variant?->name ?? null,
                'unit_code'          => $product?->unit?->code,
                'quantity'           => $line['quantity'],
                'unit_price'         => $line['unit_price'] ?? 0,
                'unit_cost'          => 0,
                'cost_total'         => 0,
                'discount_amount'    => 0,
                'tax_amount'         => 0,
                'line_total'         => $lineTotal,
                'modifiers'          => $this->normalizeLineModifiers($line['modifiers'] ?? null),
                'kot_sent'           => $kotSent,
                'kot_sent_quantity'  => $kotSentQty,
            ]);

            if (!empty($line['client_line_key'])) {
                $createdLinesByClientKey[$line['client_line_key']] = $createdLine;
            }
            $savedLinePayload[] = [
                'id' => (int) $createdLine->id,
                'client_line_key' => $line['client_line_key'] ?? null,
                'kot_sent' => (bool) $createdLine->kot_sent,
                'kot_sent_quantity' => (float) $createdLine->kot_sent_quantity,
            ];
        }

        if ($cancellationBatch) {
            $sale->unsetRelation('lines');
            $cancellationService->queueCorrectionReminders($sale, $cancellationBatch);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'sale_id'                     => $sale->id,
                'sale_no'                     => $sale->sale_no,
                'restaurant_table_session_id' => $sale->restaurant_table_session_id,
                'lines'                       => $savedLinePayload,
            ]);
        }

        return redirect(url('/held-sales'))->with('status', "Held sale {$sale->sale_no} saved.");
        });
        } catch (ShiftException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'code' => 'NO_OPEN_SHIFT', 'message' => $e->getMessage()], 422);
            }
            return back()->withErrors(['terminal_id' => $e->getMessage()])->withInput();
        }
    }

    private function validateDeliveryAttribution(array $data): array
    {
        if (($data['order_type'] ?? null) === 'delivery' && empty($data['customer_id'])) {
            throw ValidationException::withMessages([
                'customer_id' => 'Attach a customer before saving a delivery order.',
            ]);
        }

        if (($data['order_type'] ?? null) !== 'delivery' || ! empty($data['restaurant_table_session_id']) || ! empty($data['restaurant_table_id'])) {
            $data['delivery_channel_id'] = null;
            $data['delivery_rider_id'] = null;

            return $data;
        }

        if (empty($data['delivery_channel_id'])) {
            throw ValidationException::withMessages([
                'delivery_channel_id' => 'Select a delivery channel for delivery orders.',
            ]);
        }

        $channel = DeliveryChannel::where('is_active', true)->find($data['delivery_channel_id']);

        if (! $channel) {
            throw ValidationException::withMessages([
                'delivery_channel_id' => 'Selected delivery channel is not active.',
            ]);
        }

        if (! $channel->isOwn()) {
            $data['delivery_rider_id'] = null;

            return $data;
        }

        if (empty($data['delivery_rider_id'])) {
            throw ValidationException::withMessages([
                'delivery_rider_id' => 'Select a rider for own-delivery orders.',
            ]);
        }

        $rider = DeliveryRider::where('status', 'active')->find($data['delivery_rider_id']);

        if (! $rider || ($rider->branch_id && (int) $rider->branch_id !== (int) $data['branch_id'])) {
            throw ValidationException::withMessages([
                'delivery_rider_id' => 'Selected rider is not active for this branch.',
            ]);
        }

        return $data;
    }

    private function tableOpenOrdersPayload(RestaurantTableSession $tableSession)
    {
        return SalesOrder::with(['lines'])
            ->where('restaurant_table_session_id', $tableSession->id)
            ->where('status', 'held')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($sale) => $this->openOrderPayload($sale, $tableSession))
            ->values();
    }

    public function cancel(Request $request, SalesOrder $salesOrder, KotCancellationService $cancellationService)
    {
        abort_if(app(UserDataScope::class)->deniesSale(auth('tenant')->user(), $salesOrder), 403);
        app(\App\Services\Edge\BranchOperatingModeService::class)
            ->assertSaleMutationAllowed(\App\Models\Tenant\Branch::findOrFail($salesOrder->branch_id));

        $data = $request->validate([
            'reason_id' => ['required', 'integer', 'exists:void_reasons,id'],
            'manager_approval_id' => ['nullable', 'integer', 'exists:manager_approvals,id'],
        ]);

        $result = $cancellationService->cancelHeldOrder(
            $salesOrder,
            (int) $data['reason_id'],
            !empty($data['manager_approval_id']) ? (int) $data['manager_approval_id'] : null,
            (int) auth('tenant')->id(),
        );

        if (request()->expectsJson()) {
            return response()->json([
                'ok' => true,
                'cancel_kot_jobs' => collect($result['jobs'])->map(fn ($job) => [
                    'job_id' => $job->id,
                    'fallback' => empty($job->printer_id),
                    'preview_url' => url('/printing/documents/' . $job->id . '/kot'),
                ])->values(),
                'cancellation_reminder_jobs' => collect($result['reminder_jobs'] ?? [])->map(fn ($job) => [
                    'job_id' => $job->id,
                    'printer_id' => $job->printer_id,
                ])->values(),
            ]);
        }

        return redirect(url('/held-sales'))->with('status', 'Held sale cancelled.');
    }

    private function normalizeLineModifiers(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($modifier) => is_array($modifier))
            ->map(fn ($modifier) => [
                'modifier_group_id'   => (int) ($modifier['modifier_group_id'] ?? 0),
                'modifier_group_name' => (string) ($modifier['modifier_group_name'] ?? ''),
                'modifier_id'         => (int) ($modifier['modifier_id'] ?? 0),
                'name'                => (string) ($modifier['name'] ?? ''),
                'price_delta'         => round((float) ($modifier['price_delta'] ?? 0), 2),
            ])
            ->filter(fn ($modifier) => $modifier['modifier_id'] > 0 && $modifier['name'] !== '')
            ->values()
            ->all();
    }
}
