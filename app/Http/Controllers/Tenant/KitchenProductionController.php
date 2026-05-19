<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\KitchenProduction;
use App\Models\Tenant\Recipe;
use App\Services\Kitchen\KitchenProductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class KitchenProductionController extends Controller
{
    public function index(Request $request)
    {
        $query = KitchenProduction::with(['branch', 'recipe.product'])
            ->orderByDesc('production_date')
            ->orderByDesc('id');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $productions = $query->paginate(20)->withQueryString();
        $branches    = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.kitchen.productions.index', compact('productions', 'branches'));
    }

    public function create()
    {
        $branches = Branch::where('status', 'active')->orderBy('name')->get();
        $recipes  = Recipe::with('product')->where('is_active', true)->orderBy('name')->get();

        return view('tenant.kitchen.productions.create', compact('branches', 'recipes'));
    }

    public function store(Request $request, KitchenProductionService $kitchenProductionService)
    {
        $data = $request->validate([
            'branch_id'         => ['required', 'exists:branches,id'],
            'recipe_id'         => ['required', 'exists:recipes,id'],
            'quantity_produced' => ['required', 'numeric', 'min:0.0001'],
            'production_date'   => ['required', 'date'],
            'notes'             => ['nullable', 'string'],
        ]);

        $branch = Branch::findOrFail($data['branch_id']);
        $recipe = Recipe::with(['ingredients.product', 'ingredients.variant', 'ingredients.unit'])->findOrFail($data['recipe_id']);

        try {
            $production = $kitchenProductionService->record($data, $branch, $recipe, Auth::id());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['production' => $e->getMessage()])->withInput();
        }

        return redirect(url('/kitchen/productions/' . $production->id))->with('status', 'Production planned: ' . $production->production_no);
    }

    public function show(KitchenProduction $kitchenProduction)
    {
        $kitchenProduction->load(['branch', 'recipe.product', 'yieldUnit', 'producedBy', 'ingredients.product', 'ingredients.variant', 'ingredients.unit']);

        return view('tenant.kitchen.productions.show', compact('kitchenProduction'));
    }

    public function complete(Request $request, KitchenProduction $kitchenProduction, KitchenProductionService $kitchenProductionService)
    {
        $kitchenProduction->load('ingredients');

        $rules = [];
        foreach ($kitchenProduction->ingredients as $ing) {
            $rules["usages.{$ing->id}"] = ['nullable', 'numeric', 'min:0'];
        }

        $data = $request->validate($rules);

        $usages = $data['usages'] ?? [];

        try {
            $kitchenProductionService->complete($kitchenProduction, $usages, Auth::id());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['production' => $e->getMessage()]);
        }

        return redirect(url('/kitchen/productions/' . $kitchenProduction->id))->with('status', 'Production completed.');
    }
}
