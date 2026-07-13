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

    public function store(Request $request, SalesService $salesService, InventoryService $inventoryService, SalesTotalsService $totalsService)
    {
        $data = $this->validateSale($request);

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

        try {
            $sale = DB::connection('tenant')->transaction(function () use (
                $data, $lines, $payments, $salesService, $inventoryService, $totalsService
            ) {
                $branch   = Branch::findOrFail($data['branch_id']);
                $terminal = !empty($data['terminal_id']) ? Terminal::find($data['terminal_id']) : null;
                $shift    = $this->resolveOpenShift($terminal);
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
                );

                $tableSession = null;

                if (!empty($data['restaurant_table_session_id'])) {
                    $tableSession = RestaurantTableSession::with(['table.floor', 'waiter'])
                        ->whereIn('status', ['open', 'bill_requested'])
                        ->findOrFail($data['restaurant_table_session_id']);
                } elseif (!empty($data['held_sale_id'])) {
                    // If the held sale already has a session (auto-created on hold), inherit it
                    $heldForSession = SalesOrder::find($data['held_sale_id']);
                    if ($heldForSession?->restaurant_table_session_id) {
                        $tableSession = RestaurantTableSession::with(['table.floor', 'waiter'])
                            ->whereIn('status', ['open', 'bill_requested'])
                            ->find($heldForSession->restaurant_table_session_id);
                    }
                }

                // Guard: block accidental direct-pay against a table that still has open held orders
                if (
                    $tableSession
                    && empty($data['held_sale_id'])
                    && empty($data['create_separate_order'])
                ) {
                    $hasOpenHeldOrders = SalesOrder::where('restaurant_table_session_id', $tableSession->id)
                        ->where('status', 'held')
                        ->exists();

                    if ($hasOpenHeldOrders) {
                        throw new RuntimeException('This table already has open held orders. Recall an existing order or intentionally create a separate order.');
                    }
                }

                $saleFields = [
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
                    'sale_date'                   => now(),
                    'subtotal'                    => $totals['subtotal'],
                    'discount_type'               => $data['discount_type'],
                    'discount_value'              => $data['discount_value'] ?? 0,
                    'discount_amount'             => $totals['discount_amount'],
                    'tax_amount'                  => $totals['tax_amount'],
                    'service_charge_amount'       => $totals['service_charge_amount'],
                    'tip_amount'                  => $totals['tip_amount'],
                    'grand_total'                 => $totals['grand_total'],
                    'notes'                       => $data['notes'] ?? null,
                ];

                if (!empty($data['held_sale_id'])) {
                    $sale = SalesOrder::where('status', 'held')->findOrFail($data['held_sale_id']);
                    $sale->lines()->delete();
                    $sale->payments()->delete();
                    $sale->update(array_merge($saleFields, [
                        'paid_amount'      => 0,
                        'change_amount'    => 0,
                        'status'           => 'draft',
                        'inventory_posted' => false,
                        'completed_at'     => null,
                    ]));
                } else {
                    $sale = SalesOrder::create(array_merge($saleFields, [
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
                    ]);

                    if (!empty($line['client_line_key'])) {
                        $createdLinesByClientKey[$line['client_line_key']] = $createdLine;
                    }
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
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->withErrors(['sale' => $e->getMessage()])->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'sale_id'  => $sale->id,
                'sale_no'  => $sale->sale_no,
                'redirect' => url('/sales-orders/' . $sale->id),
            ]);
        }

        return redirect(url('/sales-orders/' . $sale->id))->with('status', 'Sale posted successfully.');
    }

    public function show(SalesOrder $salesOrder)
    {
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
            'order_type'          => ['required', Rule::in(['quick_sale', 'takeaway', 'dine_in', 'delivery'])],
            'delivery_channel_id' => ['nullable', 'exists:delivery_channels,id'],
            'delivery_rider_id'   => ['nullable', 'exists:delivery_riders,id'],
            'discount_type'       => ['required', Rule::in(['none', 'fixed', 'percent'])],
            'discount_value'      => ['nullable', 'numeric', 'min:0'],
            'promo_code'          => ['nullable', 'string', 'max:50'],
            'tip_amount'          => ['nullable', 'numeric', 'min:0'],
            'manager_approval_id' => ['nullable', 'exists:manager_approvals,id'],
            'notes'               => ['nullable', 'string'],

            'lines'                      => ['required', 'array'],
            'lines.*.product_id'         => ['nullable', 'exists:products,id'],
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
        // BUG-015 FIX: accept an explicitly submitted price of 0 as a legitimate
        // free/complimentary item. Only fall back to catalog price when null (i.e.
        // the field was absent from the request, not when it was intentionally set to 0).
        if ($submittedPrice !== null) {
            return $submittedPrice; // includes 0 for free items
        }

        $branchPrice = $product->branchPrices()
            ->where('branch_id', $branchId)
            ->where(function ($q) use ($variant) {
                if ($variant) {
                    $q->where('product_variant_id', $variant->id)->orWhereNull('product_variant_id');
                } else {
                    $q->whereNull('product_variant_id');
                }
            })
            ->where('is_available', true)
            ->orderByRaw('product_variant_id IS NULL')
            ->first();

        if ($branchPrice) {
            return (float) $branchPrice->selling_price;
        }

        if ($variant && (float) ($variant->selling_price ?? 0) > 0) {
            return (float) $variant->selling_price;
        }

        return (float) ($product->default_selling_price ?? 0);
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
        if ($submittedTax !== null && $submittedTax > 0) {
            return $submittedTax;
        }

        if (!(bool) ($product->is_taxable ?? false) || (float) ($product->tax_rate_percent ?? 0) <= 0) {
            return 0;
        }

        $taxableAmount = max(($quantity * $unitPrice) - $lineDiscount, 0);

        return round(($taxableAmount * (float) $product->tax_rate_percent) / 100, 2);
    }

    private function resolveOpenShift(?Terminal $terminal): ?Shift
    {
        if (!$terminal) {
            return null;
        }

        $shift = Shift::where('terminal_id', $terminal->id)
            ->where('status', 'open')
            ->latest()
            ->first();

        if ($terminal->requires_shift && !$shift) {
            throw new RuntimeException('Selected terminal requires an open shift.');
        }

        return $shift;
    }
}
