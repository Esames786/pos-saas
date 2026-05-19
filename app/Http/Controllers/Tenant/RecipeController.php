<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Product;
use App\Models\Tenant\Recipe;
use App\Models\Tenant\Unit;
use App\Services\Kitchen\RecipeCostService;
use Illuminate\Http\Request;

class RecipeController extends Controller
{
    public function index(Request $request)
    {
        $query = Recipe::with(['product', 'yieldUnit'])
            ->orderByDesc('id');

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', (bool) $request->is_active);
        }

        $recipes  = $query->paginate(20)->withQueryString();
        $products = Product::where('status', 'active')->orderBy('name')->get();

        return view('tenant.recipes.index', compact('recipes', 'products'));
    }

    public function create()
    {
        $products = Product::where('status', 'active')->orderBy('name')->get();
        $units    = Unit::orderBy('name')->get();

        return view('tenant.recipes.create', compact('products', 'units'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id'              => ['required', 'exists:products,id'],
            'name'                    => ['required', 'string', 'max:190'],
            'yield_quantity'          => ['required', 'numeric', 'min:0.0001'],
            'yield_unit_id'           => ['nullable', 'exists:units,id'],
            'is_active'               => ['boolean'],
            'notes'                   => ['nullable', 'string'],
            'ingredients'             => ['required', 'array', 'min:1'],
            'ingredients.*.product_id'         => ['required', 'exists:products,id'],
            'ingredients.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'ingredients.*.quantity'           => ['required', 'numeric', 'min:0.0001'],
            'ingredients.*.unit_id'            => ['nullable', 'exists:units,id'],
            'ingredients.*.cost_override'      => ['nullable', 'numeric', 'min:0'],
            'ingredients.*.sort_order'         => ['nullable', 'integer', 'min:0'],
        ]);

        $recipe = Recipe::create([
            'product_id'     => $data['product_id'],
            'name'           => $data['name'],
            'yield_quantity' => $data['yield_quantity'],
            'yield_unit_id'  => $data['yield_unit_id'] ?? null,
            'is_active'      => $request->boolean('is_active', true),
            'notes'          => $data['notes'] ?? null,
        ]);

        foreach ($data['ingredients'] as $i => $ing) {
            $recipe->ingredients()->create([
                'product_id'         => $ing['product_id'],
                'product_variant_id' => $ing['product_variant_id'] ?? null,
                'quantity'           => $ing['quantity'],
                'unit_id'            => $ing['unit_id'] ?? null,
                'cost_override'      => isset($ing['cost_override']) && $ing['cost_override'] !== '' ? $ing['cost_override'] : null,
                'sort_order'         => $ing['sort_order'] ?? $i,
            ]);
        }

        return redirect(url('/recipes/' . $recipe->id))->with('status', 'Recipe created.');
    }

    public function show(Recipe $recipe, RecipeCostService $recipeCostService)
    {
        $recipe->load(['product', 'yieldUnit', 'ingredients.product', 'ingredients.variant', 'ingredients.unit']);

        $estimatedCost = $recipeCostService->calculateCost($recipe);

        return view('tenant.recipes.show', compact('recipe', 'estimatedCost'));
    }

    public function edit(Recipe $recipe)
    {
        $recipe->load(['ingredients.product', 'ingredients.variant', 'ingredients.unit']);

        $products = Product::where('status', 'active')->orderBy('name')->get();
        $units    = Unit::orderBy('name')->get();

        return view('tenant.recipes.edit', compact('recipe', 'products', 'units'));
    }

    public function update(Request $request, Recipe $recipe)
    {
        $data = $request->validate([
            'product_id'              => ['required', 'exists:products,id'],
            'name'                    => ['required', 'string', 'max:190'],
            'yield_quantity'          => ['required', 'numeric', 'min:0.0001'],
            'yield_unit_id'           => ['nullable', 'exists:units,id'],
            'is_active'               => ['boolean'],
            'notes'                   => ['nullable', 'string'],
            'ingredients'             => ['required', 'array', 'min:1'],
            'ingredients.*.product_id'         => ['required', 'exists:products,id'],
            'ingredients.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'ingredients.*.quantity'           => ['required', 'numeric', 'min:0.0001'],
            'ingredients.*.unit_id'            => ['nullable', 'exists:units,id'],
            'ingredients.*.cost_override'      => ['nullable', 'numeric', 'min:0'],
            'ingredients.*.sort_order'         => ['nullable', 'integer', 'min:0'],
        ]);

        $recipe->update([
            'product_id'     => $data['product_id'],
            'name'           => $data['name'],
            'yield_quantity' => $data['yield_quantity'],
            'yield_unit_id'  => $data['yield_unit_id'] ?? null,
            'is_active'      => $request->boolean('is_active', true),
            'notes'          => $data['notes'] ?? null,
        ]);

        $recipe->ingredients()->delete();

        foreach ($data['ingredients'] as $i => $ing) {
            $recipe->ingredients()->create([
                'product_id'         => $ing['product_id'],
                'product_variant_id' => $ing['product_variant_id'] ?? null,
                'quantity'           => $ing['quantity'],
                'unit_id'            => $ing['unit_id'] ?? null,
                'cost_override'      => isset($ing['cost_override']) && $ing['cost_override'] !== '' ? $ing['cost_override'] : null,
                'sort_order'         => $ing['sort_order'] ?? $i,
            ]);
        }

        return redirect(url('/recipes/' . $recipe->id))->with('status', 'Recipe updated.');
    }

    public function destroy(Recipe $recipe)
    {
        $recipe->delete();

        return redirect(url('/recipes'))->with('status', 'Recipe deleted.');
    }
}
