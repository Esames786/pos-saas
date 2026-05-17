<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HeldSaleController extends Controller
{
    public function index()
    {
        $heldSales = SalesOrder::with(['branch', 'customer', 'restaurantTable', 'restaurantWaiter'])
            ->where('status', 'held')
            ->orderByDesc('updated_at')
            ->paginate(25);

        return view('tenant.held-sales.index', compact('heldSales'));
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

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'                   => 'required|exists:branches,id',
            'terminal_id'                 => 'nullable|exists:terminals,id',
            'order_type'                  => 'required|in:quick_sale,takeaway,dine_in,delivery',
            'restaurant_table_session_id' => 'nullable|exists:restaurant_table_sessions,id',
            'customer_id'                 => 'nullable|exists:customers,id',
            'customer_name'               => 'nullable|string|max:200',
            'customer_phone'              => 'nullable|string|max:50',
            'customer_email'              => 'nullable|email',
            'discount_type'               => 'required|in:none,fixed,percent',
            'discount_value'              => 'nullable|numeric|min:0',
            'notes'                       => 'nullable|string',
            'lines'                       => 'required|array|min:1',
            'lines.*.product_id'          => 'required_with:lines.*.quantity|nullable|exists:products,id',
            'lines.*.product_variant_id'  => 'nullable|exists:product_variants,id',
            'lines.*.quantity'            => 'nullable|numeric|min:0.001',
            'lines.*.unit_price'          => 'nullable|numeric|min:0',
        ]);

        $lines = collect($data['lines'])->filter(fn ($l) => !empty($l['product_id']) && !empty($l['quantity']));

        if ($lines->isEmpty()) {
            return back()->withErrors(['lines' => 'At least one product line is required.'])->withInput();
        }

        $subtotal = 0;
        foreach ($lines as $line) {
            $subtotal += (float) $line['quantity'] * (float) ($line['unit_price'] ?? 0);
        }

        $discountValue  = (float) ($data['discount_value'] ?? 0);
        $discountAmount = match ($data['discount_type']) {
            'fixed'   => min($discountValue, $subtotal),
            'percent' => round($subtotal * $discountValue / 100, 2),
            default   => 0,
        };

        $grandTotal = $subtotal - $discountAmount;

        $tableSession = null;
        if (!empty($data['restaurant_table_session_id'])) {
            $tableSession = RestaurantTableSession::with('table')->find($data['restaurant_table_session_id']);
            $data['order_type'] = 'dine_in';
        }

        $saleNo = 'HS-' . now()->format('YmdHis') . '-' . random_int(100, 999);

        $sale = SalesOrder::create([
            'sale_no'                     => $saleNo,
            'branch_id'                   => $data['branch_id'],
            'terminal_id'                 => $data['terminal_id'] ?? null,
            'order_source'                => 'pos',
            'order_type'                  => $data['order_type'],
            'customer_id'                 => $data['customer_id'] ?? null,
            'customer_name'               => $data['customer_name'] ?? null,
            'customer_phone'              => $data['customer_phone'] ?? null,
            'customer_email'              => $data['customer_email'] ?? null,
            'sale_date'                   => now(),
            'subtotal'                    => $subtotal,
            'discount_type'               => $data['discount_type'],
            'discount_value'              => $discountValue,
            'discount_amount'             => $discountAmount,
            'tax_amount'                  => 0,
            'grand_total'                 => $grandTotal,
            'status'                      => 'held',
            'inventory_posted'            => false,
            'created_by_user_id'          => Auth::id(),
            'notes'                       => $data['notes'] ?? null,
            'restaurant_table_session_id' => $tableSession?->id,
            'restaurant_table_id'         => $tableSession?->restaurant_table_id,
            'restaurant_floor_id'         => $tableSession?->table?->restaurant_floor_id,
            'restaurant_waiter_id'        => $tableSession?->restaurant_waiter_id,
        ]);

        foreach ($lines as $line) {
            $product = Product::find($line['product_id']);
            $variant = !empty($line['product_variant_id'])
                ? ProductVariant::find($line['product_variant_id'])
                : null;

            $lineTotal = (float) $line['quantity'] * (float) ($line['unit_price'] ?? 0);

            $sale->lines()->create([
                'product_id'         => $line['product_id'],
                'product_variant_id' => $line['product_variant_id'] ?? null,
                'product_name'       => $product?->name ?? '',
                'variant_name'       => $variant?->name ?? null,
                'quantity'           => $line['quantity'],
                'unit_price'         => $line['unit_price'] ?? 0,
                'unit_cost'          => 0,
                'cost_total'         => 0,
                'discount_amount'    => 0,
                'tax_amount'         => 0,
                'line_total'         => $lineTotal,
            ]);
        }

        return redirect(url('/held-sales'))->with('status', "Held sale {$sale->sale_no} created.");
    }

    public function cancel(SalesOrder $salesOrder)
    {
        if ($salesOrder->status !== 'held') {
            return back()->withErrors(['sale' => 'Only held sales can be cancelled.']);
        }

        $salesOrder->update(['status' => 'cancelled']);

        return redirect(url('/held-sales'))->with('status', 'Held sale cancelled.');
    }
}
