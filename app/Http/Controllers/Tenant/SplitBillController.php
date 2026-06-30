<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\Shift;
use App\Services\Sales\SalesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SplitBillController extends Controller
{
    public function create(SalesOrder $salesOrder)
    {
        $salesOrder->load([
            'branch',
            'terminal',
            'customer',
            'restaurantTableSession.table',
            'restaurantWaiter',
            'lines.product',
            'lines.variant',
        ]);

        if ($salesOrder->status !== 'held') {
            return back()->withErrors(['sale' => 'Only held sales can be split.']);
        }

        return view('tenant.sales-orders.split-bill', [
            'salesOrder'     => $salesOrder,
            'paymentMethods' => PaymentMethod::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, SalesOrder $salesOrder, SalesService $salesService)
    {
        $data = $request->validate([
            'payment_method_id'              => ['required', 'exists:payment_methods,id'],
            'tendered_amount'                => ['nullable', 'numeric', 'min:0'],
            'transaction_ref'               => ['nullable', 'string', 'max:190'],
            'notes'                          => ['nullable', 'string'],
            'lines'                          => ['required', 'array'],
            'lines.*.sales_order_line_id'    => ['nullable', 'exists:sales_order_lines,id'],
            'lines.*.quantity'               => ['nullable', 'numeric', 'min:0.001'],
        ]);

        $selectedLines = collect($data['lines'])
            ->filter(fn ($line) => !empty($line['sales_order_line_id']) && !empty($line['quantity']))
            ->values();

        if ($selectedLines->isEmpty()) {
            return back()->withErrors(['split' => 'Select at least one item quantity to split.'])->withInput();
        }

        try {
            $paidSale = DB::connection('tenant')->transaction(function () use ($salesOrder, $selectedLines, $data, $salesService) {
                $salesOrder->refresh()->load(['lines.product', 'lines.variant']);

                if ($salesOrder->status !== 'held') {
                    throw new RuntimeException('Only held sales can be split.');
                }

                $terminalId = $salesOrder->terminal_id;
                $shiftId    = $salesOrder->shift_id;

                if (!$shiftId) {
                    $openShift = Shift::where('branch_id', $salesOrder->branch_id)
                        ->where('status', 'open')
                        ->when($terminalId, fn ($q) => $q->where('terminal_id', $terminalId))
                        ->latest('opened_at')
                        ->first();
                    $shiftId = $openShift?->id;
                }

                $newSale = SalesOrder::create([
                    'sale_no'                     => $salesService->nextSaleNo(),
                    'branch_id'                   => $salesOrder->branch_id,
                    'terminal_id'                 => $terminalId,
                    'shift_id'                    => $shiftId,
                    'customer_id'                 => $salesOrder->customer_id,
                    'restaurant_floor_id'          => $salesOrder->restaurant_floor_id,
                    'restaurant_table_id'          => $salesOrder->restaurant_table_id,
                    'restaurant_table_session_id'  => $salesOrder->restaurant_table_session_id,
                    'restaurant_waiter_id'         => $salesOrder->restaurant_waiter_id,
                    'customer_name'               => $salesOrder->customer_name,
                    'customer_phone'              => $salesOrder->customer_phone,
                    'customer_email'              => $salesOrder->customer_email,
                    'order_source'                => $salesOrder->order_source,
                    'order_type'                  => $salesOrder->order_type,
                    'sale_date'                   => now(),
                    'subtotal'                    => 0,
                    'discount_type'               => 'none',
                    'discount_value'              => 0,
                    'discount_amount'             => 0,
                    'tax_amount'                  => 0,
                    'grand_total'                 => 0,
                    'paid_amount'                 => 0,
                    'change_amount'               => 0,
                    'status'                      => 'draft',
                    'inventory_posted'            => false,
                    'created_by_user_id'          => auth('tenant')->id(),
                    'notes'                       => $data['notes'] ?? 'Split from ' . $salesOrder->sale_no,
                ]);

                $subtotal    = 0;
                $discountTotal = 0;
                $taxTotal    = 0;
                $grandTotal  = 0;

                foreach ($selectedLines as $lineInput) {
                    $heldLine = SalesOrderLine::with(['product', 'variant'])
                        ->where('sales_order_id', $salesOrder->id)
                        ->findOrFail($lineInput['sales_order_line_id']);

                    $splitQty     = (float) $lineInput['quantity'];
                    $availableQty = (float) $heldLine->quantity;

                    if ($splitQty <= 0) {
                        continue;
                    }

                    if ($splitQty > $availableQty + 0.0001) {
                        throw new RuntimeException('Split quantity exceeds available quantity for ' . $heldLine->product_name . '.');
                    }

                    $ratio = $availableQty > 0 ? $splitQty / $availableQty : 0;

                    $splitDiscount  = round((float) $heldLine->discount_amount * $ratio, 2);
                    $splitTax       = round((float) $heldLine->tax_amount * $ratio, 2);
                    $splitSubtotal  = round($splitQty * (float) $heldLine->unit_price, 2);
                    $splitLineTotal = max($splitSubtotal - $splitDiscount + $splitTax, 0);

                    $newSale->lines()->create([
                        'product_id'         => $heldLine->product_id,
                        'product_variant_id' => $heldLine->product_variant_id,
                        'product_name'       => $heldLine->product_name,
                        'variant_name'       => $heldLine->variant_name,
                        'quantity'           => $splitQty,
                        'unit_price'         => $heldLine->unit_price,
                        'unit_cost'          => 0,
                        'cost_total'         => 0,
                        'discount_amount'    => $splitDiscount,
                        'tax_amount'         => $splitTax,
                        'line_total'         => $splitLineTotal,
                        // BUG-047 FIX: copy modifiers so modifier stock is consumed on finalize.
                        'modifiers'          => $heldLine->modifiers ?? [],
                        'unit_code'          => $heldLine->unit_code,
                        'line_kind'          => $heldLine->line_kind ?? 'standard',
                        'combo_id'           => $heldLine->combo_id,
                        'kitchen_note'       => $heldLine->kitchen_note,
                    ]);

                    $subtotal      += $splitSubtotal;
                    $discountTotal += $splitDiscount;
                    $taxTotal      += $splitTax;
                    $grandTotal    += $splitLineTotal;

                    $remainingQty = $availableQty - $splitQty;

                    if ($remainingQty <= 0.0001) {
                        $heldLine->delete();
                    } else {
                        $remainingRatio = $remainingQty / $availableQty;
                        $heldLine->update([
                            'quantity'        => $remainingQty,
                            'discount_amount' => round((float) $heldLine->discount_amount * $remainingRatio, 2),
                            'tax_amount'      => round((float) $heldLine->tax_amount * $remainingRatio, 2),
                            'line_total'      => round((float) $heldLine->line_total * $remainingRatio, 2),
                        ]);
                    }
                }

                if ($newSale->lines()->count() === 0) {
                    throw new RuntimeException('No valid split lines found.');
                }

                $newSale->update([
                    'subtotal'              => $subtotal,
                    'discount_amount'       => $discountTotal,
                    'tax_amount'            => $taxTotal,
                    'grand_total'           => $grandTotal,
                    // BUG-048 FIX: recalculate service charge proportionally for the split portion.
                    // Pro-rate based on split subtotal vs original held sale subtotal.
                    'service_charge_amount' => (function () use ($salesOrder, $subtotal) {
                        $origSubtotal = (float) $salesOrder->subtotal;
                        $origSC       = (float) $salesOrder->service_charge_amount;
                        if ($origSubtotal <= 0 || $origSC <= 0) {
                            return 0;
                        }
                        return round($origSC * ($subtotal / $origSubtotal), 2);
                    })(),
                ]);

                // Recalculate grand_total to include split service charge.
                $splitSC     = (float) $newSale->fresh()->service_charge_amount;
                $grandTotal += $splitSC;
                $newSale->update(['grand_total' => $grandTotal]);

                $tendered = isset($data['tendered_amount']) && $data['tendered_amount'] !== null
                    ? (float) $data['tendered_amount']
                    : $grandTotal;

                $paymentMethod = PaymentMethod::find($data['payment_method_id']);
                $isCash        = $paymentMethod?->method_type === 'cash';
                $changeAmount  = $isCash ? max($tendered - $grandTotal, 0) : 0;

                $newSale->payments()->create([
                    'payment_method_id' => $data['payment_method_id'],
                    'amount'            => $grandTotal,
                    'tendered_amount'   => $tendered,
                    'change_amount'     => $changeAmount,
                    'transaction_ref'   => $data['transaction_ref'] ?? null,
                ]);

                $this->recalculateHeldSale($salesOrder);

                return $salesService->finalizePaidSale($newSale);
            });
        } catch (RuntimeException $e) {
            return back()->withErrors(['split' => $e->getMessage()])->withInput();
        }

        return redirect(url('/sales-orders/' . $paidSale->id))
            ->with('status', 'Split bill paid successfully.');
    }

    private function recalculateHeldSale(SalesOrder $salesOrder): void
    {
        $salesOrder->refresh()->load('lines');

        if ($salesOrder->lines->isEmpty()) {
            $salesOrder->update([
                'subtotal'        => 0,
                'discount_amount' => 0,
                'tax_amount'      => 0,
                'grand_total'     => 0,
                'status'          => 'cancelled',
            ]);

            return;
        }

        $subtotal = 0;
        $discount = 0;
        $tax      = 0;
        $total    = 0;

        foreach ($salesOrder->lines as $line) {
            $subtotal += (float) $line->quantity * (float) $line->unit_price;
            $discount += (float) $line->discount_amount;
            $tax      += (float) $line->tax_amount;
            $total    += (float) $line->line_total;
        }

        $salesOrder->update([
            'subtotal'        => $subtotal,
            'discount_amount' => $discount,
            'tax_amount'      => $tax,
            'grand_total'     => $total,
        ]);
    }
}
