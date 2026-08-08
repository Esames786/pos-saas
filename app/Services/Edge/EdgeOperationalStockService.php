<?php

namespace App\Services\Edge;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Modifier;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Services\Inventory\InventoryService;
use App\Services\Kitchen\UnitConversionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * EDGE-LOCAL-POS-1 (H10) — OPERATIONAL stock decrement for an offline Branch Server sale.
 *
 * Persists ONLY to the EDGE-ONLY tables (`edge_operational_stock_baselines/balances/movements` —
 * database/migrations/edge): quantity only, NO valuation columns, so "cost unknown / not authoritative"
 * can never masquerade as an official zero cost in the Cloud ledgers. The official
 * `stock_balances`/`stock_ledgers` are NEVER touched offline (and their mutators fail closed on a
 * branch_server anyway). Cloud recomputes authoritative FEFO/COGS at sync.
 *
 * BASELINE AUTHORITY (I): consumption is only possible under an ACCEPTED baseline for the bound
 * branch/device/activation_epoch (EdgeOperationalBaselineService). No baseline → the sale is refused
 * BEFORE any mutation. Balance rows are locked (`lockForUpdate`) so two concurrent sales cannot both
 * consume the last unit; the branch `allow_negative_stock` policy gates the hard block.
 *
 * Quantity rules mirror the Cloud domain exactly (pinned by RecipeConsumptionReferenceTest):
 *   stock_item → 1:1; recipe → ingredient.qty × (soldQty / yield) with REAL UnitConversionService
 *   (missing conversion HARD-BLOCKS); consume_stock modifiers → linked product (linked_quantity × line qty,
 *   converted); combo_header skipped (components are ordinary lines). Movements reference the CANONICAL
 *   sale_uuid / line_uuid so future sync resolves them across divergent numeric ids.
 *
 * MUST run inside the caller's `tenant` transaction so stock movement commits atomically with the sale.
 */
class EdgeOperationalStockService
{
    public function __construct(
        private readonly InventoryService $inventory,           // resolveVariant() helper ONLY — never a mutator
        private readonly UnitConversionService $unitConversion,
        private readonly EdgeOperationalBaselineService $baselines,
        private readonly EdgeBranchContext $context,
    ) {
    }

    /** Decrement operational stock for every line of a paid local sale. Quantity only — no COGS/FEFO/GL. */
    public function consumeForSale(SalesOrder $sale, int $userId): void
    {
        $branch = $sale->branch;
        if (! $branch) {
            throw new RuntimeException('Local sale has no bound branch for operational stock.');
        }

        // (I) selling stock exists only under an accepted baseline for the current appliance generation.
        $baseline = $this->baselines->currentAccepted();
        if ($baseline === null) {
            throw new RuntimeException('No accepted operational stock baseline — this Branch Server cannot sell yet.');
        }
        $epoch = (int) $this->context->requireCurrent()->activation_epoch;
        if ((int) $baseline->activation_epoch !== $epoch) {
            throw new RuntimeException('Operational stock baseline belongs to a stale appliance generation.');
        }

        $allowNegative = (bool) $branch->allow_negative_stock;

        $sale->loadMissing(['lines.product']);
        foreach ($sale->lines as $line) {
            if ($line->line_kind === 'combo_header') {
                continue;
            }
            $product = $line->product;
            if ($product) {
                $method = $product->inventory_consumption_method;
                if ($method === 'stock_item' && $product->is_stock_tracked) {
                    $this->decrement($baseline, $product, $this->inventory->resolveVariant($product, $line->product_variant_id), (float) $line->quantity, 'sale', $sale, $line, $allowNegative);
                } elseif ($method === 'recipe') {
                    $this->consumeRecipe($baseline, $sale, $line, $allowNegative);
                }
                // 'none' consumes no product stock (but its modifiers still may).
            }

            $this->consumeModifiers($baseline, $sale, $line, $allowNegative);
        }
    }

    /** Recipe consumption — mirrors RecipeConsumptionService quantities exactly, operational only. */
    private function consumeRecipe(object $baseline, SalesOrder $sale, SalesOrderLine $line, bool $allowNegative): void
    {
        $product = $line->product;
        if (! $product || $product->inventory_consumption_method !== 'recipe') {
            return;
        }
        $recipe = $product->activeRecipe()->with(['ingredients.product.unit', 'ingredients.variant', 'ingredients.unit'])->first();
        if (! $recipe) {
            return;
        }

        $soldQty = (float) $line->quantity;
        $yieldQty = (float) $recipe->yield_quantity ?: 1;
        $batchCount = $soldQty / $yieldQty;

        foreach ($recipe->ingredients as $ingredient) {
            if (! $ingredient->appliesToOrderType($sale->order_type)) {
                continue;
            }
            $ingredientProduct = $ingredient->product;
            if (! $ingredientProduct || ! $ingredientProduct->is_stock_tracked) {
                continue;
            }
            $requiredQty = $ingredient->quantity * $batchCount;

            if ($ingredient->unit_id && $ingredientProduct->unit_id && $ingredient->unit_id !== $ingredientProduct->unit_id) {
                $ingredient->loadMissing('unit');
                $ingredientProduct->loadMissing('unit');
                if ($ingredient->unit && $ingredientProduct->unit) {
                    try {
                        $requiredQty = $this->unitConversion->convert($requiredQty, $ingredient->unit, $ingredientProduct->unit);
                    } catch (RuntimeException $e) {
                        throw new RuntimeException(
                            "Cannot process sale: no unit conversion found from [{$ingredient->unit->code}] to "
                            . "[{$ingredientProduct->unit->code}] for ingredient [{$ingredientProduct->name}]."
                        );
                    }
                }
            }

            $this->decrement($baseline, $ingredientProduct, $ingredient->variant, (float) $requiredQty, 'recipe_consumption', $sale, $line, $allowNegative);
        }
    }

    /** consume_stock modifiers — mirrors SalesService::consumeLineModifiers quantities, operational only. */
    private function consumeModifiers(object $baseline, SalesOrder $sale, SalesOrderLine $line, bool $allowNegative): void
    {
        $modifiers = $line->modifiers ?? [];
        if (empty($modifiers) || ! is_array($modifiers)) {
            return;
        }
        $lineQty = (float) $line->quantity;

        foreach ($modifiers as $entry) {
            $modifierId = (int) ($entry['modifier_id'] ?? 0);
            if ($modifierId <= 0) {
                continue;
            }
            $modifier = Modifier::on('tenant')->with(['linkedProduct', 'linkedUnit'])->find($modifierId);
            if (! $modifier || ! $modifier->consume_stock) {
                continue;
            }
            $linked = $modifier->linkedProduct;
            if (! $linked) {
                throw new RuntimeException("Modifier \"{$modifier->name}\" is set to consume stock but has no linked product.");
            }
            if (! $linked->is_stock_tracked) {
                throw new RuntimeException("Modifier \"{$modifier->name}\" linked product \"{$linked->name}\" is not stock-tracked.");
            }
            $perModifierQty = (float) ($modifier->linked_quantity ?: 0);
            if ($perModifierQty <= 0) {
                continue;
            }
            $deductQty = $perModifierQty;
            if ($modifier->linked_unit_id && $linked->unit_id && $modifier->linked_unit_id !== $linked->unit_id) {
                $modifier->loadMissing(['linkedUnit']);
                $linked->loadMissing('unit');
                if ($modifier->linkedUnit && $linked->unit) {
                    try {
                        $deductQty = $this->unitConversion->convert($perModifierQty, $modifier->linkedUnit, $linked->unit);
                    } catch (RuntimeException $e) {
                        throw new RuntimeException(
                            "Modifier \"{$modifier->name}\": cannot convert {$modifier->linkedUnit->code} → {$linked->unit->code} for {$linked->name}."
                        );
                    }
                }
            }
            $consumeQty = $deductQty * $lineQty;
            if ($consumeQty <= 0) {
                continue;
            }
            $this->decrement($baseline, $linked, $this->inventory->resolveVariant($linked, null), (float) $consumeQty, 'modifier_consumption', $sale, $line, $allowNegative);
        }
    }

    /**
     * The single operational-quantity choke point: row-locked EDGE balance under the accepted baseline,
     * branch negative-stock policy, append-only EDGE movement row with canonical sale/line identities.
     */
    private function decrement(object $baseline, Product $product, ?ProductVariant $variant, float $qty, string $movementType, SalesOrder $sale, SalesOrderLine $line, bool $allowNegative): void
    {
        if ($qty <= 0) {
            return;
        }
        $variantId = $variant?->id;
        $balanceKey = $baseline->id . '-' . $product->id . '-' . ($variantId ?: 0);
        $conn = DB::connection('tenant');

        $balance = $conn->table('edge_operational_stock_balances')->where('balance_key', $balanceKey)->lockForUpdate()->first();
        if (! $balance) {
            $conn->table('edge_operational_stock_balances')->insert([
                'balance_key' => $balanceKey, 'baseline_id' => $baseline->id, 'branch_id' => $baseline->branch_id,
                'product_id' => $product->id, 'product_variant_id' => $variantId, 'quantity_on_hand' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $balance = $conn->table('edge_operational_stock_balances')->where('balance_key', $balanceKey)->lockForUpdate()->first();
        }

        $current = (float) $balance->quantity_on_hand;
        if ($current < $qty && ! $allowNegative) {
            throw new RuntimeException('Insufficient stock for ' . $product->name);
        }
        $newQty = $current - $qty;
        $conn->table('edge_operational_stock_balances')->where('id', $balance->id)->update([
            'quantity_on_hand' => $newQty, 'updated_at' => now(),
        ]);

        $conn->table('edge_operational_stock_movements')->insert([
            'movement_uuid' => (string) Str::ulid(),
            'baseline_id' => $baseline->id,
            'sale_uuid' => $sale->sale_uuid,
            'line_uuid' => $line->line_uuid,
            'product_id' => $product->id,
            'product_variant_id' => $variantId,
            'movement_type' => $movementType,
            'direction' => 'out',
            'quantity' => $qty,
            'balance_after' => $newQty,
            'activation_epoch' => (int) $baseline->activation_epoch,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
