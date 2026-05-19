<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\InventoryBatch;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $query = StockBalance::query()
            ->with(['branch', 'product', 'variant', 'batch'])
            ->where('quantity_on_hand', '!=', 0)
            ->latest();

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return view('tenant.inventory.index', [
            'balances' => $query->paginate(15)->withQueryString(),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function movements(Request $request)
    {
        $query = StockLedger::query()
            ->with(['branch', 'product', 'variant', 'batch', 'createdBy'])
            ->latest();

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('movement_type')) {
            $query->where('movement_type', $request->movement_type);
        }

        return view('tenant.inventory.movements', [
            'ledgers'  => $query->paginate(20)->withQueryString(),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function batches(Request $request)
    {
        $query = InventoryBatch::query()
            ->with(['branch', 'product', 'variant'])
            ->latest();

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('expiry_status')) {
            if ($request->expiry_status === 'expired') {
                $query->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now());
            }

            if ($request->expiry_status === 'expiring') {
                $query->whereNotNull('expiry_date')
                    ->whereBetween('expiry_date', [
                        now()->toDateString(),
                        now()->addDays(30)->toDateString(),
                    ]);
            }
        }

        return view('tenant.inventory.batches', [
            'batches'  => $query->paginate(15)->withQueryString(),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function lowStock(Request $request)
    {
        $products = Product::query()
            ->with(['defaultVariant', 'stockBalances'])
            ->where('is_stock_tracked', true)
            ->whereHas('defaultVariant', fn ($q) => $q->where('reorder_level', '>', 0))
            ->get()
            ->filter(function (Product $product) {
                $qty     = $product->stockBalances->sum('quantity_on_hand');
                $reorder = (float) ($product->defaultVariant?->reorder_level ?? 0);

                return $reorder > 0 && $qty <= $reorder;
            });

        return view('tenant.inventory.low-stock', compact('products'));
    }

    public function expiryAlerts(Request $request)
    {
        $days = (int) $request->input('days', 30);

        $batches = InventoryBatch::query()
            ->with(['branch', 'product', 'variant', 'balances'])
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($days))
            ->orderBy('expiry_date')
            ->paginate(20)
            ->withQueryString();

        return view('tenant.inventory.expiry-alerts', compact('batches', 'days'));
    }
}
