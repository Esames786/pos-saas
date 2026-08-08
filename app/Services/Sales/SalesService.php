<?php

namespace App\Services\Sales;

use App\Models\Tenant\Modifier;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\SalesLedger;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\Shift;
use App\Services\Finance\JournalPostingService;
use App\Services\Inventory\InventoryService;
use App\Services\Kitchen\RecipeConsumptionService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SalesService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly RecipeConsumptionService $recipeConsumptionService,
    ) {
    }

    public function finalizePaidSale(SalesOrder $sale): SalesOrder
    {
        // EDGE-LOCAL-POS-1 (H9) — defence-in-depth: this is the Cloud-authoritative finalize (GL/COGS/FEFO/
        // cash-bank/department). It must NEVER run on a Branch Server. The offline path uses EdgeLocalPosService
        // + EdgeOperationalStockService and never reaches here; any accidental invocation fails CLOSED before
        // a single authoritative row is written.
        if (\App\Support\EdgeRuntime::isBranchServer()) {
            throw new \RuntimeException('finalizePaidSale (Cloud accounting authority) must not run on a Branch Server.');
        }

        $sale = DB::connection('tenant')->transaction(function () use ($sale) {
            $sale->load([
                'branch',
                'terminal',
                'shift',
                'lines.product',
                'lines.variant',
                'payments.method',
            ]);

            if ($sale->status === 'paid') {
                return $sale;
            }

            $paidAmount = (float) $sale->payments->sum('amount');

            if ($paidAmount + 0.01 < (float) $sale->grand_total) {
                throw new RuntimeException('Sale must be fully paid before posting.');
            }

            if (!$sale->inventory_posted) {
                foreach ($sale->lines as $line) {
                    if (($line->line_kind ?? 'standard') === 'combo_header') {
                        continue;
                    }

                    $product = $line->product;

                    if (!$product) {
                        continue;
                    }

                    $consumptionMethod = $product->inventory_consumption_method ?? 'stock_item';

                    if ($consumptionMethod === 'recipe') {
                        // FIN-11A: capture the consumed ingredient cost as the line COGS
                        // so postPaidSale posts Dr 5100 COGS / Cr 1400 Inventory.
                        $recipeCost = $this->recipeConsumptionService->consumeForSalesOrderLine($sale, $line, $sale->branch);

                        if ($recipeCost > 0) {
                            $quantity = (float) $line->quantity;
                            $line->update([
                                'unit_cost'  => $quantity > 0 ? round($recipeCost / $quantity, 4) : 0,
                                'cost_total' => round($recipeCost, 4),
                            ]);
                        }
                    } elseif ($consumptionMethod === 'stock_item' && $product->is_stock_tracked) {
                        $ledgers = $this->inventoryService->postOutFefo(
                            branch: $sale->branch,
                            product: $product,
                            variant: $line->variant,
                            quantity: (float) $line->quantity,
                            movementType: 'sale',
                            referenceType: 'sales_order',
                            referenceId: $sale->id,
                            referenceNo: $sale->sale_no,
                            notes: 'Sale stock out',
                            userId: $sale->created_by_user_id,
                            allowNegative: (bool) $sale->branch->allow_negative_stock,
                        );

                        $costTotal = collect($ledgers)->sum(fn ($ledger) => (float) $ledger->total_cost);
                        $unitCost  = (float) $line->quantity > 0 ? $costTotal / (float) $line->quantity : 0;

                        $line->update([
                            'unit_cost'  => $unitCost,
                            'cost_total' => $costTotal,
                        ]);
                    }
                    // 'none': skip inventory entirely

                    // MODIFIER-INVENTORY-1: deduct linked stock for any consume_stock
                    // modifier options on this line, adding their cost on top of line COGS.
                    $modifierCost = $this->consumeLineModifiers($sale, $line);
                    if ($modifierCost > 0) {
                        $qty          = (float) $line->quantity;
                        $newCostTotal = round((float) $line->cost_total + $modifierCost, 4);
                        $line->update([
                            'cost_total' => $newCostTotal,
                            'unit_cost'  => $qty > 0 ? round($newCostTotal / $qty, 4) : 0,
                        ]);
                    }
                }
            }

            $changeAmount = max($paidAmount - (float) $sale->grand_total, 0);

            $sale->update([
                'paid_amount'       => $paidAmount,
                'change_amount'     => $changeAmount,
                'status'            => 'paid',
                'inventory_posted'  => true,
                'completed_at'      => now(),
            ]);

            $sale->refresh()->load(['payments.method']);

            // EDGE-LOCAL-POS-1 (G): the operational settlement (sales subledger + shift counters) is the
            // SHARED Cloud/Edge implementation — one set of rules, no drift.
            app(SaleOperationalSettlementService::class)->settle($sale);
            $this->closeRestaurantTableSession($sale);

            return $sale->fresh();
        });

        // FIN-7B: GL posting for normal paid sales. The operational sale flow above
        // remains the source of truth; journal posting is idempotent and never throws
        // (JournalPostingService catches/reports), so it can never break checkout.
        $journalPosting = app(JournalPostingService::class);
        $journalPosting->postPaidSale($sale, $sale->created_by_user_id);
        // FIN-7C: operational cash/bank balance movement for POS receipts (safe + idempotent).
        $journalPosting->postSalesCashBankMovement($sale, $sale->created_by_user_id);

        // DEPT-3A: shadow department custody deduction mirroring the official
        // out-movements above. Custody sub-ledger ONLY (no stock/COGS/GL twice);
        // idempotent per ledger; shortages/no-mapping become exception rows —
        // this can NEVER block or fail the sale.
        try {
            app(\App\Services\Departments\DepartmentConsumptionService::class)->processSaleOrder($sale);
        } catch (\Throwable $e) {
            report($e);
        }

        return $sale;
    }

    /**
     * MODIFIER-INVENTORY-1 — deduct linked inventory for the consume_stock modifier
     * options selected on a sale line, returning the total cost consumed (added to the
     * line COGS by the caller). Runs only inside the inventory_posted guard, so a
     * repeated finalize cannot double-post. Throws (rolls back the sale) when a
     * stock-consuming modifier is misconfigured or its linked product is short on stock.
     */
    private function consumeLineModifiers(SalesOrder $sale, SalesOrderLine $line): float
    {
        $modifiers = $line->modifiers ?? [];

        if (empty($modifiers) || ! is_array($modifiers)) {
            return 0.0;
        }

        $lineQty   = (float) $line->quantity;
        $totalCost = 0.0;

        foreach ($modifiers as $entry) {
            $modifierId = (int) ($entry['modifier_id'] ?? 0);
            if ($modifierId <= 0) {
                continue;
            }

            $modifier = Modifier::with(['linkedProduct', 'linkedUnit'])->find($modifierId);

            // Price-only / removed / non-consuming options never touch stock.
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

            // BUG-008 FIX: convert from modifier's linked_unit to the product's stock unit
            // before deducting. Without this, a modifier configured as "50 G" on a product
            // stocked in KG would deduct 50 KG instead of 0.05 KG.
            $deductQty = $perModifierQty;
            if ($modifier->linked_unit_id && $linked->unit_id && $modifier->linked_unit_id !== $linked->unit_id) {
                $modifier->loadMissing(['linkedUnit']);
                $linked->loadMissing('unit');
                if ($modifier->linkedUnit && $linked->unit) {
                    try {
                        $unitConversion = app(\App\Services\Kitchen\UnitConversionService::class);
                        $deductQty = $unitConversion->convert($perModifierQty, $modifier->linkedUnit, $linked->unit);
                    } catch (\RuntimeException $e) {
                        throw new RuntimeException(
                            "Modifier \"{$modifier->name}\": cannot convert"
                            . " {$modifier->linkedUnit->code} → {$linked->unit->code}"
                            . " for {$linked->name}. Add a unit conversion rule under Catalog → Unit Conversions."
                        );
                    }
                }
            }

            // 1 selected modifier per cart entry × line quantity.
            $consumeQty = $deductQty * $lineQty;
            if ($consumeQty <= 0) {
                continue;
            }

            $variant = $this->inventoryService->resolveVariant($linked, null);

            try {
                $ledgers = $this->inventoryService->postOutFefo(
                    branch: $sale->branch,
                    product: $linked,
                    variant: $variant,
                    quantity: $consumeQty,
                    movementType: 'modifier_consumption',
                    referenceType: 'sales_order',
                    referenceId: $sale->id,
                    referenceNo: $sale->sale_no,
                    notes: "Modifier consumption: {$modifier->name} for {$line->product_name}",
                    userId: $sale->created_by_user_id,
                    allowNegative: (bool) $sale->branch->allow_negative_stock,
                );
            } catch (\RuntimeException $e) {
                throw new RuntimeException(
                    "Cannot complete sale — modifier \"{$modifier->name}\" ({$linked->name}): " . $e->getMessage()
                );
            }

            $totalCost += (float) collect($ledgers)->sum(fn ($ledger) => (float) $ledger->total_cost);
        }

        return round($totalCost, 4);
    }

    private function closeRestaurantTableSession(SalesOrder $sale): void
    {
        if (!$sale->restaurant_table_session_id) {
            return;
        }

        $session = RestaurantTableSession::with('table')->find($sale->restaurant_table_session_id);

        if (!$session || !in_array($session->status, ['open', 'bill_requested'], true)) {
            return;
        }

        $remainingHeld = SalesOrder::where('restaurant_table_session_id', $session->id)
            ->where('status', 'held')
            ->where('id', '!=', $sale->id)
            ->exists();

        if ($remainingHeld) {
            return;
        }

        $session->update([
            'status'            => 'closed',
            'closed_at'         => now(),
            'closed_by_user_id' => $sale->created_by_user_id,
        ]);

        $session->table?->update(['status' => 'available']);
    }

    public function nextSaleNo(): string
    {
        return 'SO-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    public function nextReturnNo(): string
    {
        return 'SR-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    public function paymentMethodType(int $paymentMethodId): string
    {
        return PaymentMethod::findOrFail($paymentMethodId)->method_type;
    }
}
