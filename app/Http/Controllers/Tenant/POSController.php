<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Customer;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\Product;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\Terminal;
use Illuminate\Http\Request;

class POSController extends Controller
{
    public function index(Request $request)
    {
        $branches         = Branch::where('status', 'active')->orderBy('name')->get();
        $selectedBranchId = (int) ($request->branch_id ?: optional($branches->first())->id);

        $tableSession = null;

        if ($request->filled('table_session_id')) {
            $tableSession = RestaurantTableSession::with(['table.floor', 'waiter'])
                ->whereIn('status', ['open', 'bill_requested'])
                ->find($request->table_session_id);
        }

        $heldSale = null;

        if ($request->filled('held_sale_id')) {
            $heldSale = SalesOrder::with(['lines', 'customer', 'restaurantTableSession.table', 'restaurantWaiter'])
                ->where('status', 'held')
                ->find($request->held_sale_id);
        }

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
                        'branch_id'         => (int) $price->branch_id,
                        'product_variant_id' => $price->product_variant_id ? (int) $price->product_variant_id : null,
                        'selling_price'      => (float) $price->selling_price,
                    ];
                })->values()
                : collect();

            $variants = $product->variants->map(function ($variant) use ($product, $stockByProduct) {
                $stockMap = [];

                foreach ($stockByProduct as $rows) {
                    foreach ($rows as $row) {
                        if ((int) $row->product_id === (int) $product->id
                            && (int) ($row->product_variant_id ?: 0) === (int) $variant->id) {
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
                    if ((int) $row->product_id === (int) $product->id
                        && (int) ($row->product_variant_id ?: 0) === 0) {
                        $stockMap[(int) $row->branch_id] = (float) $row->qty;
                    }
                }
            }

            return [
                'id'              => (int) $product->id,
                'name'            => $product->name,
                'sku'             => $product->sku,
                'category_id'     => $product->category_id ? (int) $product->category_id : null,
                'category_name'   => $product->category?->name,
                'price'           => (float) ($defaultVariant?->selling_price ?? $product->default_selling_price ?? $product->selling_price ?? 0),
                'is_stock_tracked' => (bool) $product->is_stock_tracked,
                'is_taxable'      => (bool) ($product->is_taxable ?? false),
                'tax_rate_percent' => (float) ($product->tax_rate_percent ?? 0),
                'barcodes'        => $barcodes,
                'branch_prices'   => $branchPrices,
                'variants'        => $variants,
                'stock_by_branch' => $stockMap,
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
            'paymentMethods'   => PaymentMethod::where('is_active', true)->orderBy('name')->get(),
            'tableSession'     => $tableSession,
            'heldSale'         => $heldSale,
        ]);
    }
}
