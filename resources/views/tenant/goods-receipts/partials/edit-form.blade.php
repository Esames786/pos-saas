{{-- PURCHASE-EDIT-1 — the receipt's Details and Received Lines as boxes, on the receipt's own page (no new screen). The
     client: "GRN kholein aur rate daalein, sab khud seedha ho jaye." Qty and Unit Cost are the same columns the page
     shows; 0 takes a line off. The totals and the difference follow the typing. Saved by GoodsReceiptController@revise. --}}
@php
    $bill = $goodsReceipt->bill;
    $fmt = fn ($n, $dp) => rtrim(rtrim(number_format((float) $n, $dp, '.', ''), '0'), '.');
    $oldGoods = (float) $goodsReceipt->lines->sum('line_total');
    $oldTotal = $bill ? (float) $bill->grand_total : $oldGoods + (float) ($goodsReceipt->extra_charges ?? 0);
    $discount = $bill ? (float) $bill->discount_total : 0.0;
    $tax = $bill ? (float) $bill->tax_total : 0.0;
    $paid = $bill ? (float) $bill->amount_paid : 0.0;
@endphp
<form method="POST" action="{{ url('/goods-receipts/' . $goodsReceipt->id . '/revise') }}" data-grn-edit-form
      data-discount="{{ $discount }}" data-tax="{{ $tax }}" data-old-total="{{ $oldTotal }}">
    @csrf
    <input type="hidden" name="revision_no" value="{{ (int) $goodsReceipt->revision_no }}">

    <div class="alert alert-info small">
        <strong>Editing {{ $goodsReceipt->grn_no }}{{ $bill ? ' and its invoice ' . $bill->bill_no : '' }}.</strong>
        Change a quantity, a rate or a date, then press <strong>Save changes</strong>. The numbers stay the same.
        The supplier's account and the accounts are corrected on the invoice's own date; the stock today.
        Put <strong>0</strong> to take a line off.
        <span class="d-block text-muted">Qty, rate ya date badlein aur "Save changes" dabayein. Line hatani ho to 0 likh dein.</span>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Details</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-sm-4">
                    <div class="form-label mb-1">Supplier</div>
                    <div class="form-control-plaintext fw-medium">{{ $goodsReceipt->supplier?->name }}</div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label mb-1" for="grn-edit-receipt-date">Receipt date</label>
                    <input type="date" id="grn-edit-receipt-date" name="receipt_date" class="form-control" required
                           value="{{ old('receipt_date', $goodsReceipt->receipt_date?->toDateString()) }}">
                </div>
                @if($bill)
                    <div class="col-sm-4">
                        <label class="form-label mb-1" for="grn-edit-bill-date">Invoice date</label>
                        <input type="date" id="grn-edit-bill-date" name="bill_date" class="form-control" required
                               value="{{ old('bill_date', $bill->bill_date?->toDateString()) }}">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label mb-1" for="grn-edit-invoice-no">Supplier invoice no.</label>
                        <input type="text" id="grn-edit-invoice-no" name="supplier_invoice_no" class="form-control" maxlength="100"
                               value="{{ old('supplier_invoice_no', $bill->supplier_invoice_no) }}">
                    </div>
                @endif
                <div class="col-sm-4">
                    <label class="form-label mb-1" for="grn-edit-charges">Extra charges</label>
                    <input type="number" id="grn-edit-charges" name="extra_charges" class="form-control" step="0.01" min="0" data-grn-charges
                           value="{{ old('extra_charges', $fmt($goodsReceipt->extra_charges ?? 0, 2)) }}">
                </div>
                <div class="col-sm-4">
                    <label class="form-label mb-1" for="grn-edit-charges-note">Extra charges note</label>
                    <input type="text" id="grn-edit-charges-note" name="extra_charges_note" class="form-control" maxlength="255"
                           value="{{ old('extra_charges_note', $goodsReceipt->extra_charges_note) }}">
                </div>
                <div class="col-12">
                    <label class="form-label mb-1" for="grn-edit-notes">Notes</label>
                    <textarea id="grn-edit-notes" name="notes" class="form-control" rows="2" maxlength="1000">{{ old('notes', $goodsReceipt->notes) }}</textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Received Lines</strong></div>
        <div class="card-body table-responsive p-0">
            <table class="table align-middle mb-0">
                <caption class="visually-hidden">Goods receipt lines being edited</caption>
                <thead>
                <tr>
                    <th scope="col">Product</th>
                    <th scope="col">Qty</th>
                    <th scope="col">Unit Cost</th>
                    <th scope="col" class="text-end">Line Total</th>
                </tr>
                </thead>
                <tbody>
                @foreach($goodsReceipt->lines as $line)
                    <tr data-grn-line>
                        <td>
                            <code>{{ $line->product?->sku }}</code>
                            <small class="d-block">{{ $line->product?->name }}</small>
                            @if($line->affects_stock === false)<span class="badge bg-light text-dark border">Purchase only — no stock</span>@endif
                            <small class="d-block text-muted">was {{ $fmt($line->quantity_received, 3) }} × {{ $fmt($line->unit_cost, 4) }}</small>
                        </td>
                        <td style="min-width:8rem">
                            <input type="number" name="lines[{{ $line->id }}][quantity]" class="form-control" step="0.001" min="0" required data-grn-qty
                                   aria-label="Quantity of {{ $line->product?->name }}"
                                   value="{{ old('lines.' . $line->id . '.quantity', $fmt($line->quantity_received, 3)) }}">
                            @if($line->product?->unit?->name)<small class="text-muted">{{ $line->product->unit->name }}</small>@endif
                        </td>
                        <td style="min-width:9rem">
                            <input type="number" name="lines[{{ $line->id }}][unit_cost]" class="form-control" step="0.0001" min="0" required data-grn-cost
                                   aria-label="Rate of {{ $line->product?->name }}"
                                   value="{{ old('lines.' . $line->id . '.unit_cost', $fmt($line->unit_cost, 4)) }}">
                        </td>
                        <td class="text-end" data-grn-line-total>{{ number_format((float) $line->line_total, 2) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot class="table-light">
                    <tr><td colspan="3" class="text-end">Goods</td><td class="text-end" data-grn-goods>{{ number_format($oldGoods, 2) }}</td></tr>
                    <tr><td colspan="3" class="text-end">Extra Charges</td><td class="text-end" data-grn-charges-total>{{ number_format((float) ($goodsReceipt->extra_charges ?? 0), 2) }}</td></tr>
                    @if($discount > 0)<tr><td colspan="3" class="text-end">Invoice discount</td><td class="text-end">− {{ number_format($discount, 2) }}</td></tr>@endif
                    @if($tax > 0)<tr><td colspan="3" class="text-end">Invoice tax</td><td class="text-end">{{ number_format($tax, 2) }}</td></tr>@endif
                    <tr class="fw-bold"><td colspan="3" class="text-end">New total</td><td class="text-end" data-grn-new-total>{{ number_format($oldTotal, 2) }}</td></tr>
                    <tr><td colspan="4" class="text-end small text-muted" data-grn-diff>Was {{ number_format($oldTotal, 2) }} — no change yet</td></tr>
                </tfoot>
            </table>
        </div>
    </div>

    @if($paid > 0.005)
        <div class="alert alert-warning small" data-grn-paid-note>
            Rs {{ number_format($paid, 2) }} is already paid on this invoice. If the new total is lower than that, the extra comes off
            this invoice and stays with the supplier as <strong>"Not on any invoice"</strong> — it is not put on another invoice by itself.
        </div>
    @endif

    <div class="d-flex gap-2 mb-3">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="{{ url('/goods-receipts/' . $goodsReceipt->id) }}" class="btn btn-light">Cancel</a>
    </div>
</form>

<script>
    (function () {
        var form = document.querySelector('[data-grn-edit-form]');
        if (!form) { return; }
        var money = function (n) { return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
        var num = function (el) { var v = parseFloat(el && el.value); return isNaN(v) ? 0 : v; };
        var oldTotal = parseFloat(form.dataset.oldTotal) || 0;
        function update() {
            var goods = 0;
            form.querySelectorAll('[data-grn-line]').forEach(function (row) {
                var t = num(row.querySelector('[data-grn-qty]')) * num(row.querySelector('[data-grn-cost]'));
                goods += t;
                row.querySelector('[data-grn-line-total]').textContent = money(t);
            });
            var charges = num(form.querySelector('[data-grn-charges]'));
            var total = goods + charges - (parseFloat(form.dataset.discount) || 0) + (parseFloat(form.dataset.tax) || 0);
            var diff = total - oldTotal;
            form.querySelector('[data-grn-goods]').textContent = money(goods);
            form.querySelector('[data-grn-charges-total]').textContent = money(charges);
            form.querySelector('[data-grn-new-total]').textContent = money(total);
            form.querySelector('[data-grn-diff]').textContent = 'Was ' + money(oldTotal)
                + (Math.abs(diff) < 0.005 ? ' — no change yet' : ' — ' + (diff > 0 ? '+' : '−') + money(Math.abs(diff)));
        }
        form.addEventListener('input', update);
        update();
    })();
</script>
