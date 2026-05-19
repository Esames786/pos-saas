<?php

namespace App\Services\Kitchen;

use App\Models\Tenant\Recipe;
use App\Models\Tenant\RecipeIngredient;
use App\Models\Tenant\StockBalance;

class RecipeCostService
{
    public function calculateCost(Recipe $recipe): float
    {
        $recipe->loadMissing(['ingredients.product', 'ingredients.unit']);

        $total = 0.0;

        foreach ($recipe->ingredients as $ingredient) {
            $unitCost = $this->resolveUnitCost($ingredient);
            $total   += $unitCost * (float) $ingredient->quantity;
        }

        return round($total, 4);
    }

    private function resolveUnitCost(RecipeIngredient $ingredient): float
    {
        if ($ingredient->cost_override !== null) {
            return (float) $ingredient->cost_override;
        }

        $product = $ingredient->product;

        if (!$product) {
            return 0.0;
        }

        // Average cost from stock balances
        $balance = StockBalance::where('product_id', $product->id)
            ->where('quantity_on_hand', '>', 0)
            ->first();

        if ($balance && (float) $balance->quantity_on_hand > 0) {
            return (float) $balance->avg_cost;
        }

        // Fall back to purchase price
        return (float) ($product->default_purchase_price ?? 0);
    }
}
