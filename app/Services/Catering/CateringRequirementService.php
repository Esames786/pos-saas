<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\StockBalance;
use App\Services\Kitchen\UnitConversionService;
use RuntimeException;

/**
 * CATERING-SLICE-3: consolidated material requirements (spec §13).
 *
 * Recipe-explodes an estimate and consolidates by ingredient product in the
 * ingredient product's own stock unit — Chicken Biryani + Qorma + Karahi
 * sharing Raw Chicken yield ONE Raw Chicken requirement line.
 *
 * READ-ONLY planning: compares against stock_balances by reading them.
 * No purchasing, stock, or ledger mutation of any kind.
 */
class CateringRequirementService
{
    public function __construct(
        private readonly UnitConversionService $unitConversion,
    ) {}

    /**
     * @return array{requirements: array<int, array>, warnings: string[]}
     */
    public function consolidatedForEstimate(CateringEstimate $estimate, ?int $branchId = null): array
    {
        $requirements = [];
        $warnings = [];

        $lines = $estimate->lines()
            ->with(['product.activeRecipe.ingredients.product.unit', 'product.unit', 'unit'])
            ->get();

        foreach ($lines as $line) {
            $product = $line->product;
            if (! $product) {
                continue;
            }

            $recipe = $product->activeRecipe;
            if (! $recipe || $recipe->ingredients->isEmpty()) {
                continue;
            }

            $yieldQty = (float) ($recipe->yield_quantity ?: 1);
            $outputQty = (float) $line->quantity;
            if ($line->unit && $recipe->yieldUnit && $line->unit->id !== $recipe->yieldUnit->id) {
                try {
                    $outputQty = $this->unitConversion->convert($outputQty, $line->unit, $recipe->yieldUnit);
                } catch (RuntimeException $e) {
                    $warnings[] = "Line '{$line->item_name}': {$e->getMessage()} Quote quantity used unconverted.";
                }
            }
            $batchCount = $outputQty / $yieldQty;

            foreach ($recipe->ingredients as $ingredient) {
                $material = $ingredient->product;
                if (! $material) {
                    continue;
                }

                $requiredQty = (float) $ingredient->quantity * $batchCount;

                // Consolidate in the material's own stock unit.
                if ($ingredient->unit && $material->unit && $ingredient->unit->id !== $material->unit->id) {
                    try {
                        $requiredQty = $this->unitConversion->convert($requiredQty, $ingredient->unit, $material->unit);
                    } catch (RuntimeException $e) {
                        $warnings[] = "{$material->name}: {$e->getMessage()} Quantity consolidated unconverted.";
                    }
                }

                if (! isset($requirements[$material->id])) {
                    $requirements[$material->id] = [
                        'product_id' => $material->id,
                        'name' => $material->name,
                        'unit_code' => $material->unit?->code,
                        'required_qty' => 0.0,
                        'used_by' => [],
                    ];
                }

                $requirements[$material->id]['required_qty'] += $requiredQty;
                $requirements[$material->id]['used_by'][$line->item_name] = true;
            }
        }

        // Read-only stock comparison (official balances; no lock, no mutation).
        foreach ($requirements as $productId => &$row) {
            $row['required_qty'] = round($row['required_qty'], 3);
            $row['used_by'] = array_keys($row['used_by']);

            $onHandQuery = StockBalance::query()->where('product_id', $productId);
            if ($branchId) {
                $onHandQuery->where('branch_id', $branchId);
            }
            $onHand = (float) $onHandQuery->sum('quantity_on_hand');

            $row['on_hand'] = round($onHand, 3);
            $row['shortfall'] = round(max($row['required_qty'] - $onHand, 0), 3);
        }
        unset($row);

        return [
            'requirements' => array_values($requirements),
            'warnings' => $warnings,
        ];
    }
}
