<?php

namespace App\Services\Sales;

use App\Models\Tenant\SalesLedger;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\Shift;
use App\Services\Finance\JournalPostingService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;

class SalesReturnService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly SalesService $salesService,
        private readonly JournalPostingService $journalPosting,
    ) {}

    public function processReturn(
        SalesOrder $salesOrder,
        array $lines,
        ?string $reason,
        ?string $refundMethod,
        ?float $refundAmount,
        int $userId,
    ): SalesReturn {
        $salesReturn = DB::connection('tenant')->transaction(function () use (
            $salesOrder, $lines, $reason, $refundMethod, $refundAmount, $userId
        ) {
            $salesOrder->load(['branch', 'lines.product', 'lines.variant']);

            $salesReturn = SalesReturn::create([
                'return_no'          => $this->salesService->nextReturnNo(),
                'sales_order_id'     => $salesOrder->id,
                'branch_id'          => $salesOrder->branch_id,
                'return_date'        => now(),
                'subtotal'           => 0,
                'tax_amount'         => 0,
                'grand_total'        => 0,
                'refund_method'      => $refundMethod,
                'refund_amount'      => $refundAmount ?? 0,
                'status'             => 'posted',
                'created_by_user_id' => $userId,
                'reason'             => $reason,
            ]);

            $subtotal = 0;
            $tax      = 0;

            foreach ($lines as $lineData) {
                $orderLine = $salesOrder->lines->firstWhere('id', $lineData['sales_order_line_id']);

                if (!$orderLine) {
                    continue;
                }

                $qty       = min((float) $lineData['quantity'], (float) $orderLine->quantity);
                $lineTotal = $qty * (float) $orderLine->unit_price;
                $lineTax   = (float) $orderLine->quantity > 0
                    ? ((float) $orderLine->tax_amount / (float) $orderLine->quantity) * $qty
                    : 0;

                $salesReturn->lines()->create([
                    'sales_order_line_id' => $orderLine->id,
                    'product_id'          => $orderLine->product_id,
                    'product_variant_id'  => $orderLine->product_variant_id,
                    'quantity'            => $qty,
                    'unit_price'          => $orderLine->unit_price,
                    'tax_amount'          => $lineTax,
                    'line_total'          => $lineTotal,
                ]);

                $orderLine->increment('returned_quantity', $qty);

                $product = $orderLine->product;
                if ($product && $product->is_stock_tracked) {
                    $this->inventoryService->postIn(
                        branch:        $salesOrder->branch,
                        product:       $product,
                        variant:       $orderLine->variant,
                        quantity:      $qty,
                        unitCost:      (float) ($orderLine->unit_cost > 0 ? $orderLine->unit_cost : 0),
                        movementType:  'sale_return',
                        referenceType: 'sales_return',
                        referenceId:   $salesReturn->id,
                        referenceNo:   $salesReturn->return_no,
                        notes:         'Return stock in',
                        userId:        $userId,
                    );
                }

                $subtotal += $lineTotal;
                $tax      += $lineTax;
            }

            $grandTotal = $subtotal + $tax;

            $salesReturn->update([
                'subtotal'    => $subtotal,
                'tax_amount'  => $tax,
                'grand_total' => $grandTotal,
            ]);

            $salesOrder->refresh()->load('lines');
            $returnedQty = $salesOrder->lines->sum('returned_quantity');
            $originalQty = $salesOrder->lines->sum('quantity');
            $newStatus   = $returnedQty >= $originalQty ? 'returned' : 'partially_returned';
            $salesOrder->update(['status' => $newStatus]);

            SalesLedger::create([
                'branch_id'          => $salesReturn->branch_id,
                'sales_order_id'     => $salesOrder->id,
                'sale_payment_id'    => null,
                'entry_type'         => 'sale_return',
                'direction'          => 'debit',
                'amount'             => $grandTotal,
                'reference_no'       => $salesReturn->return_no,
                'created_by_user_id' => $userId,
                'notes'              => 'Sales return',
            ]);

            $this->updateShiftForReturn($salesOrder, $refundMethod, $grandTotal);

            return $salesReturn->fresh();
        });

        // FIN-7C: GL reversal + operational cash/bank refund. Idempotent + safe
        // (JournalPostingService catches/reports — never breaks return processing).
        $this->journalPosting->postSalesReturn($salesReturn, $userId);
        $this->journalPosting->postSalesReturnCashBankMovement($salesReturn, $userId);

        return $salesReturn;
    }

    private function updateShiftForReturn(SalesOrder $salesOrder, ?string $refundMethod, float $grandTotal): void
    {
        if (!$salesOrder->shift_id) {
            return;
        }

        $shift = Shift::find($salesOrder->shift_id);

        if (!$shift || $shift->status !== 'open') {
            return;
        }

        $cashRefund  = $refundMethod === 'cash'          ? $grandTotal : 0;
        $cardRefund  = $refundMethod === 'card'          ? $grandTotal : 0;
        $bankRefund  = $refundMethod === 'bank_transfer' ? $grandTotal : 0;
        $otherRefund = $refundMethod && !in_array($refundMethod, ['cash', 'card', 'bank_transfer'], true) ? $grandTotal : 0;

        $shift->increment('total_refunds', $grandTotal);

        if ($cashRefund > 0) {
            $shift->increment('total_cash_refunds', $cashRefund);
            $shift->decrement('expected_cash', $cashRefund);
        }
        if ($cardRefund > 0) {
            $shift->increment('total_card_refunds', $cardRefund);
        }
        if ($bankRefund > 0) {
            $shift->increment('total_bank_refunds', $bankRefund);
        }
        if ($otherRefund > 0) {
            $shift->increment('total_other_refunds', $otherRefund);
        }
    }
}
