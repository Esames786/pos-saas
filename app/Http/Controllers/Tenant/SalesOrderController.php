<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Customer;
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
                    $product = Product::findOrFail($line['product_id']);
                    $variant = $inventoryService->resolveVariant($product, $line['product_variant_id'] ?? null);
                    $qty     = (float) $line['quantity'];
                    $price   = $this->resolveSellingPrice($product, $variant, $branch->id,
                        isset($line['unit_price']) ? (float) $line['unit_price'] : null);
                    $disc    = (float) ($line['discount_amount'] ?? 0);
                    $tax     = $this->resolveTaxAmount($product, $qty, $price, $disc,
                        isset($line['tax_amount']) ? (float) $line['tax_amount'] : null);

                    return array_merge($line->toArray(), [
                        '_product' => $product,
                        '_variant' => $variant,
                        'unit_price'      => $price,
                        'discount_amount' => $disc,
                        'tax_amount'      => $tax,
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

                // Increment promo usage count once sale is committed
                if ($totals['promotion_id']) {
                    \App\Models\Tenant\Promotion::where('id', $totals['promotion_id'])->increment('used_count');
                }

                // Create lines using resolved prices
                foreach ($resolvedLines as $line) {
                    $product   = $line['_product'];
                    $variant   = $line['_variant'];
                    $qty       = (float) $line['quantity'];
                    $unitPrice = (float) $line['unit_price'];
                    $disc      = (float) $line['discount_amount'];
                    $lineTax   = (float) $line['tax_amount'];
                    $lineTotal = ($qty * $unitPrice) - $disc + $lineTax;

                    $sale->lines()->create([
                        'product_id'         => $product->id,
                        'product_variant_id' => $variant?->id,
                        'product_name'       => $product->name,
                        'variant_name'       => $variant?->name,
                        'quantity'           => $qty,
                        'unit_price'         => $unitPrice,
                        'unit_cost'          => 0,
                        'cost_total'         => 0,
                        'discount_amount'    => $disc,
                        'tax_amount'         => $lineTax,
                        'line_total'         => $lineTotal,
                    ]);
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
        return $request->validate([
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
            'discount_type'       => ['required', Rule::in(['none', 'fixed', 'percent'])],
            'discount_value'      => ['nullable', 'numeric', 'min:0'],
            'promo_code'          => ['nullable', 'string', 'max:50'],
            'tip_amount'          => ['nullable', 'numeric', 'min:0'],
            'manager_approval_id' => ['nullable', 'exists:manager_approvals,id'],
            'notes'               => ['nullable', 'string'],

            'lines'                      => ['required', 'array'],
            'lines.*.product_id'         => ['nullable', 'exists:products,id'],
            'lines.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'lines.*.quantity'           => ['nullable', 'numeric', 'min:0.001'],
            'lines.*.unit_price'         => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_amount'    => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_amount'         => ['nullable', 'numeric', 'min:0'],

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
    }

    private function resolveSellingPrice(Product $product, $variant, int $branchId, ?float $submittedPrice): float
    {
        if ($submittedPrice !== null && $submittedPrice > 0) {
            return $submittedPrice;
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
