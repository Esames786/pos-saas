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
                            userId: $sale->created_by_user_id
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

            $this->postSalesLedger($sale);
            $this->updateShiftTotals($sale);
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

            $modifier = Modifier::with(['linkedProduct'])->find($modifierId);

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

            // 1 selected modifier per cart entry × line quantity.
            $consumeQty = $perModifierQty * $lineQty;
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
                );
            } catch (RuntimeException $e) {
                throw new RuntimeException(
                    "Cannot complete sale — modifier \"{$modifier->name}\" ({$linked->name}): " . $e->getMessage()
                );
            }

            $totalCost += (float) collect($ledgers)->sum(fn ($ledger) => (float) $ledger->total_cost);
        }

        return round($totalCost, 4);
    }

    private function postSalesLedger(SalesOrder $sale): void
    {
        SalesLedger::firstOrCreate(
            [
                'sales_order_id' => $sale->id,
                'entry_type'     => 'sale_total',
            ],
            [
                'branch_id'           => $sale->branch_id,
                'sale_payment_id'     => null,
                'direction'           => 'credit',
                'amount'              => $sale->grand_total,
                'reference_no'        => $sale->sale_no,
                'created_by_user_id'  => $sale->created_by_user_id,
                'notes'               => 'Sale posted',
            ]
        );

        foreach ($sale->payments as $payment) {
            SalesLedger::firstOrCreate(
                [
                    'sales_order_id'  => $sale->id,
                    'sale_payment_id' => $payment->id,
                    'entry_type'      => 'sale_payment',
                ],
                [
                    'branch_id'          => $sale->branch_id,
                    'direction'          => 'debit',
                    'amount'             => $payment->amount,
                    'reference_no'       => $sale->sale_no,
                    'created_by_user_id' => $sale->created_by_user_id,
                    'notes'              => 'Sale payment received',
                ]
            );
        }

        if ((float) $sale->discount_amount > 0) {
            SalesLedger::firstOrCreate(
                [
                    'sales_order_id' => $sale->id,
                    'entry_type'     => 'sale_discount',
                ],
                [
                    'branch_id'          => $sale->branch_id,
                    'sale_payment_id'    => null,
                    'direction'          => 'debit',
                    'amount'             => $sale->discount_amount,
                    'reference_no'       => $sale->sale_no,
                    'created_by_user_id' => $sale->created_by_user_id,
                    'notes'              => 'Sale discount',
                ]
            );
        }

        if ((float) $sale->tax_amount > 0) {
            SalesLedger::firstOrCreate(
                [
                    'sales_order_id' => $sale->id,
                    'entry_type'     => 'sale_tax',
                ],
                [
                    'branch_id'          => $sale->branch_id,
                    'sale_payment_id'    => null,
                    'direction'          => 'credit',
                    'amount'             => $sale->tax_amount,
                    'reference_no'       => $sale->sale_no,
                    'created_by_user_id' => $sale->created_by_user_id,
                    'notes'              => 'Sale tax',
                ]
            );
        }

        if ((float) ($sale->service_charge_amount ?? 0) > 0) {
            SalesLedger::firstOrCreate(
                [
                    'sales_order_id' => $sale->id,
                    'entry_type'     => 'service_charge',
                ],
                [
                    'branch_id'          => $sale->branch_id,
                    'sale_payment_id'    => null,
                    'direction'          => 'credit',
                    'amount'             => $sale->service_charge_amount,
                    'reference_no'       => $sale->sale_no,
                    'created_by_user_id' => $sale->created_by_user_id,
                    'notes'              => 'Service charge',
                ]
            );
        }

        if ((float) ($sale->tip_amount ?? 0) > 0) {
            SalesLedger::firstOrCreate(
                [
                    'sales_order_id' => $sale->id,
                    'entry_type'     => 'tip',
                ],
                [
                    'branch_id'          => $sale->branch_id,
                    'sale_payment_id'    => null,
                    'direction'          => 'credit',
                    'amount'             => $sale->tip_amount,
                    'reference_no'       => $sale->sale_no,
                    'created_by_user_id' => $sale->created_by_user_id,
                    'notes'              => 'Tip',
                ]
            );
        }
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

    private function updateShiftTotals(SalesOrder $sale): void
    {
        if (!$sale->shift_id) {
            return;
        }

        $shift = Shift::find($sale->shift_id);

        if (!$shift || $shift->status !== 'open') {
            return;
        }

        $cash         = 0;
        $card         = 0;
        $bankTransfer = 0;
        $cheque       = 0;

        foreach ($sale->payments as $payment) {
            $type = $payment->method?->method_type;

            if ($type === 'cash') {
                $cash += (float) $payment->amount;
            }
            if ($type === 'card') {
                $card += (float) $payment->amount;
            }
            if ($type === 'bank_transfer') {
                $bankTransfer += (float) $payment->amount;
            }
            if ($type === 'cheque') {
                $cheque += (float) $payment->amount;
            }
        }

        $shift->update([
            'total_sales'         => (float) $shift->total_sales + (float) $sale->grand_total,
            'total_cash'          => (float) $shift->total_cash + $cash,
            'total_card'          => (float) $shift->total_card + $card,
            'total_bank_transfer' => (float) $shift->total_bank_transfer + $bankTransfer,
            'total_cheque'        => (float) $shift->total_cheque + $cheque,
            'total_discount'      => (float) $shift->total_discount + (float) $sale->discount_amount,
            'total_tax'           => (float) $shift->total_tax + (float) $sale->tax_amount,
            'expected_cash'       => (float) $shift->expected_cash + $cash,
        ]);
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
