<?php

namespace App\Services\Sales;

use App\Models\Tenant\SalesLedger;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
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
        // Defence in depth behind the request validation. A return with no refund method posts
        // Dr revenue / Cr 1500 Undeposited Funds and writes NO cash-bank movement, so the money
        // is never actually returned on the books and the drawer can never be reconciled.
        if (! $refundMethod) {
            throw new \RuntimeException('Select how the refund was paid — a return cannot be posted without a refund method.');
        }

        $salesReturn = DB::connection('tenant')->transaction(function () use (
            $salesOrder, $lines, $reason, $refundMethod, $refundAmount, $userId
        ) {
            $salesOrder = SalesOrder::query()
                ->whereKey($salesOrder->id)
                ->lockForUpdate()
                ->firstOrFail();
            $salesOrder->load('branch');
            $salesOrder->setRelation('lines', SalesOrderLine::query()
                ->where('sales_order_id', $salesOrder->id)
                ->with(['product', 'variant', 'returnLines'])
                ->lockForUpdate()
                ->get());

            $salesReturn = SalesReturn::create([
                'return_no'          => $this->salesService->nextReturnNo(),
                'sales_order_id'     => $salesOrder->id,
                'branch_id'          => $salesOrder->branch_id,
                'return_date'        => now(),
                // Anchor the return to the SAME business day as the order it reverses (and whose
                // shift cash it adjusts below) — never the wall-clock date. So a refund punched
                // after midnight on a still-open pre-midnight shift books to that shift's day.
                'business_date'      => $salesOrder->business_date,
                'subtotal'           => 0,
                'discount_amount'    => 0,
                'tax_amount'         => 0,
                'grand_total'        => 0,
                'refund_method'      => $refundMethod,
                'refund_amount'      => $refundAmount ?? 0,
                'status'             => 'posted',
                'created_by_user_id' => $userId,
                'reason'             => $reason,
            ]);

            $subtotal = 0;
            $discount = 0;
            $tax      = 0;
            $processedLines = 0;

            foreach ($lines as $lineData) {
                $orderLine = $salesOrder->lines->firstWhere('id', $lineData['sales_order_line_id']);

                if (!$orderLine) {
                    throw new \RuntimeException('A selected return line does not belong to this sale.');
                }

                if (in_array($orderLine->line_kind, ['component', 'modifier'], true)) {
                    throw new \RuntimeException('Component and modifier rows cannot be returned separately. Return their parent sale item.');
                }

                // BUG-011 FIX: cap against remaining returnable qty, not original qty.
                $alreadyReturned = (float) $orderLine->returned_quantity;
                $returnable      = (float) $orderLine->quantity - $alreadyReturned;

                if ($returnable <= 0) {
                    continue; // fully returned already
                }

                $qty = min((float) $lineData['quantity'], $returnable);
                if ($qty <= 0) {
                    continue;
                }

                $originalQty = (float) $orderLine->quantity;
                $lineSubtotal = round($qty * (float) $orderLine->unit_price, 2);
                $allocation = $this->originalLineAllocation($salesOrder, $orderLine);
                $finalQuantity = $qty + 0.000001 >= $returnable;
                $remainingDiscount = max($allocation['discount'] - (float) $orderLine->returnLines->sum('discount_amount'), 0);
                $remainingTax = max($allocation['tax'] - (float) $orderLine->returnLines->sum('tax_amount'), 0);
                $lineDiscount = $finalQuantity
                    ? round($remainingDiscount, 2)
                    : min(round(($remainingDiscount / $returnable) * $qty, 2), round($remainingDiscount, 2));
                $lineTax = $finalQuantity
                    ? round($remainingTax, 2)
                    : min(round(($remainingTax / $returnable) * $qty, 2), round($remainingTax, 2));
                $lineTotal = round($lineSubtotal - $lineDiscount + $lineTax, 2);

                $salesReturn->lines()->create([
                    'sales_order_line_id' => $orderLine->id,
                    'product_id'          => $orderLine->product_id,
                    'product_variant_id'  => $orderLine->product_variant_id,
                    'quantity'            => $qty,
                    'unit_price'          => $orderLine->unit_price,
                    'discount_amount'     => $lineDiscount,
                    'tax_amount'          => $lineTax,
                    'line_total'          => $lineTotal,
                ]);

                $orderLine->increment('returned_quantity', $qty);

                // Combo components are operational stock rows, not customer-facing return choices.
                // Restore them in the same proportion as the returned combo header.
                if ($orderLine->line_kind === 'combo_header' && $originalQty > 0) {
                    $children = $salesOrder->lines->where('parent_sales_order_line_id', $orderLine->id);
                    foreach ($children as $child) {
                        $childReturnQty = round(((float) $child->quantity / $originalQty) * $qty, 6);
                        $this->restoreStock($salesOrder, $salesReturn, $child, $childReturnQty, $userId);
                        $child->increment('returned_quantity', $childReturnQty);
                    }
                } else {
                    $this->restoreStock($salesOrder, $salesReturn, $orderLine, $qty, $userId);
                }

                $subtotal += $lineSubtotal;
                $discount += $lineDiscount;
                $tax      += $lineTax;
                $processedLines++;
            }

            if ($processedLines === 0) {
                throw new \RuntimeException('Select at least one returnable sale item.');
            }

            $subtotal = round($subtotal, 2);
            $discount = round($discount, 2);
            $tax = round($tax, 2);

            // THE WHOLE ORDER COMING BACK MUST GIVE BACK THE WHOLE CHARGE.
            //
            // Returns used to stop at subtotal − discount + tax, so the delivery charge could
            // never be refunded. When a customer sent an entire order back the counter handed over
            // the full amount, but the system recorded less money leaving than actually did — at
            // Khatri that stranded 350 as "delivery income" the shop had already given back, and
            // the close screen expected 350 more cash than the drawer held.
            //
            // A PARTIAL return keeps the charge: the rider still made that trip for the items the
            // customer kept. It is only refunded when nothing of the order remains.
            $deliveryRefund = 0.0;
            $alreadyRefundedDelivery = (float) $salesOrder->returns()
                ->where('status', 'posted')->where('id', '!=', $salesReturn->id)
                ->sum('delivery_charge_amount');

            if ($this->returnCompletesOrder($salesOrder)) {
                $deliveryRefund = max(
                    round((float) $salesOrder->delivery_charge_amount - $alreadyRefundedDelivery, 2),
                    0
                );
            }

            $grandTotal = round($subtotal - $discount + $tax + $deliveryRefund, 2);

            if ($refundMethod && $refundAmount !== null && abs($refundAmount - $grandTotal) > 0.01) {
                throw new \RuntimeException('Refund amount must match the calculated refund of ' . number_format($grandTotal, 2) . '.');
            }

            $salesReturn->update([
                'subtotal'    => $subtotal,
                'discount_amount' => $discount,
                'tax_amount'  => $tax,
                'delivery_charge_amount' => $deliveryRefund,
                'grand_total' => $grandTotal,
                'refund_amount' => $refundMethod ? $grandTotal : 0,
            ]);

            $salesOrder->refresh()->load('lines');
            $customerLines = $salesOrder->lines->reject(
                fn ($line) => in_array($line->line_kind, ['component', 'modifier'], true)
            );
            $returnedQty = $customerLines->sum('returned_quantity');
            $originalQty = $customerLines->sum('quantity');
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

    /**
     * Does this return leave nothing of the order behind?
     *
     * Compares what has ALREADY been returned plus what this return takes against the original
     * quantities. Only then is the delivery charge given back — a partial return keeps it, because
     * the rider still made the trip for the items the customer kept.
     */
    private function returnCompletesOrder(SalesOrder $salesOrder): bool
    {
        // Called AFTER the line loop, which has already incremented returned_quantity on each
        // line it processed (increment() syncs the in-memory attribute as well as the row), so
        // this return's own quantities are counted here — adding them again would make a half
        // return look complete and refund the delivery charge early.
        foreach ($salesOrder->lines as $line) {
            if (in_array($line->line_kind, ['component', 'modifier'], true)) {
                continue;   // not customer-facing; they follow their parent
            }

            if ((float) $line->returned_quantity + 0.000001 < (float) $line->quantity) {
                return false;
            }
        }

        return true;
    }

    /**
     * Allocate the sale's complete discount back to customer-facing lines. Line discounts
     * stay on their source line; order/promo discount is spread by original gross value.
     */
    public function originalLineAllocation(SalesOrder $salesOrder, SalesOrderLine $orderLine): array
    {
        $eligibleLines = $salesOrder->lines->reject(
            fn (SalesOrderLine $line) => in_array($line->line_kind, ['component', 'modifier'], true)
        );
        $eligibleGross = (float) $eligibleLines->sum(
            fn (SalesOrderLine $line) => (float) $line->quantity * (float) $line->unit_price
        );
        $lineDiscountTotal = (float) $eligibleLines->sum('discount_amount');
        $orderDiscountRemainder = max((float) $salesOrder->discount_amount - $lineDiscountTotal, 0);
        $lineGross = (float) $orderLine->quantity * (float) $orderLine->unit_price;
        $allocatedOrderDiscount = $eligibleGross > 0
            ? $orderDiscountRemainder * ($lineGross / $eligibleGross)
            : 0;

        return [
            'discount' => round((float) $orderLine->discount_amount + $allocatedOrderDiscount, 2),
            'tax' => round((float) $orderLine->tax_amount, 2),
        ];
    }

    private function restoreStock(
        SalesOrder $salesOrder,
        SalesReturn $salesReturn,
        $orderLine,
        float $quantity,
        int $userId,
    ): void {
        $product = $orderLine->product;
        if ($quantity <= 0 || ! $product || ! $product->is_stock_tracked) {
            return;
        }

        $this->inventoryService->postIn(
            branch:        $salesOrder->branch,
            product:       $product,
            variant:       $orderLine->variant,
            quantity:      $quantity,
            unitCost:      (float) ($orderLine->unit_cost > 0 ? $orderLine->unit_cost : 0),
            movementType:  'sale_return',
            referenceType: 'sales_return',
            referenceId:   $salesReturn->id,
            referenceNo:   $salesReturn->return_no,
            notes:         'Return stock in',
            userId:        $userId,
        );
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
