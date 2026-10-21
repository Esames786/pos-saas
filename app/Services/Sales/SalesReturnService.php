<?php

namespace App\Services\Sales;

use App\Models\Tenant\Branch;
use App\Models\Tenant\InventoryBatch;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductPackUnit;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\SalesLedger;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\SalesReturnLine;
use App\Models\Tenant\Shift;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
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
                // RETURN-RESTOCK-1 (canonical, merged 22 Oct 2026): back on the shelf (default — every caller before this) or
                // thrown away; carried on the computed item so processReturn and the Edge twin read ONE source. The Edge till
                // sends no restock key today (= restocked, today's behaviour).
                'restock' => ! array_key_exists('restock', $lineData) || filter_var($lineData['restock'], FILTER_VALIDATE_BOOLEAN),
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
        // REFUND-SHIFT-1: the open shift of the counter that pays the refund (the caller resolves it).
        // Null = the sale's own shift while it is still open, as before; neither = no shift.
        ?int $refundShiftId = null,
    ): SalesReturn {
        // Defence in depth behind the request validation. A return with no refund method posts
        // Dr revenue / Cr 1500 Undeposited Funds and writes NO cash-bank movement, so the money
        // is never actually returned on the books and the drawer can never be reconciled.
        if (! $refundMethod) {
            throw new \RuntimeException('Select how the refund was paid — a return cannot be posted without a refund method.');
        }

        $salesReturn = DB::connection('tenant')->transaction(function () use (
            $salesOrder, $lines, $reason, $refundMethod, $refundAmount, $userId, $refundShiftId
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

            // REFUND-SHIFT-1 — the drawer that pays the refund, and the day it belongs to.
            //
            // REPORT-BUSINESS-DATE-1 anchored a return to the ORIGINAL sale's day, through that sale's shift —
            // right while that shift is open (a refund after midnight books to the evening's day), but a bill
            // from yesterday returned today reached no shift and no day closing at all, and changed a closed
            // day's figures. Now the refund goes into the shift open at the counter paying it, on THAT shift's
            // business day (after midnight on an evening shift = the evening's day, as before); without one,
            // the sale's own shift while still open (today's rule); without either, no shift, and the
            // branch's current business day — never a past, closed day.
            $refundShift = $this->refundShift($salesOrder, $refundShiftId);
            $businessDate = $refundShift?->business_date
                ?? app(\App\Support\TenantClock::class)->currentBusinessDate($salesOrder->branch);

            $salesReturn = SalesReturn::create([
                'return_no'          => $this->salesService->nextReturnNo(),
                'sales_order_id'     => $salesOrder->id,
                'branch_id'          => $salesOrder->branch_id,
                'return_date'        => now(),
                'business_date'      => $businessDate instanceof \DateTimeInterface ? $businessDate->format('Y-m-d') : $businessDate,
                'shift_id'           => $refundShift?->id,
                'terminal_id'        => $refundShift?->terminal_id,
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

                // RETURN-RESTOCK-1: back on the shelf (the default — every caller before this) or thrown away
                // (an opened strip, yesterday's bread, a dish): nothing goes back to stock, and the cost stays
                // a loss (the GL reverses COGS only for restocked lines).
                $restock = (bool) ($item['restock'] ?? true);
                $factor = $orderLine->base_quantity !== null && (int) $orderLine->pack_factor > 0 ? (int) $orderLine->pack_factor : null;

                $salesReturn->lines()->create([
                    'sales_order_line_id' => $orderLine->id,
                    'product_id'          => $orderLine->product_id,
                    'product_variant_id'  => $orderLine->product_variant_id,
                    'quantity'            => $qty,
                    'unit_price'          => $orderLine->unit_price,
                    'discount_amount'     => $item['discount'],
                    'tax_amount'          => $item['tax'],
                    'line_total'          => $item['line_total'],
                    'restocked'           => $restock,
                    // MEDICAL-COUNTER-1: a line sold in a pack comes back in that pack ("1 Strip" = 10 tablets)
                    'pack_unit_id'        => $factor ? $orderLine->pack_unit_id : null,
                    'pack_factor'         => $factor,
                    'base_quantity'       => $factor ? round($qty * $factor, 3) : null,
                ]);

                $orderLine->increment('returned_quantity', $qty);

                // Combo components are operational stock rows, not customer-facing return choices.
                // Restore them in the same proportion as the returned combo header.
                if ($orderLine->line_kind === 'combo_header' && $originalQty > 0) {
                    $children = $salesOrder->lines->where('parent_sales_order_line_id', $orderLine->id);
                    foreach ($children as $child) {
                        $childReturnQty = round(((float) $child->quantity / $originalQty) * $qty, 6);
                        if ($restock) {
                            $this->restoreStock($salesOrder, $salesReturn, $child, $childReturnQty, $userId);
                        }
                        $child->increment('returned_quantity', $childReturnQty);
                    }
                } elseif ($restock) {
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

            $this->updateShiftForReturn($refundShift, $refundMethod, $grandTotal);

            return $salesReturn->fresh();
        });

        // FIN-7C: GL reversal + operational cash/bank refund. Idempotent + safe
        // (JournalPostingService catches/reports — never breaks return processing).
        $this->journalPosting->postSalesReturn($salesReturn, $userId);
        $this->journalPosting->postSalesReturnCashBankMovement($salesReturn, $userId);

        return $salesReturn;
    }

    /**
     * RETURN-NO-BILL-1 — the customer brings an item back without the bill.
     *
     * Each line names the item, the pack it comes back in (Box / Strip / Tablet, or the item's own unit), how many, and
     * the price paid back — at most TODAY's price of that pack (there is no sale to read a price from). Back on the
     * shelf = stock in to the batch named (picked, or typed from the strip — required for an item with an expiry) at
     * that batch's cost, else the item's; thrown away = nothing back in stock and no cost reversal (RETURN-RESTOCK-1).
     * The refund goes into the counter's open shift and day (REFUND-SHIFT-1) and is booked like any return; the line
     * keeps the cost it went back in at, so the books reverse exactly that.
     *
     * @param  list<array{product_id:int, product_variant_id?:?int, pack_unit_id?:?int, quantity:float|string,
     *   unit_price:float|string, restock?:mixed, inventory_batch_id?:?int, batch_no?:?string, expiry?:?string}>  $lines
     */
    public function processNoBillReturn(
        Branch $branch,
        array $lines,
        string $reason,
        string $refundMethod,
        ?float $refundAmount,
        int $userId,
        ?int $refundShiftId = null,
    ): SalesReturn {
        if (trim($reason) === '') {
            throw new \RuntimeException('Write why the item is coming back — a return without a bill needs a reason.');
        }

        $salesReturn = DB::connection('tenant')->transaction(function () use (
            $branch, $lines, $reason, $refundMethod, $refundAmount, $userId, $refundShiftId
        ) {
            $refundShift = $refundShiftId
                ? Shift::query()->whereKey($refundShiftId)->where('branch_id', $branch->id)->where('status', 'open')->lockForUpdate()->first()
                : null;
            $businessDate = $refundShift?->business_date
                ?? app(\App\Support\TenantClock::class)->currentBusinessDate($branch);

            $salesReturn = SalesReturn::create([
                'return_no'          => $this->salesService->nextReturnNo(),
                'sales_order_id'     => null,
                'branch_id'          => $branch->id,
                'return_date'        => now(),
                'business_date'      => $businessDate instanceof \DateTimeInterface ? $businessDate->format('Y-m-d') : $businessDate,
                'shift_id'           => $refundShift?->id,
                'terminal_id'        => $refundShift?->terminal_id,
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

            $pricing = app(SalePricingService::class);
            $subtotal = 0.0;
            $tax = 0.0;
            $processed = 0;

            foreach ($lines as $lineData) {
                $qty = round((float) ($lineData['quantity'] ?? 0), 3);
                if ($qty <= 0) {
                    continue;
                }

                $product = Product::with(['unit', 'packUnits', 'defaultVariant'])->findOrFail((int) $lineData['product_id']);
                // No variant named = the item's default one: that is where its stock sits and where the counter sells
                // from (stock filed under "no variant" would never be sold — the PURCHASING-V2 lesson).
                $variant = ! empty($lineData['product_variant_id'])
                    ? ProductVariant::query()->whereKey((int) $lineData['product_variant_id'])->where('product_id', $product->id)->firstOrFail()
                    : $product->defaultVariant;
                $pack = ! empty($lineData['pack_unit_id'])
                    ? $product->packUnits->first(fn (ProductPackUnit $p) => (int) $p->id === (int) $lineData['pack_unit_id'] && $p->can_sell)
                    : null;
                if (! empty($lineData['pack_unit_id']) && ! $pack) {
                    throw new \RuntimeException($product->name . ': that pack is not sold.');
                }
                $factor = $pack ? (int) $pack->factor : 1;
                $unitName = $pack ? $pack->name : ($product->unit?->name ?: 'unit');

                // Whole packs; a loose item sold by weight / volume may come back in parts.
                $loose = ! $pack && $product->unit && $product->unit->unit_type !== 'quantity';
                if (! $loose && abs($qty - round($qty)) > 0.0005) {
                    throw new \RuntimeException($product->name . ': ' . $unitName . ' comes back whole — ' . $qty . ' is not a whole number.');
                }

                // Today's price of that pack is the most that can be paid back.
                $base = $pricing->resolveSellingPrice($product, $variant, (int) $branch->id, null);
                $cap = round($pack ? ($pack->sale_price !== null ? (float) $pack->sale_price : $factor * $base) : $base, 2);
                $price = round((float) $lineData['unit_price'], 2);
                if ($price > $cap + 0.005) {
                    throw new \RuntimeException($product->name . ': 1 ' . $unitName . ' is ' . number_format($cap, 2)
                        . ' today — without a bill no more than that can be paid back.');
                }

                $tracked = (bool) $product->is_stock_tracked;
                $restock = $tracked && (! array_key_exists('restock', $lineData) || filter_var($lineData['restock'], FILTER_VALIDATE_BOOLEAN));
                $baseQty = round($qty * $factor, 3);

                // The batch it goes back into: one the cashier picked, or the one printed on the strip.
                $batchNo = null;
                $expiry = null;
                if ($restock) {
                    if (! empty($lineData['inventory_batch_id'])) {
                        $picked = InventoryBatch::query()->whereKey((int) $lineData['inventory_batch_id'])
                            ->where('branch_id', $branch->id)->where('product_id', $product->id)->first();
                        if (! $picked) {
                            throw new \RuntimeException($product->name . ': that batch is not this branch\'s batch of it.');
                        }
                        $batchNo = $picked->batch_no;
                        $expiry = $picked->expiry_date?->toDateString();
                    } elseif (trim((string) ($lineData['batch_no'] ?? '')) !== '') {
                        $batchNo = trim((string) $lineData['batch_no']);
                        $expiry = \App\Support\PackLines::expiry($lineData['expiry'] ?? null);
                        if ($expiry === false) {
                            throw new \RuntimeException($product->name . ': write the expiry as MM/YY, e.g. 09/28.');
                        }
                    }
                    if ($product->has_expiry && ($batchNo === null || ! $expiry)) {
                        throw new \RuntimeException($product->name . ' has an expiry: pick its batch, or type the batch and expiry printed on it.');
                    }
                }

                $baseCost = $this->noBillCost($branch, $product, $variant, $batchNo);
                $lineSubtotal = round($qty * $price, 2);
                $lineTax = round($pricing->resolveTaxAmount($product, $qty, $price, 0, null), 2);

                $returnLine = SalesReturnLine::create([
                    'sales_return_id'     => $salesReturn->id,
                    'sales_order_line_id' => null,
                    'product_id'          => $product->id,
                    'product_variant_id'  => $variant?->id,
                    'quantity'            => $qty,
                    'unit_price'          => $price,
                    'discount_amount'     => 0,
                    'tax_amount'          => $lineTax,
                    'line_total'          => round($lineSubtotal + $lineTax, 2),
                    'restocked'           => $restock,
                    'pack_unit_id'        => $pack?->id,
                    'pack_factor'         => $pack ? $factor : null,
                    'base_quantity'       => $pack ? $baseQty : null,
                    'unit_cost'           => $tracked ? round($baseCost * $factor, 4) : null,
                ]);

                if ($restock) {
                    $ledger = $this->inventoryService->postIn(
                        branch:        $branch,
                        product:       $product,
                        variant:       $variant,
                        quantity:      $baseQty,
                        unitCost:      $baseCost,
                        movementType:  'sale_return',
                        referenceType: 'sales_return',
                        referenceId:   $salesReturn->id,
                        referenceNo:   $salesReturn->return_no,
                        batchNo:       $batchNo,
                        expiryDate:    $expiry ?: null,
                        notes:         'Return without a bill',
                        userId:        $userId,
                    );
                    $returnLine->update(['inventory_batch_id' => $ledger->inventory_batch_id]);
                }

                $subtotal += $lineSubtotal;
                $tax += $lineTax;
                $processed++;
            }

            if ($processed === 0) {
                throw new \RuntimeException('Add at least one item with a quantity.');
            }

            $subtotal = round($subtotal, 2);
            $tax = round($tax, 2);
            $grandTotal = round($subtotal + $tax, 2);
            if ($refundAmount !== null && abs($refundAmount - $grandTotal) > 0.01) {
                throw new \RuntimeException('Refund amount must match the calculated refund of ' . number_format($grandTotal, 2) . '.');
            }

            $salesReturn->update([
                'subtotal'      => $subtotal,
                'tax_amount'    => $tax,
                'grand_total'   => $grandTotal,
                'refund_amount' => $grandTotal,
            ]);

            SalesLedger::create([
                'branch_id'          => $branch->id,
                'sales_order_id'     => null,
                'sale_payment_id'    => null,
                'entry_type'         => 'sale_return',
                'direction'          => 'debit',
                'amount'             => $grandTotal,
                'reference_no'       => $salesReturn->return_no,
                'created_by_user_id' => $userId,
                'notes'              => 'Sales return (no bill)',
            ]);

            $this->updateShiftForReturn($refundShift, $refundMethod, $grandTotal);

            return $salesReturn->fresh();
        });

        $this->journalPosting->postSalesReturn($salesReturn, $userId);
        $this->journalPosting->postSalesReturnCashBankMovement($salesReturn, $userId);

        return $salesReturn;
    }

    /** RETURN-NO-BILL-1: the cost of one base unit going back in — its batch's, else the item's, else its buying price. */
    private function noBillCost(Branch $branch, Product $product, ?ProductVariant $variant, ?string $batchNo): float
    {
        $rows = StockBalance::query()->where('branch_id', $branch->id)->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)->where('average_cost', '>', 0);
        if ($batchNo !== null) {
            $batchId = InventoryBatch::query()->where('branch_id', $branch->id)->where('product_id', $product->id)
                ->where('batch_key', $this->inventoryService->batchKey((int) $branch->id, (int) $product->id, $variant?->id, $batchNo))->value('id');
            $own = $batchId ? (float) (clone $rows)->where('inventory_batch_id', $batchId)->value('average_cost') : 0.0;
            if ($own > 0) {
                return $own;
            }
        }
        $any = (float) $rows->orderByDesc('updated_at')->value('average_cost');
        if ($any > 0) {
            return $any;
        }

        return (float) ($variant?->purchase_price ?? 0) ?: (float) ($product->default_purchase_price ?? 0);
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

        // MEDICAL-COUNTER-1: a line sold in a pack ("2 Strip") is returned in that pack, but its TABLETS go
        // back on the shelf (2 × 10), at the cost of one tablet, into the batch the line left from.
        $factor = $orderLine->base_quantity !== null && (int) $orderLine->pack_factor > 0 ? (int) $orderLine->pack_factor : 1;
        $batch = ($orderLine->inventory_batch_id && $product->has_expiry
                ? InventoryBatch::query()->whereKey($orderLine->inventory_batch_id)->whereNotNull('expiry_date')->first()
                : null)
            ?? $this->batchTheSaleTookFrom($salesOrder, $orderLine, $product);

        $this->inventoryService->postIn(
            branch:        $salesOrder->branch,
            product:       $product,
            variant:       $orderLine->variant,
            quantity:      round($quantity * $factor, 3),
            unitCost:      (float) ($orderLine->unit_cost > 0 ? $orderLine->unit_cost / $factor : 0),
            movementType:  'sale_return',
            referenceType: 'sales_return',
            referenceId:   $salesReturn->id,
            referenceNo:   $salesReturn->return_no,
            batchNo:       $batch?->batch_no,
            expiryDate:    $batch?->expiry_date?->toDateString(),
            notes:         'Return stock in',
            userId:        $userId,
        );
    }

    /**
     * PHARMACY-DEMO-1 — a returned medicine goes back into a batch it was sold from, with that
     * batch's expiry. Before, every return opened a new "BATCH-<time>" with NO expiry, so a returned
     * syrup sat on the shelf undated: off the expiry report, and last in line for FEFO.
     *
     * Only products that carry an expiry, and only the sale's own stock-out rows for this product
     * ('sale' — recipe and modifier stock leave as other movement types). When the sale took from
     * two batches the earlier expiry is chosen, so the unit is sold, and flagged, first. Products
     * without an expiry, and sales with no batch on record, keep the old behaviour.
     */
    private function batchTheSaleTookFrom(SalesOrder $salesOrder, $orderLine, $product): ?InventoryBatch
    {
        if (! $product->has_expiry) {
            return null;
        }

        $batchIds = StockLedger::query()
            ->where('reference_type', 'sales_order')
            ->where('reference_id', $salesOrder->id)
            ->where('movement_type', 'sale')
            ->where('direction', 'out')
            ->where('product_id', $product->id)
            ->when($orderLine->product_variant_id,
                fn ($q, $variantId) => $q->where('product_variant_id', $variantId),
                fn ($q) => $q->whereNull('product_variant_id'))
            ->whereNotNull('inventory_batch_id')
            ->pluck('inventory_batch_id');

        return InventoryBatch::query()
            ->whereIn('id', $batchIds)
            ->whereNotNull('expiry_date')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->first();
    }

    /**
     * REFUND-SHIFT-1 — the open shift that pays this refund: the one the caller named (this branch, open),
     * else the sale's own shift while it is still open, else none. Locked, so a close cannot slip in between.
     */
    private function refundShift(SalesOrder $salesOrder, ?int $refundShiftId): ?Shift
    {
        foreach (array_filter([$refundShiftId, $salesOrder->shift_id]) as $id) {
            $shift = Shift::query()->whereKey($id)
                ->where('branch_id', $salesOrder->branch_id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();
            if ($shift) {
                return $shift;
            }
        }

        return null;
    }

    private function updateShiftForReturn(?Shift $shift, ?string $refundMethod, float $grandTotal): void
    {
        if (! $shift) {
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
