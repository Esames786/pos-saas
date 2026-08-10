<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Customer;
use App\Models\Tenant\DeliveryChannel;
use App\Models\Tenant\DeliveryRider;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\Product;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SalesService;
use App\Services\Sales\SalesTotalsService;
use App\Services\Sales\PromotionService;
use App\Services\Sales\ShiftService;
use App\Services\Printing\DirectPayPrintOrchestrator;
use App\Support\TenantClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SalesOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = SalesOrder::with(['branch', 'terminal', 'customer', 'createdBy'])
            ->orderByDesc('sale_date')
            ->orderByDesc('id');

        // USER-DATA-SCOPE-1: a terminal/order-type restricted operator only ever lists his own
        // sales — applied to the QUERY so a hand-edited filter cannot widen it.
        app(\App\Services\Security\UserDataScope::class)->applyToSales($query, auth('tenant')->user());

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('order_type')) {
            $query->where('order_type', $request->order_type);
        }

        return view('tenant.sales-orders.index', [
            'orders'   => $query->paginate(15)->withQueryString(),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('tenant.sales-orders.create', [
            'branches'       => Branch::where('status', 'active')->orderBy('name')->get(),
            'terminals'      => Terminal::where('status', 'active')->with('branch')->orderBy('name')->get(),
            'customers'      => Customer::where('status', 'active')->orderBy('name')->get(),
            'products'       => Product::with(['defaultVariant', 'variants', 'barcodes', 'branchPrices'])
                ->where('status', 'active')
                ->where('is_sellable', true)
                ->orderBy('name')
                ->get(),
            'paymentMethods' => PaymentMethod::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, SalesService $salesService, InventoryService $inventoryService, SalesTotalsService $totalsService, \App\Services\Sales\SaleIdempotencyService $idempotency, \App\Services\Sales\KotCancellationService $cancellationService, DirectPayPrintOrchestrator $printOrchestrator)
    {
        $data = $this->validateSale($request);
        if ($request->routeIs('tenant.pos.store')
            && (!isset($data['kot_print_intent']) || !isset($data['receipt_print_intent']))) {
            throw ValidationException::withMessages([
                'printing' => 'Choose the Direct Pay KOT and Receipt intent before completing the sale.',
            ]);
        }
        $directPayPrintState = isset($data['kot_print_intent'], $data['receipt_print_intent'])
            ? DirectPayPrintOrchestrator::initialState($data['kot_print_intent'], $data['receipt_print_intent'])
            : null;

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
            abort_if(app(\App\Services\Security\UserDataScope::class)->deniesSale(auth('tenant')->user(), $held), 403);
        }

        // BRANCH-OPERATING-MODE-1: a Local POS (active) branch runs sales on its
        // Branch Server — the cloud must not create them (split-brain guard).
        app(\App\Services\Edge\BranchOperatingModeService::class)
            ->assertSaleMutationAllowed(Branch::findOrFail($data['branch_id']));

        // SALE-IDEMPOTENCY-1: one logical sale = one client_uuid. A retry / double
        // click / timeout replay of the same sale must never double-post.
        $clientUuid  = $idempotency->normalizeClientUuid($data['client_uuid'] ?? null);
        $payloadHash = $idempotency->buildPayloadHash($this->canonicalSalePayload($data));

        if ($clientUuid !== null && $existing = $idempotency->findFinalized($clientUuid)) {
            return $this->idempotentReplayOrThrow($request, $idempotency, $existing, $payloadHash, $printOrchestrator);
        }

        $lines = collect($data['lines'])
            ->filter(fn ($line) => !empty($line['product_id']) && !empty($line['quantity']))
            ->values();

        $payments = collect($data['payments'])
            ->filter(fn ($payment) => !empty($payment['payment_method_id']) && !empty($payment['amount']))
            ->values();

        if ($lines->isEmpty()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'At least one product line is required.'], 422);
            }
            return back()->withErrors(['lines' => 'At least one product line is required.'])->withInput();
        }

        if ($payments->isEmpty()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'At least one payment line is required.'], 422);
            }
            return back()->withErrors(['payments' => 'At least one payment line is required.'])->withInput();
        }

        // Idempotency fields stamped on the created/updated sale. client_uuid only
        // when supplied (else the model auto-generates one); hash always stored.
        $idempotencyFields = $clientUuid !== null
            ? ['client_uuid' => $clientUuid, 'client_payload_hash' => $payloadHash]
            : ['client_payload_hash' => $payloadHash];

        try {
            $sale = DB::connection('tenant')->transaction(function () use (
                $data, $lines, $payments, $salesService, $inventoryService, $totalsService, $idempotencyFields, $cancellationService, $directPayPrintState
            ) {
                $branch   = Branch::findOrFail($data['branch_id']);
                $terminal = !empty($data['terminal_id']) ? Terminal::find($data['terminal_id']) : null;
                $shift    = $this->resolveOpenShift($terminal, $data['order_source'] ?? 'pos');
                // Business date for a NEW sale = the open shift's frozen date (falls back to the
                // branch business-tz calendar date). Crossing midnight never changes it.
                $businessDate = app(TenantClock::class)->businessDateForSale($shift, $branch);
                $orderType = $data['order_type'];

                // Resolve line prices first so SalesTotalsService gets accurate per-line data
                $resolvedLines = $lines->map(function ($line) use ($branch, $inventoryService) {
                    $product = Product::with('unit')->findOrFail($line['product_id']);
                    $variant = $inventoryService->resolveVariant($product, $line['product_variant_id'] ?? null);
                    $qty     = (float) $line['quantity'];
                    $lineKind       = $line['line_kind'] ?? 'standard';

                    // PRODUCT-BOUNDARY-2: a normal POS line must be a saleable, POS-visible,
                    // active product. Combo header/component lines are managed by the combo
                    // system and are exempt (components are intentionally not POS-visible).
                    if (
                        $lineKind === 'standard'
                        && (! $product->is_sellable || ! $product->is_pos_visible || $product->status !== 'active')
                    ) {
                        throw new RuntimeException($product->name . ' is not available for POS sale.');
                    }

                    $submittedPrice = isset($line['unit_price']) ? (float) $line['unit_price'] : null;
                    // Combo header carries the bundle price; components are intentionally 0.
                    // Honour those exactly — never re-resolve a combo line from the catalog,
                    // or the server total drifts above what the customer pays.
                    $price = in_array($lineKind, ['combo_header', 'component'], true)
                        ? (float) ($submittedPrice ?? 0)
                        : $this->resolveSellingPrice($product, $variant, $branch->id, $submittedPrice);
                    $disc    = (float) ($line['discount_amount'] ?? 0);
                    $tax     = $this->resolveTaxAmount($product, $qty, $price, $disc,
                        isset($line['tax_amount']) ? (float) $line['tax_amount'] : null);

                    return array_merge((array) $line, [
                        '_product' => $product,
                        '_variant' => $variant,
                        'unit_price'      => $price,
                        'discount_amount' => $disc,
                        'tax_amount'      => $tax,
                        'product_id'      => $product->id,
                        'category_id'     => $product->category_id,
                        'modifiers'       => $this->normalizeLineModifiers($line['modifiers'] ?? null),
                        'client_line_key' => $line['client_line_key'] ?? null,
                        'parent_client_line_key' => $line['parent_client_line_key'] ?? null,
                        'line_kind'       => $line['line_kind'] ?? 'standard',
                        'combo_id'        => $line['combo_id'] ?? null,
                        'line_name'       => $line['line_name'] ?? null,
                    ]);
                })->values()->toArray();

                $totals = $totalsService->calculate(
                    resolvedLines: $resolvedLines,
                    discountType:  $data['discount_type'],
                    discountValue: (float) ($data['discount_value'] ?? 0),
                    branchId:      $branch->id,
                    orderType:     $orderType,
                    promoCode:     $data['promo_code'] ?? null,
                    tipAmount:     (float) ($data['tip_amount'] ?? 0),
                    // CUSTOMER-UX-1: a branch with the delivery charge LOCKED always uses its
                    // configured default — the client-submitted value is never trusted.
                    deliveryCharge: ($orderType === 'delivery' && empty($data['restaurant_table_session_id']))
                        ? ($branch->delivery_charge_locked
                            ? (float) $branch->default_delivery_charge
                            : (float) ($data['delivery_charge_amount'] ?? 0))
                        : 0,
                );

                $tableSession = null;

                if (!empty($data['restaurant_table_session_id'])) {
                    $tableSession = RestaurantTableSession::with(['table.floor', 'waiter'])
                        ->where('branch_id', $branch->id)
                        ->whereIn('status', ['open', 'bill_requested'])
                        ->lockForUpdate()
                        ->findOrFail($data['restaurant_table_session_id']);
                } elseif (!empty($data['held_sale_id'])) {
                    // If the held sale already has a session (auto-created on hold), inherit it
                    $heldForSession = SalesOrder::find($data['held_sale_id']);
                    if ($heldForSession?->restaurant_table_session_id) {
                        $tableSession = RestaurantTableSession::with(['table.floor', 'waiter'])
                            ->where('branch_id', $branch->id)
                            ->whereIn('status', ['open', 'bill_requested'])
                            ->lockForUpdate()
                            ->find($heldForSession->restaurant_table_session_id);
                    }
                }

                if ($orderType === 'dine_in' && !$tableSession) {
                    throw ValidationException::withMessages([
                        'restaurant_table_session_id' => 'Select an open table before completing a dine-in order.',
                    ]);
                }
                if ($tableSession && !auth('tenant')->user()?->allowsOrderType('dine_in')) {
                    abort(403, 'Your account is not allowed to use Dine In orders.');
                }

                // Guard: block accidental direct-pay against a table that still has open held orders
                if (
                    $tableSession
                    && empty($data['held_sale_id'])
                ) {
                    $hasOpenHeldOrders = SalesOrder::where('restaurant_table_session_id', $tableSession->id)
                        ->where('status', 'held')
                        ->exists();

                    if ($hasOpenHeldOrders) {
                        throw new RuntimeException('This table already has an open check. Recall it before taking payment.');
                    }
                }

                $saleFields = array_merge([
                    'branch_id'                   => $branch->id,
                    'terminal_id'                 => $terminal?->id,
                    'shift_id'                    => $shift?->id,
                    'customer_id'                 => $data['customer_id'] ?? null,
                    'promotion_id'                => $totals['promotion_id'],
                    'promo_code'                  => $totals['promo_code'],
                    'restaurant_floor_id'         => $tableSession?->table?->restaurant_floor_id,
                    'restaurant_table_id'         => $tableSession?->restaurant_table_id,
                    'restaurant_table_session_id' => $tableSession?->id,
                    'restaurant_waiter_id'        => $tableSession?->restaurant_waiter_id,
                    'customer_name'               => $data['customer_name'] ?? null,
                    'customer_phone'              => $data['customer_phone'] ?? null,
                    'customer_email'              => $data['customer_email'] ?? null,
                    'order_source'                => $data['order_source'] ?? 'pos',
                    'order_type'                  => $tableSession ? 'dine_in' : $orderType,
                    // Channel/rider are delivery-only attribution; never persist stale
                    // values when the effective order type is not delivery.
                    'delivery_channel_id'         => (! $tableSession && $orderType === 'delivery') ? ($data['delivery_channel_id'] ?? null) : null,
                    'delivery_rider_id'           => (! $tableSession && $orderType === 'delivery') ? ($data['delivery_rider_id'] ?? null) : null,
                    'delivery_address'            => (! $tableSession && $orderType === 'delivery') ? ($data['delivery_address'] ?? null) : null,
                    'delivery_charge_amount'      => $totals['delivery_charge_amount'] ?? 0,
                    // Vehicle number is quick-sale (drive-through) attribution only — never stale on other types.
                    'vehicle_number'              => (! $tableSession && $orderType === 'quick_sale') ? ($data['vehicle_number'] ?? null) : null,
                    'sale_date'                   => now(),
                    'business_date'               => $businessDate,
                    'subtotal'                    => $totals['subtotal'],
                    'discount_type'               => $data['discount_type'],
                    'discount_value'              => $data['discount_value'] ?? 0,
                    'discount_amount'             => $totals['discount_amount'],
                    'tax_amount'                  => $totals['tax_amount'],
                    'service_charge_amount'       => $totals['service_charge_amount'],
                    'tip_amount'                  => $totals['tip_amount'],
                    'grand_total'                 => $totals['grand_total'],
                    'notes'                       => $data['notes'] ?? null,
                ], $directPayPrintState ? ['direct_pay_print_state' => $directPayPrintState] : []);

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
                    $providedLineIds = collect($resolvedLines)->pluck('sales_order_line_id')->filter()
                        ->map(fn ($id) => (int) $id)->unique();
                    $foreignLineIds = $providedLineIds->diff($existingLines->keys()->map(fn ($id) => (int) $id));
                    if ($foreignLineIds->isNotEmpty()) {
                        throw ValidationException::withMessages([
                            'lines' => 'One or more recalled line references do not belong to this order.',
                        ]);
                    }
                    $submittedQuantities = collect($resolvedLines)
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
                    $kotSentByLineId = $existingLines->mapWithKeys(fn ($line) => [$line->id => (float) $line->kot_sent_quantity])->all();
                    $sale->lines()->delete();
                    $sale->payments()->delete();
                    $sale->update(array_merge($saleFields, $idempotencyFields, [
                        'paid_amount'      => 0,
                        'change_amount'    => 0,
                        'status'           => 'draft',
                        'inventory_posted' => false,
                        'completed_at'     => null,
                        // Add Round / recall-to-pay keeps the ORIGINAL shift + business date. A held
                        // check opened before midnight stays on its opening business day even when
                        // paid after midnight (and even from a different shift).
                        'shift_id'         => $sale->shift_id ?? $shift?->id,
                        'business_date'    => $sale->business_date?->toDateString() ?? $businessDate,
                    ]));
                } else {
                    $sale = SalesOrder::create(array_merge($saleFields, $idempotencyFields, [
                        'sale_no'             => $salesService->nextSaleNo(),
                        'status'              => 'draft',
                        'created_by_user_id'  => auth('tenant')->id(),
                    ]));
                }

                // BUG-003 FIX: increment promo usage atomically with a guard so
                // concurrent sales cannot bypass the usage_limit. Must run inside
                // the sale transaction so it rolls back if the sale fails.
                if ($totals['promotion_id']) {
                    $updated = \App\Models\Tenant\Promotion::where('id', $totals['promotion_id'])
                        ->where(function ($q) {
                            $q->whereNull('usage_limit')
                              ->orWhereRaw('used_count < usage_limit');
                        })
                        ->increment('used_count');

                    if (! $updated) {
                        throw new RuntimeException('Promotion usage limit has been reached. Please try without the promo code.');
                    }
                }

                // Create lines using resolved prices
                $createdLinesByClientKey = [];
                foreach ($resolvedLines as $line) {
                    $product   = $line['_product'];
                    $variant   = $line['_variant'];
                    $qty       = (float) $line['quantity'];
                    $unitPrice = (float) $line['unit_price'];
                    $disc      = (float) $line['discount_amount'];
                    $lineTax   = (float) $line['tax_amount'];
                    $lineTotal = ($qty * $unitPrice) - $disc + $lineTax;
                    $parentClientKey = $line['parent_client_line_key'] ?? null;
                    $oldLineId = !empty($line['sales_order_line_id']) ? (int) $line['sales_order_line_id'] : null;
                    $sentQuantity = $oldLineId ? min((float) ($kotSentByLineId[$oldLineId] ?? 0), $qty) : 0;

                    $createdLine = $sale->lines()->create([
                        'parent_sales_order_line_id' => $parentClientKey && isset($createdLinesByClientKey[$parentClientKey])
                            ? $createdLinesByClientKey[$parentClientKey]->id
                            : null,
                        'line_kind'          => $line['line_kind'] ?? 'standard',
                        'combo_id'           => $line['combo_id'] ?? null,
                        'product_id'         => $product->id,
                        'product_variant_id' => $variant?->id,
                        'product_name'       => (($line['line_kind'] ?? 'standard') === 'combo_header' && !empty($line['line_name']))
                            ? $line['line_name']
                            : $product->name,
                        'variant_name'       => $variant?->name,
                        'unit_code'          => $product->unit?->code,
                        'quantity'           => $qty,
                        'unit_price'         => $unitPrice,
                        'unit_cost'          => 0,
                        'cost_total'         => 0,
                        'discount_amount'    => $disc,
                        'tax_amount'         => $lineTax,
                        'line_total'         => $lineTotal,
                        'modifiers'          => $line['modifiers'] ?? [],
                        'kot_sent'           => $sentQuantity > 0,
                        'kot_sent_quantity'  => $sentQuantity,
                    ]);

                    if (!empty($line['client_line_key'])) {
                        $createdLinesByClientKey[$line['client_line_key']] = $createdLine;
                    }
                }

                if ($cancellationBatch) {
                    $sale->unsetRelation('lines');
                    $cancellationService->queueCorrectionReminders($sale, $cancellationBatch);
                }

                foreach ($payments as $payment) {
                    $method   = PaymentMethod::findOrFail($payment['payment_method_id']);
                    $amount   = (float) $payment['amount'];
                    $tendered = isset($payment['tendered_amount']) && $payment['tendered_amount'] !== null
                        ? (float) $payment['tendered_amount']
                        : null;

                    $sale->payments()->create([
                        'payment_method_id' => $method->id,
                        'amount'            => $amount,
                        'tendered_amount'   => $tendered,
                        'change_amount'     => $method->method_type === 'cash' && $tendered !== null
                            ? max($tendered - $amount, 0)
                            : 0,
                        'bank_name'         => $payment['bank_name'] ?? null,
                        'account_no'        => $payment['account_no'] ?? null,
                        'transaction_ref'   => $payment['transaction_ref'] ?? null,
                        'card_last_four'    => $payment['card_last_four'] ?? null,
                        'cheque_no'         => $payment['cheque_no'] ?? null,
                        'cheque_date'       => $payment['cheque_date'] ?? null,
                        'notes'             => $payment['notes'] ?? null,
                    ]);
                }

                return $salesService->finalizePaidSale($sale);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // SALE-IDEMPOTENCY-1/HARDEN-1: a concurrent identical request won the
            // race. Bounded retry until its (atomic) sale is visible, then replay /
            // conflict per the same rules. If it truly can't be resolved in budget,
            // return a retryable PENDING (never a 500) — the caller retries same uuid.
            if ($clientUuid !== null) {
                if ($winner = $idempotency->resolveFinalizedWithRetry($clientUuid)) {
                    return $this->idempotentReplayOrThrow($request, $idempotency, $winner, $payloadHash, $printOrchestrator);
                }
                throw new \App\Exceptions\SaleIdempotencyConflictException(null, \App\Exceptions\SaleIdempotencyConflictException::CODE_PENDING);
            }
            throw $e;
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->withErrors(['sale' => $e->getMessage()])->withInput();
        }

        $printing = $directPayPrintState ? $printOrchestrator->orchestrate($sale) : null;

        return $this->saleResponse($request, $sale, false, $printing);
    }

    /**
     * SALE-IDEMPOTENCY-HARDEN-1: given an existing sale under this client_uuid,
     * decide replay vs conflict vs unverifiable — used by both the pre-check and
     * the concurrent-race resolution so they behave identically.
     *   verified hash matches   → replay (return existing sale)
     *   verified hash differs   → 409 SALE_IDEMPOTENCY_CONFLICT
     *   no stored hash          → 409 SALE_IDEMPOTENCY_UNVERIFIABLE (never silently replay)
     */
    private function idempotentReplayOrThrow(Request $request, \App\Services\Sales\SaleIdempotencyService $idempotency, SalesOrder $existing, string $payloadHash, DirectPayPrintOrchestrator $printOrchestrator)
    {
        if (! $idempotency->hasVerifiableHash($existing)) {
            throw new \App\Exceptions\SaleIdempotencyConflictException($existing->id, \App\Exceptions\SaleIdempotencyConflictException::CODE_UNVERIFIABLE);
        }

        if ($idempotency->payloadMatches($existing, $payloadHash)) {
            $printing = $existing->direct_pay_print_state
                ? $printOrchestrator->orchestrate($existing)
                : null;

            return $this->saleResponse($request, $existing, true, $printing);
        }

        throw new \App\Exceptions\SaleIdempotencyConflictException($existing->id);
    }

    /**
     * SALE-IDEMPOTENCY-1: unified sale response for a fresh sale or an idempotent
     * replay. The `idempotent_replay` flag lets the POS know this sale already
     * posted (it still ensures print jobs; see POS JS).
     */
    private function saleResponse(Request $request, SalesOrder $sale, bool $replay, ?array $printing = null): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'sale_id'           => $sale->id,
                'sale_no'           => $sale->sale_no,
                'redirect'          => url('/sales-orders/' . $sale->id),
                'idempotent_replay' => $replay,
                'printing'          => $printing,
            ]);
        }

        return redirect(url('/sales-orders/' . $sale->id))
            ->with('status', $replay ? 'This sale was already completed.' : 'Sale posted successfully.');
    }

    /**
     * SALE-IDEMPOTENCY-1: the customer's INTENDED sale, canonicalized for hashing.
     * INCLUDES the material intent (branch/terminal/customer/order type + delivery,
     * each line's product/variant/qty/price/discount/tax/modifiers/kind, and each
     * payment's method/amount/tender, plus discount/promo/tip). EXCLUDES CSRF, key
     * order, server-generated sale_no/ids, and browser-only fields.
     */
    private function canonicalSalePayload(array $data): array
    {
        // EDGE-LOCAL-POS-1: single source of truth shared with the offline Branch Server.
        return app(\App\Services\Sales\SaleIdempotencyService::class)->canonicalSalePayload($data);
    }

    public function show(SalesOrder $salesOrder)
    {
        // USER-DATA-SCOPE-1: out-of-scope sale ids are refused, not just hidden from the list.
        abort_if(
            app(\App\Services\Security\UserDataScope::class)->deniesSale(auth('tenant')->user(), $salesOrder),
            403,
            'This order belongs to another terminal or order type.'
        );

        $salesOrder->load([
            'branch',
            'terminal',
            'shift',
            'customer',
            'createdBy',
            'lines.product',
            'lines.variant',
            'payments.method',
            'ledgerEntries',
        ]);

        return view('tenant.sales-orders.show', compact('salesOrder'));
    }

    public function cancel(SalesOrder $salesOrder)
    {
        app(\App\Services\Edge\BranchOperatingModeService::class)
            ->assertSaleMutationAllowed(Branch::findOrFail($salesOrder->branch_id));

        if ($salesOrder->status === 'paid' || $salesOrder->inventory_posted) {
            return back()->withErrors([
                'sale' => 'Paid sale cannot be cancelled. Use sales return flow.',
            ]);
        }

        $salesOrder->update(['status' => 'cancelled']);

        return back()->with('status', 'Sale cancelled successfully.');
    }

    private function validateSale(Request $request): array
    {
        $data = $request->validate([
            'branch_id'    => ['required', 'exists:branches,id'],
            'terminal_id'  => ['nullable', 'exists:terminals,id'],
            'customer_id'  => ['nullable', 'exists:customers,id'],
            'customer_name'  => ['nullable', 'string', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'customer_email' => ['nullable', 'email', 'max:190'],
            'held_sale_id'                => ['nullable', 'exists:sales_orders,id'],
            'restaurant_table_session_id' => ['nullable', 'exists:restaurant_table_sessions,id'],
            'create_separate_order'       => ['nullable', 'boolean'],
            'order_source'        => ['nullable', Rule::in(['pos', 'manual'])],
            'client_uuid'         => ['nullable', 'string', 'max:36'],
            'kot_print_intent'    => ['nullable', Rule::in(['print', 'skip'])],
            'receipt_print_intent' => ['nullable', Rule::in(['print', 'skip'])],
            'order_type'          => ['required', Rule::in(['quick_sale', 'takeaway', 'dine_in', 'delivery'])],
            'delivery_channel_id' => ['nullable', 'exists:delivery_channels,id'],
            'delivery_rider_id'   => ['nullable', 'exists:delivery_riders,id'],
            'delivery_address'    => ['nullable', 'string', 'max:500'],
            'delivery_charge_amount' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'vehicle_number'      => ['nullable', 'string', 'max:50'],
            'discount_type'       => ['required', Rule::in(['none', 'fixed', 'percent'])],
            'discount_value'      => ['nullable', 'numeric', 'min:0'],
            'promo_code'          => ['nullable', 'string', 'max:50'],
            'tip_amount'          => ['nullable', 'numeric', 'min:0'],
            'manager_approval_id' => ['nullable', 'exists:manager_approvals,id'],
            'notes'               => ['nullable', 'string'],

            'lines'                      => ['required', 'array'],
            'lines.*.product_id'         => ['nullable', 'exists:products,id'],
            'lines.*.sales_order_line_id' => ['nullable', 'integer'],
            'lines.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'lines.*.client_line_key'    => ['nullable', 'string', 'max:120'],
            'lines.*.parent_client_line_key' => ['nullable', 'string', 'max:120'],
            'lines.*.line_kind'          => ['nullable', Rule::in(['standard', 'combo_header', 'component', 'modifier'])],
            'lines.*.combo_id'           => ['nullable', 'exists:combos,id'],
            'lines.*.line_name'          => ['nullable', 'string', 'max:190'],
            'lines.*.quantity'           => ['nullable', 'numeric', 'min:0.001'],
            'lines.*.unit_price'         => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_amount'    => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_amount'         => ['nullable', 'numeric', 'min:0'],
            'lines.*.modifiers'          => ['nullable', 'string'],

            'void_items'                            => ['nullable', 'array'],
            'void_items.*.old_line_id'              => ['required_with:void_items', 'integer'],
            'void_items.*.reason_id'                => ['required_with:void_items', 'exists:void_reasons,id'],
            'void_items.*.quantity'                 => ['required_with:void_items', 'numeric', 'gt:0'],
            'void_items.*.manager_approval_id'      => ['nullable', 'exists:manager_approvals,id'],

            'payments'                         => ['required', 'array'],
            'payments.*.payment_method_id'     => ['nullable', 'exists:payment_methods,id'],
            'payments.*.amount'                => ['nullable', 'numeric', 'min:0.01'],
            'payments.*.tendered_amount'       => ['nullable', 'numeric', 'min:0'],
            'payments.*.bank_name'             => ['nullable', 'string', 'max:190'],
            'payments.*.account_no'            => ['nullable', 'string', 'max:190'],
            'payments.*.transaction_ref'       => ['nullable', 'string', 'max:190'],
            'payments.*.card_last_four'        => ['nullable', 'string', 'max:10'],
            'payments.*.cheque_no'             => ['nullable', 'string', 'max:190'],
            'payments.*.cheque_date'           => ['nullable', 'date'],
            'payments.*.notes'                 => ['nullable', 'string'],
        ]);

        return $this->validateDeliveryAttribution($data);
    }

    public function retryDirectPayPrinting(SalesOrder $salesOrder, DirectPayPrintOrchestrator $printOrchestrator)
    {
        if (!$salesOrder->direct_pay_print_state) {
            return response()->json(['message' => 'This sale has no Direct Pay print intent.'], 422);
        }
        if ($salesOrder->status !== 'paid') {
            return response()->json(['message' => 'Only a paid sale can resume Direct Pay printing.'], 422);
        }

        return response()->json([
            'sale_id' => $salesOrder->id,
            'sale_no' => $salesOrder->sale_no,
            'printing' => $printOrchestrator->orchestrate($salesOrder),
        ]);
    }

    private function validateDeliveryAttribution(array $data): array
    {
        if (($data['order_type'] ?? null) !== 'delivery' || ! empty($data['restaurant_table_session_id'])) {
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

    private function resolveSellingPrice(Product $product, $variant, int $branchId, ?float $submittedPrice): float
    {
        // EDGE-LOCAL-POS-1: single source of truth shared with the offline Branch Server.
        return app(\App\Services\Sales\SalePricingService::class)
            ->resolveSellingPrice($product, $variant, $branchId, $submittedPrice);
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

    private function resolveTaxAmount(Product $product, float $quantity, float $unitPrice, float $lineDiscount, ?float $submittedTax): float
    {
        // EDGE-LOCAL-POS-1: single source of truth shared with the offline Branch Server.
        return app(\App\Services\Sales\SalePricingService::class)
            ->resolveTaxAmount($product, $quantity, $unitPrice, $lineDiscount, $submittedTax);
    }

    /**
     * SHIFT-TIMEZONE-BUSINESS-DATE-1 — resolve the open shift for a POS operation.
     *
     * A POS business operation requires an open shift on its terminal (universal — the rule
     * ignores terminals.requires_shift, so no terminal can bypass it). Non-POS order sources
     * (imported/online orders that never touch a terminal) are exempt. Delegates to the one
     * canonical ShiftService so Cloud and future Edge enforce identically.
     */
    private function resolveOpenShift(?Terminal $terminal, string $orderSource): ?Shift
    {
        $shiftService = app(ShiftService::class);

        // HARDEN-1: called inside the sale transaction — LOCK the open shift (FOR UPDATE) and
        // re-validate under the lock, so a concurrent shift close cannot close it out from under
        // this in-flight sale (no TOCTOU). Non-POS sources (imported/online) stay lenient.
        if ($orderSource === 'pos') {
            return $shiftService->lockOpenShiftForTerminal($terminal);
        }

        return $shiftService->activeShiftForTerminal($terminal);
    }
}
