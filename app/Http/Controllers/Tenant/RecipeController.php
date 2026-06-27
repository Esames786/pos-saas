<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Product;
use App\Models\Tenant\Recipe;
use App\Models\Tenant\RecipeIngredient;
use App\Models\Tenant\Unit;
use App\Services\Kitchen\RecipeCostService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'ingredients.*.line_section'       => ['nullable', Rule::in(array_keys(RecipeIngredient::SECTIONS))],
            'ingredients.*.sort_order'         => ['nullable', 'integer', 'min:0'],
            // KITCHEN-RECIPE-COST-1 report header
            'doc_no'                  => ['nullable', 'string', 'max:100'],
            'recipe_no'               => ['nullable', 'string', 'max:100'],
            'revision_no'             => ['nullable', 'integer', 'min:1'],
            'review_date'             => ['nullable', 'date'],
            'overhead_percent'        => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        $recipe = Recipe::create([
            'product_id'       => $data['product_id'],
            'name'             => $data['name'],
            'yield_quantity'   => $data['yield_quantity'],
            'yield_unit_id'    => $data['yield_unit_id'] ?? null,
            'is_active'        => $request->boolean('is_active', true),
            'notes'            => $data['notes'] ?? null,
            'doc_no'           => $data['doc_no'] ?? null,
            'recipe_no'        => $data['recipe_no'] ?? null,
            'revision_no'      => $data['revision_no'] ?? 1,
            'review_date'      => $data['review_date'] ?? null,
            'overhead_percent' => $data['overhead_percent'] ?? 0,
        ]);

        foreach ($data['ingredients'] as $i => $ing) {
            $recipe->ingredients()->create([
                'product_id'         => $ing['product_id'],
                'product_variant_id' => $ing['product_variant_id'] ?? null,
                'quantity'           => $ing['quantity'],
                'unit_id'            => $ing['unit_id'] ?? null,
                'cost_override'      => isset($ing['cost_override']) && $ing['cost_override'] !== '' ? $ing['cost_override'] : null,
                'line_section'       => $ing['line_section'] ?? 'food_cost',
                'sort_order'         => $ing['sort_order'] ?? $i,
            ]);
        }

        $this->enforceSingleActive($recipe);

        return redirect(url('/recipes/' . $recipe->id))->with('status', 'Recipe created.');
    }

    public function show(Request $request, Recipe $recipe, RecipeCostService $recipeCostService)
    {
        $recipe->load(['product', 'yieldUnit', 'ingredients.product', 'ingredients.variant', 'ingredients.unit']);

        $estimatedCost = $recipeCostService->calculateCost($recipe);
        $breakdown     = $recipeCostService->breakdown($recipe);
        $print         = $request->boolean('print');

        return view('tenant.recipes.show', compact('recipe', 'estimatedCost', 'breakdown', 'print'));
    }

    /** Only one active recipe per finished product: deactivate the others. */
    private function enforceSingleActive(Recipe $recipe): void
    {
        if (! $recipe->is_active) {
            return;
        }

        Recipe::where('product_id', $recipe->product_id)
            ->where('id', '!=', $recipe->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);
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
            'ingredients.*.line_section'       => ['nullable', Rule::in(array_keys(RecipeIngredient::SECTIONS))],
            'ingredients.*.sort_order'         => ['nullable', 'integer', 'min:0'],
            // KITCHEN-RECIPE-COST-1 report header
            'doc_no'                  => ['nullable', 'string', 'max:100'],
            'recipe_no'               => ['nullable', 'string', 'max:100'],
            'revision_no'             => ['nullable', 'integer', 'min:1'],
            'review_date'             => ['nullable', 'date'],
            'overhead_percent'        => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        $recipe->update([
            'product_id'       => $data['product_id'],
            'name'             => $data['name'],
            'yield_quantity'   => $data['yield_quantity'],
            'yield_unit_id'    => $data['yield_unit_id'] ?? null,
            'is_active'        => $request->boolean('is_active', true),
            'notes'            => $data['notes'] ?? null,
            'doc_no'           => $data['doc_no'] ?? null,
            'recipe_no'        => $data['recipe_no'] ?? null,
            'revision_no'      => $data['revision_no'] ?? 1,
            'review_date'      => $data['review_date'] ?? null,
            'overhead_percent' => $data['overhead_percent'] ?? 0,
        ]);

        $recipe->ingredients()->delete();

        foreach ($data['ingredients'] as $i => $ing) {
            $recipe->ingredients()->create([
                'product_id'         => $ing['product_id'],
                'product_variant_id' => $ing['product_variant_id'] ?? null,
                'quantity'           => $ing['quantity'],
                'unit_id'            => $ing['unit_id'] ?? null,
                'cost_override'      => isset($ing['cost_override']) && $ing['cost_override'] !== '' ? $ing['cost_override'] : null,
                'line_section'       => $ing['line_section'] ?? 'food_cost',
                'sort_order'         => $ing['sort_order'] ?? $i,
            ]);
        }

        $this->enforceSingleActive($recipe);

        return redirect(url('/recipes/' . $recipe->id))->with('status', 'Recipe updated.');
    }

    public function destroy(Recipe $recipe)
    {
        $recipe->delete();

        return redirect(url('/recipes'))->with('status', 'Recipe deleted.');
    }
}
