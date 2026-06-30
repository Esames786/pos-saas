<?php

namespace App\Services\Kitchen;

use App\Models\Tenant\Branch;
use App\Models\Tenant\KitchenProduction;
use App\Models\Tenant\Recipe;
use App\Services\Inventory\InventoryService;
use RuntimeException;

class KitchenProductionService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly RecipeCostService $recipeCostService,
    ) {
    }

    public function record(array $data, Branch $branch, Recipe $recipe, int $userId): KitchenProduction
    {
        $recipe->loadMissing(['ingredients.product', 'ingredients.variant', 'ingredients.unit']);

        $qtyProduced = (float) $data['quantity_produced'];
        $yieldQty    = (float) $recipe->yield_quantity ?: 1;
        $batchCount  = $qtyProduced / $yieldQty;

        $production = KitchenProduction::create([
            'production_no'      => 'KP-' . now()->format('YmdHis') . '-' . random_int(100, 999),
            'branch_id'          => $branch->id,
            'recipe_id'          => $recipe->id,
            'quantity_produced'  => $qtyProduced,
            'yield_unit_id'      => $recipe->yield_unit_id,
            'production_date'    => $data['production_date'] ?? now()->toDateString(),
            'status'             => 'planned',
            'notes'              => $data['notes'] ?? null,
            'produced_by_user_id' => $userId,
        ]);

        foreach ($recipe->ingredients as $ingredient) {
            $production->ingredients()->create([
                'product_id'         => $ingredient->product_id,
                'product_variant_id' => $ingredient->product_variant_id,
                'quantity_required'  => round((float) $ingredient->quantity * $batchCount, 4),
                'quantity_used'      => 0,
                'unit_id'            => $ingredient->unit_id,
            ]);
        }

        return $production;
    }

    public function complete(KitchenProduction $production, array $ingredientUsages, int $userId): void
    {
        if (!in_array($production->status, ['planned', 'in_progress'], true)) {
            throw new RuntimeException('Production cannot be completed from its current status.');
        }

        $production->loadMissing(['branch', 'recipe.product', 'ingredients.product', 'ingredients.variant', 'ingredients.unit']);

        foreach ($production->ingredients as $prodIngredient) {
            $usedQty = (float) ($ingredientUsages[$prodIngredient->id] ?? $prodIngredient->quantity_required);

            if ($usedQty <= 0) {
                continue;
            }

            $product = $prodIngredient->product;

            if (!$product || !$product->is_stock_tracked) {
                continue;
            }

            $this->inventoryService->postOutFefo(
                branch: $production->branch,
                product: $product,
                variant: $prodIngredient->variant,
                quantity: $usedQty,
                movementType: 'kitchen_production',
                referenceType: 'kitchen_production',
                referenceId: $production->id,
                referenceNo: $production->production_no,
                notes: "Kitchen production #{$production->production_no}",
                userId: $userId,
            );

            $prodIngredient->update(['quantity_used' => $usedQty]);
        }

        // Post finished product into stock only if it is stock-tracked AND
        // NOT recipe-based. BUG-017 FIX: a product with inventory_consumption_method
        // = 'recipe' is consumed ingredient-by-ingredient at point-of-sale. If we
        // also add it to stock here, the POS sale will consume ingredients a second
        // time (double-consumption). Only add to stock for products where the POS
        // will deduct using FEFO (stock_item method) or the product is not sold via POS.
        $finishedProduct = $production->recipe->product;

        if (
            $finishedProduct
            && $finishedProduct->is_stock_tracked
            && $finishedProduct->inventory_consumption_method !== 'recipe'
        ) {
            $unitCost = $this->recipeCostService->calculateCost($production->recipe);

            $this->inventoryService->postIn(
                branch: $production->branch,
                product: $finishedProduct,
                variant: null,
                quantity: (float) $production->quantity_produced,
                unitCost: $unitCost,
                movementType: 'kitchen_production',
                referenceType: 'kitchen_production',
                referenceId: $production->id,
                referenceNo: $production->production_no,
                notes: "Finished goods from production #{$production->production_no}",
                userId: $userId,
            );
        }

        $production->update([
            'status'             => 'completed',
            'produced_by_user_id' => $userId,
        ]);
    }
}
