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

        $floors = RestaurantFloor::with([
                'tables.openSession.waiter',
                'tables.openSession.salesOrders' => function ($query) {
                    $query->whereIn('status', ['held', 'paid']);
                },
            ])
            ->where('branch_id', $selectedBranchId)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $waiters = RestaurantWaiter::where(function ($query) use ($selectedBranchId) {
                $query->whereNull('branch_id')
                    ->orWhere('branch_id', $selectedBranchId);
            })
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $products = Product::with([
                'category',
                'defaultVariant',
                'variants',
                'barcodes',
                'branchPrices',
            ])
            ->where('status', 'active')
            ->where('is_sellable', true)
            ->orderBy('name')
            ->get();

        $stockByProduct = StockBalance::query()
            ->selectRaw('branch_id, product_id, product_variant_id, SUM(quantity_on_hand) as qty')
            ->groupBy('branch_id', 'product_id', 'product_variant_id')
            ->get()
            ->groupBy(fn ($row) => $row->branch_id . ':' . $row->product_id . ':' . ($row->product_variant_id ?: 0));

        $productsPayload = $products->map(function ($product) use ($stockByProduct) {
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
                    'id'             => (int) $variant->id,
                    'name'           => $variant->name,
                    'sku'            => $variant->sku,
                    'selling_price'  => (float) ($variant->selling_price ?? $product->default_selling_price ?? $product->selling_price ?? 0),
                    'stock_by_branch' => $stockMap,
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

            return [
                'id'               => (int) $product->id,
                'name'             => $product->name,
                'sku'              => $product->sku,
                'category_id'      => $product->category_id ? (int) $product->category_id : null,
                'category_name'    => $product->category?->name,
                'price'            => (float) ($defaultVariant?->selling_price ?? $product->default_selling_price ?? $product->selling_price ?? 0),
                'is_stock_tracked' => (bool) $product->is_stock_tracked,
                'is_taxable'       => (bool) ($product->is_taxable ?? false),
                'tax_rate_percent' => (float) ($product->tax_rate_percent ?? 0),
                'barcodes'         => $barcodes,
                'branch_prices'    => $branchPrices,
                'variants'         => $variants,
                'stock_by_branch'  => $stockMap,
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
