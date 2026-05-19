<?php

namespace App\Services\Kitchen;

use App\Models\Tenant\Branch;
use App\Models\Tenant\RecipeConsumption;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Services\Inventory\InventoryService;
use RuntimeException;

class RecipeConsumptionService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly UnitConversionService $unitConversionService,
    ) {
    }

    public function consumeForSalesOrderLine(SalesOrder $sale, SalesOrderLine $line, Branch $branch): void
    {
        $product = $line->product;

        if (!$product || $product->inventory_consumption_method !== 'recipe') {
            return;
        }

        $recipe = $product->activeRecipe()->with(['ingredients.product.unit', 'ingredients.variant', 'ingredients.unit'])->first();

        if (!$recipe) {
            return;
        }

        $soldQty   = (float) $line->quantity;
        $yieldQty  = (float) $recipe->yield_quantity ?: 1;
        $batchCount = $soldQty / $yieldQty;

        foreach ($recipe->ingredients as $ingredient) {
            $ingredientProduct = $ingredient->product;

            if (!$ingredientProduct || !$ingredientProduct->is_stock_tracked) {
                continue;
            }

            $requiredQty = $ingredient->quantity * $batchCount;

            // Convert units if ingredient unit differs from product base unit
            if ($ingredient->unit_id && $ingredientProduct->unit_id && $ingredient->unit_id !== $ingredientProduct->unit_id) {
                $ingredient->loadMissing('unit');
                $ingredientProduct->loadMissing('unit');

                if ($ingredient->unit && $ingredientProduct->unit) {
                    try {
                        $requiredQty = $this->unitConversionService->convert(
                            $requiredQty,
                            $ingredient->unit,
                            $ingredientProduct->unit
                        );
                    } catch (RuntimeException) {
                        // Skip conversion if no path found; use as-is
                    }
                }
            }

            $this->inventoryService->postOutFefo(
                branch: $branch,
                product: $ingredientProduct,
                variant: $ingredient->variant,
                quantity: $requiredQty,
                movementType: 'recipe_consumption',
                referenceType: 'sales_order',
                referenceId: $sale->id,
                referenceNo: $sale->sale_no,
                notes: "Recipe consumption for {$product->name} (Sale {$sale->sale_no})",
                userId: $sale->created_by_user_id,
            );

            RecipeConsumption::create([
                'recipe_id'           => $recipe->id,
                'sales_order_id'      => $sale->id,
                'sales_order_line_id' => $line->id,
                'product_id'          => $ingredientProduct->id,
                'product_variant_id'  => $ingredient->product_variant_id,
                'quantity_consumed'   => $requiredQty,
                'unit_id'             => $ingredientProduct->unit_id,
                'consumed_at'         => now(),
                'notes'               => "Consumed for sale line #{$line->id}",
            ]);
        }
    }
}
