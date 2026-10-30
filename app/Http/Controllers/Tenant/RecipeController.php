<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Product;
use App\Models\Tenant\Recipe;
use App\Models\Tenant\RecipeIngredient;
use App\Models\Tenant\Unit;
use App\Services\Kitchen\RecipeCostService;
use App\Services\Kitchen\RecipeWriter;
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

    public function store(Request $request, RecipeWriter $writer)
    {
        $data = $request->validate(RecipeWriter::rules());
        $recipe = $writer->save(null, $data, $request->boolean('is_active', true));

        return redirect(url('/recipes/' . $recipe->id))->with('status', 'Recipe created.');
    }

    public function show(Request $request, Recipe $recipe, RecipeCostService $recipeCostService)
    {
        $recipe->load(['product', 'yieldUnit', 'ingredients.product', 'ingredients.variant', 'ingredients.unit']);

        // KITCHEN-RECIPE-ORDER-TYPE-1 report scope (null = all lines).
        $orderType = $request->query('order_type');
        if (! in_array($orderType, RecipeIngredient::SPECIFIC_ORDER_TYPES, true)) {
            $orderType = null;
        }

        $estimatedCost = $recipeCostService->calculateCost($recipe);
        $breakdown     = $recipeCostService->breakdown($recipe, $orderType);
        $print         = $request->boolean('print');

        return view('tenant.recipes.show', compact('recipe', 'estimatedCost', 'breakdown', 'print', 'orderType'));
    }

    public function edit(Recipe $recipe, RecipeCostService $recipeCostService)
    {
        $recipe->load(['product', 'ingredients.product', 'ingredients.variant', 'ingredients.unit']);

        $products = Product::where('status', 'active')->orderBy('name')->get();
        $units    = Unit::orderBy('name')->get();

        // KITCHEN-RECIPE-REPORT-UX-1: read-only mini cost summary on the setup screen
        // (all lines; the full Technosys report lives on the View page). No stock movement.
        $breakdown = $recipeCostService->breakdown($recipe);

        return view('tenant.recipes.edit', compact('recipe', 'products', 'units', 'breakdown'));
    }

    public function update(Request $request, Recipe $recipe, RecipeWriter $writer)
    {
        $data = $request->validate(RecipeWriter::rules());
        $writer->save($recipe, $data, $request->boolean('is_active', true));

        return redirect(url('/recipes/' . $recipe->id))->with('status', 'Recipe updated.');
    }

    public function destroy(Recipe $recipe)
    {
        $recipe->delete();

        return redirect(url('/recipes'))->with('status', 'Recipe deleted.');
    }
}
