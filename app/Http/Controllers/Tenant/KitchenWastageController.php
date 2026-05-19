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
        $branches = Branch::where('status', 'active')->orderBy('name')->get();
        $products = Product::where('status', 'active')->orderBy('name')->get();
        $units    = Unit::orderBy('name')->get();

        return view('tenant.kitchen.wastages.create', compact('branches', 'products', 'units'));
    }

    public function store(Request $request, KitchenWastageService $kitchenWastageService)
    {
        $data = $request->validate([
            'branch_id'          => ['required', 'exists:branches,id'],
            'product_id'         => ['required', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'quantity'           => ['required', 'numeric', 'min:0.0001'],
            'unit_id'            => ['nullable', 'exists:units,id'],
            'reason'             => ['nullable', 'string', 'max:255'],
            'wastage_date'       => ['required', 'date'],
        ]);

        $branch  = Branch::findOrFail($data['branch_id']);
        $product = Product::findOrFail($data['product_id']);
        $variant = !empty($data['product_variant_id']) ? ProductVariant::find($data['product_variant_id']) : null;

        try {
            $wastage = $kitchenWastageService->record($data, $branch, $product, $variant, Auth::id());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['wastage' => $e->getMessage()])->withInput();
        }

        return redirect(url('/kitchen/wastages/' . $wastage->id))->with('status', 'Wastage recorded: ' . $wastage->wastage_no);
    }

    public function show(KitchenWastage $kitchenWastage)
    {
        $kitchenWastage->load(['branch', 'product', 'variant', 'unit', 'recordedBy']);

        return view('tenant.kitchen.wastages.show', compact('kitchenWastage'));
    }
}
