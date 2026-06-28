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

    /**
     * Consume recipe ingredients for a sold line and RETURN the total ingredient
     * cost consumed (FIN-11A). The caller (SalesService) stores this on the sale
     * line as unit_cost/cost_total so the paid-sale journal can post COGS.
     */
    public function consumeForSalesOrderLine(SalesOrder $sale, SalesOrderLine $line, Branch $branch): float
    {
        $product = $line->product;

        if (!$product || $product->inventory_consumption_method !== 'recipe') {
            return 0.0;
        }

        $recipe = $product->activeRecipe()->with(['ingredients.product.unit', 'ingredients.variant', 'ingredients.unit'])->first();

        if (!$recipe) {
            return 0.0;
        }

        $soldQty   = (float) $line->quantity;
        $yieldQty  = (float) $recipe->yield_quantity ?: 1;
        $batchCount = $soldQty / $yieldQty;

        $totalCost = 0.0;

        foreach ($recipe->ingredients as $ingredient) {
            // KITCHEN-RECIPE-CONSUME-ORDER-TYPE-1: only consume lines that apply to this
            // sale's POS order type. Lines tagged for other order types (e.g. takeaway/
            // delivery packing on a dine-in sale) are skipped — no stock out, no COGS.
            // Lines with applicable_order_types null/empty/["all"] still consume for every
            // order type, so legacy recipes behave exactly as before.
            if (!$ingredient->appliesToOrderType($sale->order_type)) {
                continue;
            }

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

            $ledgers = $this->inventoryService->postOutFefo(
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

            $totalCost += (float) collect($ledgers)->sum(fn ($ledger) => (float) $ledger->total_cost);

            RecipeConsumption::create([
                'recipe_id'            => $recipe->id,
                'recipe_ingredient_id' => $ingredient->id,
                'sales_order_id'       => $sale->id,
                'sales_order_line_id'  => $line->id,
                'product_id'           => $ingredientProduct->id,
                'product_variant_id'   => $ingredient->product_variant_id,
                'quantity_consumed'    => $requiredQty,
                'order_type'           => $sale->order_type,
                'line_section'         => $ingredient->line_section,
                'unit_id'              => $ingredientProduct->unit_id,
                'consumed_at'          => now(),
                'notes'                => "Consumed for sale line #{$line->id}",
            ]);
        }

        return round($totalCost, 4);
    }
}
