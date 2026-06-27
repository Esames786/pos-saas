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

    /**
     * KITCHEN-RECIPE-COST-1 — Technosys-style structured cost breakdown for the report.
     * Read-only: it computes numbers only and never touches stock, COGS, or the GL.
     *
     * Per line:  Price/Unit = Cost Price ÷ pack size ;  Amount = Prod.Qty × Price/Unit.
     * Cost Price source: recipe line cost_override (per purchase unit) else product
     * default_purchase_price. (This is the PURCHASE-unit meaning — distinct from the
     * legacy calculateCost() above, which is intentionally left unchanged.)
     */
    public function breakdown(Recipe $recipe): array
    {
        $recipe->loadMissing([
            'product.unit', 'product.purchaseUnit',
            'ingredients.product.unit', 'ingredients.product.purchaseUnit', 'ingredients.product.barcodes',
            'ingredients.unit',
        ]);

        $finished = $recipe->product;
        $grand    = 0.0;
        $buckets  = []; // section => [lines, items, qty, amount]
        $allLines = [];

        foreach ($recipe->ingredients as $ing) {
            $product   = $ing->product;
            $costPrice = $this->resolvePurchaseCost($ing);                 // per purchase unit
            $packSize  = (float) ($product?->purchase_pack_size ?: 0);
            $prodWt    = $packSize > 0 ? $packSize : 1.0;                  // null/0 → 1
            $pricePer  = $prodWt > 0 ? $costPrice / $prodWt : 0.0;
            $qty       = (float) $ing->quantity;
            $amount    = round($qty * $pricePer, 4);

            $section = $ing->line_section ?? 'food_cost';
            if (! isset(RecipeIngredient::SECTIONS[$section])) {
                $section = 'other';
            }

            $line = [
                'section'          => $section,
                'barcode'          => optional($product?->barcodes?->first())->barcode ?? ($product?->sku ?? ''),
                'item_description' => $product?->name ?? '—',
                'is_intermediate'  => (bool) ($product && $product->inventory_consumption_method === 'recipe'),
                'prod_qty'         => $qty,
                'prod_unit'        => $ing->unit?->code ?? $product?->unit?->code ?? '',
                'prod_weight'      => $prodWt,
                'purchase_unit'    => $product?->purchaseUnit?->code ?? $product?->unit?->code ?? '',
                'price_per_unit'   => round($pricePer, 4),
                'cost_price'       => round($costPrice, 4),
                'amount'           => $amount,
            ];

            $allLines[] = $line;
            $grand += $amount;

            $buckets[$section] ??= ['lines' => [], 'items' => 0, 'qty' => 0.0, 'amount' => 0.0];
            $buckets[$section]['lines'][]  = $line;
            $buckets[$section]['items']++;
            $buckets[$section]['qty']    += $qty;
            $buckets[$section]['amount'] += $amount;
        }

        $grand = round($grand, 4);

        $sections = [];
        $sNo = 0;
        foreach (array_keys(RecipeIngredient::SECTIONS) as $key) {
            if (! isset($buckets[$key])) {
                continue;
            }
            $bucket = $buckets[$key];
            $secLines = [];
            foreach ($bucket['lines'] as $l) {
                $l['s_no']    = ++$sNo;
                $l['percent'] = $grand > 0 ? round($l['amount'] / $grand * 100, 2) : 0.0;
                $secLines[]   = $l;
            }
            $sections[] = [
                'key'              => $key,
                'label'            => RecipeIngredient::SECTIONS[$key],
                'lines'            => $secLines,
                'items_count'      => $bucket['items'],
                'quantity_total'   => round($bucket['qty'], 2),
                'amount_total'     => round($bucket['amount'], 2),
                'percent_of_grand' => $grand > 0 ? round($bucket['amount'] / $grand * 100, 2) : 0.0,
            ];
        }

        $recipeCostPrice = $grand;
        $overheadPercent = (float) ($recipe->overhead_percent ?? 0);
        $overheadAmount  = round($recipeCostPrice * $overheadPercent / 100, 4);
        $costPrice       = round($recipeCostPrice + $overheadAmount, 4);

        $salePrice = (float) ($finished?->default_selling_price ?? 0);
        $taxable   = $finished && $finished->is_taxable && (float) $finished->tax_rate_percent > 0;
        $saleWithGst = $taxable ? round($salePrice * (1 + (float) $finished->tax_rate_percent / 100), 4) : $salePrice;

        $gpAmount = round($saleWithGst - $costPrice, 4);

        return [
            'recipe'               => $recipe,
            'finished_product'     => $finished,
            'sale_price'           => round($salePrice, 2),
            'sale_price_with_gst'  => round($saleWithGst, 2),
            'recipe_cost_price'    => round($recipeCostPrice, 2),
            'overhead_amount'      => round($overheadAmount, 2),
            'cost_price'           => round($costPrice, 2),
            'gp_amount'            => round($gpAmount, 2),
            'gp_percent_on_cost'   => $costPrice > 0 ? round($gpAmount / $costPrice * 100, 2) : 0.0,
            'overall_cost_percent' => $saleWithGst > 0 ? round($recipeCostPrice / $saleWithGst * 100, 2) : 0.0,
            'sections'             => $sections,
            'grand_total'          => round($grand, 2),
            'line_count'           => count($allLines),
            'quantity_total'       => round(array_sum(array_column($allLines, 'prod_qty')), 2),
        ];
    }

    /** Cost per PURCHASE unit: line override else product purchase price. */
    private function resolvePurchaseCost(RecipeIngredient $ingredient): float
    {
        if ($ingredient->cost_override !== null) {
            return (float) $ingredient->cost_override;
        }

        return (float) ($ingredient->product?->default_purchase_price ?? 0);
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
