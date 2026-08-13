<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringCostSnapshot;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use App\Services\Kitchen\UnitConversionService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CATERING-SLICE-2: PURE quotation costing (spec §7).
 *
 * requirement = recipe ingredient qty × (quote qty / recipe yield) × material rate
 *
 * READ-ONLY BY CONSTRUCTION: this service reads recipes, units, the Catering
 * Material Rate Book, and products — and writes ONLY catering tables
 * (cost snapshots + estimated-cost columns on catering estimate lines).
 * It never touches InventoryService, stock tables, FEFO, COGS, GL, shifts,
 * or KOT. Unit conversion reuses the platform's UnitConversionService.
 *
 * Rate resolution per ingredient: Material Rate Book (effective at the
 * costing date) → fallback products.default_purchase_price. A missing unit
 * conversion is reported as a per-ingredient warning (cost excluded), never a
 * silent wrong number.
 */
class CateringRecipeCostingService
{
    public function __construct(
        private readonly UnitConversionService $unitConversion,
    ) {}

    /** Compute the full costing breakdown for an estimate. Pure — persists nothing. */
    public function calculate(CateringEstimate $estimate, ?string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?? now()->toDateString();
        $lines = [];
        $total = 0.0;
        $warnings = [];

        foreach ($estimate->lines()->with(['product.activeRecipe.ingredients.product.unit', 'product.unit', 'unit'])->get() as $line) {
            $lineBreakdown = $this->costLine($line, $asOfDate);
            $lines[] = $lineBreakdown;
            $total += $lineBreakdown['line_cost'];
            foreach ($lineBreakdown['ingredients'] as $ingredient) {
                if (! empty($ingredient['warning'])) {
                    $warnings[] = $ingredient['warning'];
                }
            }
            if (! empty($lineBreakdown['warning'])) {
                $warnings[] = $lineBreakdown['warning'];
            }
        }

        return [
            'as_of_date' => $asOfDate,
            'lines' => $lines,
            'total_material_cost' => round($total, 2),
            'warnings' => $warnings,
        ];
    }

    /**
     * Recompute + persist the costing basis for a DRAFT estimate: snapshot row,
     * per-line estimated costs, and the estimate's estimated_material_cost.
     */
    public function snapshot(CateringEstimate $estimate, ?int $userId = null, ?string $asOfDate = null): CateringCostSnapshot
    {
        if (! $estimate->isDraft()) {
            throw new RuntimeException(
                "Estimate {$estimate->displayNo()} is {$estimate->status}; its costing basis is frozen. Revise it instead."
            );
        }

        $result = $this->calculate($estimate, $asOfDate);

        return DB::connection('tenant')->transaction(function () use ($estimate, $result, $userId) {
            foreach ($result['lines'] as $lineBreakdown) {
                if ($lineBreakdown['line_id']) {
                    CateringEstimateLine::whereKey($lineBreakdown['line_id'])->update([
                        'estimated_unit_cost' => $lineBreakdown['unit_cost'],
                        'estimated_cost_total' => $lineBreakdown['line_cost'],
                    ]);
                }
            }

            $estimate->forceFill(['estimated_material_cost' => $result['total_material_cost']])->save();

            return CateringCostSnapshot::create([
                'catering_estimate_id' => $estimate->id,
                'breakdown' => $result,
                'total_material_cost' => $result['total_material_cost'],
                'computed_at' => now(),
                'computed_by_user_id' => $userId,
            ]);
        });
    }

    /** Cost one estimate line: recipe explosion when available, direct material rate otherwise. */
    private function costLine(CateringEstimateLine $line, string $asOfDate): array
    {
        $base = [
            'line_id' => $line->id,
            'item_name' => $line->item_name,
            'product_id' => $line->product_id,
            'quantity' => (float) $line->quantity,
            'method' => 'none',
            'batch_count' => null,
            'ingredients' => [],
            'unit_cost' => null,
            'line_cost' => 0.0,
            'warning' => null,
        ];

        $product = $line->product;
        if (! $product) {
            $base['warning'] = "'{$line->item_name}' is a free-text item — no recipe or material rate to cost.";

            return $base;
        }

        $recipe = $product->activeRecipe;
        $quantity = (float) $line->quantity;

        if ($recipe && $recipe->ingredients->isNotEmpty()) {
            // Quote qty → recipe yield unit if both are known and differ.
            $yieldQty = (float) ($recipe->yield_quantity ?: 1);
            $outputQty = $quantity;
            if ($line->unit && $recipe->yieldUnit && $line->unit->id !== $recipe->yieldUnit->id) {
                try {
                    $outputQty = $this->unitConversion->convert($quantity, $line->unit, $recipe->yieldUnit);
                } catch (RuntimeException $e) {
                    $base['warning'] = "Line '{$line->item_name}': {$e->getMessage()} Quote quantity used unconverted against the recipe yield.";
                }
            }

            $batchCount = $outputQty / $yieldQty;
            $base['method'] = 'recipe';
            $base['batch_count'] = round($batchCount, 6);

            $lineCost = 0.0;
            foreach ($recipe->ingredients as $ingredient) {
                $entry = $this->costIngredient($ingredient->product, (float) $ingredient->quantity * $batchCount, $ingredient->unit, $asOfDate);
                $entry['recipe_qty'] = (float) $ingredient->quantity;
                $base['ingredients'][] = $entry;
                $lineCost += $entry['cost'];
            }

            $base['line_cost'] = round($lineCost, 2);
            $base['unit_cost'] = $quantity > 0 ? round($lineCost / $quantity, 4) : null;

            return $base;
        }

        // No recipe: cost the product itself from the rate book (simple materials).
        $entry = $this->costIngredient($product, $quantity, $line->unit ?: $product->unit, $asOfDate);
        $base['method'] = 'direct';
        $base['ingredients'] = [$entry];
        $base['line_cost'] = $entry['cost'];
        $base['unit_cost'] = $quantity > 0 ? round($entry['cost'] / $quantity, 4) : null;

        return $base;
    }

    /** Price a required material quantity via rate book → purchase-price fallback. */
    private function costIngredient(?Product $product, float $requiredQty, ?Unit $requiredUnit, string $asOfDate): array
    {
        $entry = [
            'product_id' => $product?->id,
            'name' => $product?->name ?? '(missing product)',
            'required_qty' => round($requiredQty, 4),
            'required_unit' => $requiredUnit?->code,
            'priced_qty' => null,
            'priced_unit' => null,
            'rate' => null,
            'rate_source' => null,
            'cost' => 0.0,
            'warning' => null,
        ];

        if (! $product) {
            $entry['warning'] = 'Recipe ingredient product missing — excluded from cost.';

            return $entry;
        }

        $rateRow = CateringMaterialRate::effectiveFor($product->id, $asOfDate);
        $rate = $rateRow ? (float) $rateRow->rate : (float) ($product->default_purchase_price ?? 0);
        $rateUnit = $rateRow?->unit ?: $product->unit;
        $entry['rate'] = round($rate, 4);
        $entry['rate_source'] = $rateRow ? 'rate_book' : 'purchase_price';

        $pricedQty = $requiredQty;
        if ($requiredUnit && $rateUnit && $requiredUnit->id !== $rateUnit->id) {
            try {
                $pricedQty = $this->unitConversion->convert($requiredQty, $requiredUnit, $rateUnit);
            } catch (RuntimeException $e) {
                $entry['warning'] = "{$product->name}: {$e->getMessage()} Ingredient excluded from cost.";
                $entry['cost'] = 0.0;

                return $entry;
            }
        }

        $entry['priced_qty'] = round($pricedQty, 4);
        $entry['priced_unit'] = $rateUnit?->code;
        $entry['cost'] = round($pricedQty * $rate, 2);

        return $entry;
    }
}
