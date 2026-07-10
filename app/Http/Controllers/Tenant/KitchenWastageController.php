<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\KitchenWastage;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\Unit;
use App\Services\Kitchen\KitchenWastageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class KitchenWastageController extends Controller
{
    public function index(Request $request)
    {
        $query = KitchenWastage::with(['branch', 'product', 'variant', 'unit', 'recordedBy'])
            ->orderByDesc('wastage_date')
            ->orderByDesc('id');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $wastages = $query->paginate(20)->withQueryString();
        $branches = Branch::where('status', 'active')->orderBy('name')->get();
        $products = Product::where('status', 'active')->orderBy('name')->get();

        return view('tenant.kitchen.wastages.index', compact('wastages', 'branches', 'products'));
    }

    public function create()
    {
        return view('tenant.kitchen.wastages.create', [
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, KitchenWastageService $kitchenWastageService, \App\Services\Inventory\InventoryService $inventoryService)
    {
        // WASTAGE-UX-1: multi-line document instead of one product per submit.
        $data = $request->validate([
            'branch_id'                  => ['required', 'exists:branches,id'],
            'wastage_date'               => ['required', 'date'],
            'lines'                      => ['required', 'array', 'min:1'],
            'lines.*.product_id'         => ['nullable', 'exists:products,id'],
            'lines.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'lines.*.quantity'           => ['nullable', 'numeric', 'min:0.0001'],
            'lines.*.reason'             => ['nullable', 'string', 'max:255'],
        ]);

        $lines = collect($data['lines'])
            ->filter(fn ($line) => !empty($line['product_id']) && !empty($line['quantity']))
            ->values();

        if ($lines->isEmpty()) {
            return back()->withErrors(['wastage' => 'At least one product line with quantity is required.'])->withInput();
        }

        $branch  = Branch::findOrFail($data['branch_id']);
        $created = [];

        try {
            \Illuminate\Support\Facades\DB::connection('tenant')->transaction(function () use ($lines, $data, $branch, $kitchenWastageService, $inventoryService, &$created) {
                foreach ($lines as $line) {
                    $product = Product::findOrFail($line['product_id']);
                    // BUG FIX: stock lives under the product's DEFAULT variant — a null
                    // variant made postOutFefo find nothing and fail "Insufficient stock".
                    $variant = $inventoryService->resolveVariant($product, $line['product_variant_id'] ?? null);

                    $created[] = $kitchenWastageService->record([
                        'quantity'     => (float) $line['quantity'],
                        'reason'       => $line['reason'] ?? null,
                        'wastage_date' => $data['wastage_date'],
                    ], $branch, $product, $variant, Auth::id());
                }
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['wastage' => $e->getMessage()])->withInput();
        }

        $nos = collect($created)->pluck('wastage_no')->implode(', ');

        return redirect(url('/kitchen/wastages'))
            ->with('status', count($created) . ' wastage record(s) posted: ' . $nos);
    }

    public function show(KitchenWastage $kitchenWastage)
    {
        $kitchenWastage->load(['branch', 'product', 'variant', 'unit', 'recordedBy']);

        return view('tenant.kitchen.wastages.show', compact('kitchenWastage'));
    }
}
