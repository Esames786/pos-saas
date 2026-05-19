<?php

namespace App\Services\Sales;

use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\SalesLedger;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
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
        return DB::connection('tenant')->transaction(function () use ($sale) {
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
                    $product = $line->product;

                    if (!$product) {
                        continue;
                    }

                    $consumptionMethod = $product->inventory_consumption_method ?? 'stock_item';

                    if ($consumptionMethod === 'recipe') {
                        $this->recipeConsumptionService->consumeForSalesOrderLine($sale, $line, $sale->branch);
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
