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

    /**
     * THE return arithmetic, with no side effects (F1: one formula for Online posting AND the offline appliance).
     *
     * $salesOrder must carry its lines with ['product', 'variant', 'returnLines'] loaded (locked by the caller when
     * the result is about to be posted). Returns the per-line result plus the totals exactly as processReturn posts
     * them, including the delivery-charge rule (given back only when this return leaves nothing of the order behind).
     *
     * @param  array<int,array{sales_order_line_id:int|string, quantity:float|string}>  $lines
     * @return array{
     *   lines: array<int, array{order_line: SalesOrderLine, quantity: float, subtotal: float, discount: float, tax: float, line_total: float, final: bool}>,
     *   subtotal: float, discount: float, tax: float, delivery_refund: float, grand_total: float, completes: bool, already_refunded_delivery: float
     * }
     */
    public function computeReturn(SalesOrder $salesOrder, array $lines): array
    {
        $result = [];
        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $requested = []; // order line id => qty this return takes (for the completion check)

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

            $result[] = [
                'order_line' => $orderLine,
                'quantity' => $qty,
                'subtotal' => $lineSubtotal,
                'discount' => $lineDiscount,
                'tax' => $lineTax,
                'line_total' => $lineTotal,
                'final' => $finalQuantity,
            ];
            $requested[(int) $orderLine->id] = ((float) ($requested[(int) $orderLine->id] ?? 0)) + $qty;

            $subtotal += $lineSubtotal;
            $discount += $lineDiscount;
            $tax      += $lineTax;
        }

        if ($result === []) {
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
        $completes = true;
        foreach ($salesOrder->lines as $line) {
            if (in_array($line->line_kind, ['component', 'modifier'], true)) {
                continue;   // not customer-facing; they follow their parent
            }
            $after = (float) $line->returned_quantity + (float) ($requested[(int) $line->id] ?? 0);
            if ($after + 0.000001 < (float) $line->quantity) {
                $completes = false;
                break;
            }
        }
        // ('cloud_mirror' exists only on an appliance's mirrored Cloud returns; on the Cloud this is the posted set.)
        $alreadyRefundedDelivery = (float) $salesOrder->returns()
            ->whereIn('status', ['posted', 'cloud_mirror'])
            ->sum('delivery_charge_amount');
        $deliveryRefund = $completes
            ? max(round((float) $salesOrder->delivery_charge_amount - $alreadyRefundedDelivery, 2), 0)
            : 0.0;

        return [
            'lines' => $result,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'delivery_refund' => $deliveryRefund,
            'grand_total' => round($subtotal - $discount + $tax + $deliveryRefund, 2),
            'completes' => $completes,
            'already_refunded_delivery' => $alreadyRefundedDelivery,
        ];
    }

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

            $computed = $this->computeReturn($salesOrder, $lines);

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

            foreach ($computed['lines'] as $item) {
                $orderLine = $item['order_line'];
                $qty = $item['quantity'];
                $originalQty = (float) $orderLine->quantity;

                $salesReturn->lines()->create([
                    'sales_order_line_id' => $orderLine->id,
                    'product_id'          => $orderLine->product_id,
                    'product_variant_id'  => $orderLine->product_variant_id,
                    'quantity'            => $qty,
                    'unit_price'          => $orderLine->unit_price,
                    'discount_amount'     => $item['discount'],
                    'tax_amount'          => $item['tax'],
                    'line_total'          => $item['line_total'],
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
            }

            $subtotal = $computed['subtotal'];
            $discount = $computed['discount'];
            $tax = $computed['tax'];
            $deliveryRefund = $computed['delivery_refund'];
            $grandTotal = $computed['grand_total'];

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
