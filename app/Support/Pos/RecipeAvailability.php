<?php

namespace App\Support\Pos;

use App\Models\Tenant\Product;
use App\Services\Kitchen\UnitConversionService;

/**
 * W-G1 — the ONE "makeable" preview computation behind a recipe tile (`is_recipe` / `makeable_by_branch` /
 * `limiting_ingredient_by_branch` in the shared cashier view's productsPayload).
 *
 * Extracted VERBATIM from Online `POSController::recipeAvailability()` so the Cloud and the Branch Server share one
 * implementation: Online feeds it the official `stock_balances`, the Edge feeds it the ACCEPTED operational baseline
 * (`edge_operational_stock_balances`) — exactly the balances each runtime's sale consumes/refuses on (Online
 * RecipeConsumptionService, Edge EdgeOperationalStockService::consumeRecipe), so the preview never promises what the
 * sale would refuse. Proactive only — the backend stays authoritative at sale time.
 */
final class RecipeAvailability
{
    public function __construct(private readonly UnitConversionService $unitConversion)
    {
    }

    /** @return array{is_recipe:false, makeable:array{}, limiting:array{}} */
    public static function blank(): array
    {
        return ['is_recipe' => false, 'makeable' => [], 'limiting' => []];
    }

    /**
     * Build the `[branchId][productId][variantId|0] => qty` lookup the computation reads, from any rows that carry
     * `branch_id`, `product_id`, `product_variant_id` and a quantity column (Online: the grouped StockBalance rows,
     * `qty`; Edge: edge_operational_stock_balances, `quantity_on_hand`).
     *
     * @param  iterable<object|array>  $rows
     * @return array<int, array<int, array<int, float>>>
     */
    public static function stockLookup(iterable $rows, string $quantityColumn = 'qty'): array
    {
        $lookup = [];
        foreach ($rows as $row) {
            $row = (object) $row;
            $lookup[(int) $row->branch_id][(int) $row->product_id][(int) ($row->product_variant_id ?: 0)] = (float) $row->{$quantityColumn};
        }

        return $lookup;
    }

    /**
     * Recipe-based availability preview for a service product — proactive makeable-units
     * estimate so the cashier sees "out of stock" BEFORE a sale is refused.
     *
     * Only ingredients that are stock-tracked constrain; non-tracked ingredients are ignored
     * (plain service items). Ingredient unit→product base unit conversion is applied so the preview
     * matches what checkout would actually consume. Backend remains authoritative.
     *
     * The product must carry `activeRecipe.ingredients.product.unit`, `activeRecipe.ingredients.unit` (eager-loaded
     * by both callers); `$stockLookup` is `[branchId][productId][variantId|0] => qty` (see {@see stockLookup()}).
     *
     * @param  iterable<object>  $branches  objects with an `id`
     * @param  array<int, array<int, array<int, float>>>  $stockLookup
     * @return array{is_recipe:bool, makeable:array<int,int>, limiting:array<int,string>}
     */
    public function forProduct(Product $product, iterable $branches, array $stockLookup): array
    {
        $blank = self::blank();

        if ($product->inventory_consumption_method !== 'recipe') {
            return $blank;
        }

        $recipe = $product->activeRecipe;
        if (! $recipe) {
            return $blank;
        }

        // Only stock-tracked ingredients constrain how many we can make.
        $ingredients = $recipe->ingredients->filter(
            fn ($ing) => $ing->product && $ing->product->is_stock_tracked
        );

        if ($ingredients->isEmpty()) {
            return $blank; // recipe with no stock-tracked ingredients → unlimited (plain service)
        }

        $yield = (float) ($recipe->yield_quantity ?: 1) ?: 1;

        $makeable = [];
        $limiting = [];

        foreach ($branches as $branch) {
            $branchId = (int) $branch->id;
            $minUnits = null;
            $limitName = null;

            foreach ($ingredients as $ing) {
                $ip = $ing->product;

                $requiredPerBatch = (float) $ing->quantity;

                // Convert ingredient unit → ingredient product base unit (as consumption does).
                if ($ing->unit_id && $ip->unit_id && $ing->unit_id !== $ip->unit_id && $ing->unit && $ip->unit) {
                    try {
                        $requiredPerBatch = $this->unitConversion->convert($requiredPerBatch, $ing->unit, $ip->unit);
                    } catch (\Throwable) {
                        // no conversion path — use as-is
                    }
                }

                if ($requiredPerBatch <= 0) {
                    continue;
                }

                $variantId = (int) ($ing->product_variant_id ?: 0);
                $stock = $stockLookup[$branchId][(int) $ip->id][$variantId] ?? 0.0;

                $units = ($stock / $requiredPerBatch) * $yield;

                if ($minUnits === null || $units < $minUnits) {
                    $minUnits = $units;
                    $limitName = $ip->name;
                }
            }

            $makeable[$branchId]  = (int) floor(max(0, $minUnits ?? 0));
            $limiting[$branchId]  = $limitName ?? '';
        }

        return ['is_recipe' => true, 'makeable' => $makeable, 'limiting' => $limiting];
    }
}
