@extends('layouts.app')

@section('title', 'Create Sales Return')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Create Sales Return</h1>
        <p class="fw-medium text-muted mb-0">Search a paid sale, review its details, then return items — stock comes back and refunds post to the ledger.</p>
    </div>
    <a href="{{ url('/sales-returns') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

{{-- Step 1: find sale — searchable, branch-scoped --}}
<div class="card mb-3 {{ $salesOrder ? '' : 'border-primary-subtle' }}">
    <div class="card-header"><strong>Find Sale</strong> <span class="text-muted small ms-2">search by sale no, customer name, or phone — only your branches' paid sales appear</span></div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-7">
                <label for="sale-picker" class="form-label required">Sale</label>
                <select id="sale-picker" class="form-select">
                    @if($salesOrder)
                        <option value="{{ $salesOrder->id }}" selected>
                            {{ $salesOrder->sale_no }} — {{ $salesOrder->branch?->name }} — {{ number_format($salesOrder->grand_total, 2) }}
                        </option>
                    @endif
                </select>
                <div class="form-text">Start typing e.g. <code>SO-2026…</code>, a customer name, or a phone number.</div>
            </div>
        </div>
    </div>
</div>

@if($salesOrder)
@php
    $paymentSummary = $salesOrder->payments->map(fn ($p) => ($p->method?->name ?? 'Payment') . ' ' . number_format($p->amount, 2))->implode(' · ');
@endphp

{{-- Step 2: full order information --}}
<div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><strong>Sale <code>{{ $salesOrder->sale_no }}</code></strong>
            @if($salesOrder->status === 'partially_returned')
                <span class="badge bg-warning text-dark ms-1">Partially Returned</span>
            @else
                <span class="badge bg-success ms-1">Paid</span>
            @endif
        </span>
        <span class="small text-muted">{{ app(\App\Support\TenantClock::class)->formatSale($salesOrder, 'Y-m-d H:i') }}</span>
    </div>
    <div class="card-body row g-3 small">
        <div class="col-6 col-md-2"><div class="text-muted">Branch</div><div class="fw-semibold">{{ $salesOrder->branch?->name }}</div></div>
        <div class="col-6 col-md-2"><div class="text-muted">Order Type</div><div class="fw-semibold">{{ ucwords(str_replace('_', ' ', $salesOrder->order_type)) }}</div></div>
        <div class="col-6 col-md-2"><div class="text-muted">Terminal</div><div class="fw-semibold">{{ $salesOrder->terminal?->name ?? '—' }}</div></div>
        <div class="col-6 col-md-2"><div class="text-muted">Waiter</div><div class="fw-semibold">{{ $salesOrder->restaurantWaiter?->name ?? '—' }}</div></div>
        <div class="col-6 col-md-2"><div class="text-muted">Table</div><div class="fw-semibold">{{ $salesOrder->restaurantTable?->table_no ?? '—' }}</div></div>
        <div class="col-6 col-md-2"><div class="text-muted">Cashier</div><div class="fw-semibold">{{ $salesOrder->createdBy?->name ?? '—' }}</div></div>
        <div class="col-6 col-md-3"><div class="text-muted">Customer</div><div class="fw-semibold">{{ $salesOrder->customer?->name ?? $salesOrder->customer_name ?? 'Walk-in' }}{{ $salesOrder->customer_phone ? ' · ' . $salesOrder->customer_phone : '' }}</div></div>
        <div class="col-6 col-md-3"><div class="text-muted">Payments</div><div class="fw-semibold">{{ $paymentSummary ?: '—' }}</div></div>
        <div class="col-6 col-md-2"><div class="text-muted">Subtotal</div><div class="fw-semibold">{{ number_format($salesOrder->subtotal, 2) }}</div></div>
        <div class="col-6 col-md-2"><div class="text-muted">Discount</div><div class="fw-semibold">-{{ number_format($salesOrder->discount_amount, 2) }}</div></div>
        <div class="col-6 col-md-2"><div class="text-muted">Tax</div><div class="fw-semibold">+{{ number_format($salesOrder->tax_amount, 2) }}</div></div>
        @if((float) $salesOrder->service_charge_amount > 0)
            <div class="col-6 col-md-2"><div class="text-muted">Service Charge</div><div class="fw-semibold">+{{ number_format($salesOrder->service_charge_amount, 2) }}</div></div>
        @endif
        @if((float) $salesOrder->delivery_charge_amount > 0)
            <div class="col-6 col-md-2"><div class="text-muted">Delivery Charge</div><div class="fw-semibold">+{{ number_format($salesOrder->delivery_charge_amount, 2) }}</div></div>
        @endif
        @if((float) $salesOrder->tip_amount > 0)
            <div class="col-6 col-md-2"><div class="text-muted">Tip</div><div class="fw-semibold">+{{ number_format($salesOrder->tip_amount, 2) }}</div></div>
        @endif
        <div class="col-6 col-md-2"><div class="text-muted">Grand Total</div><div class="fw-bold">{{ number_format($salesOrder->grand_total, 2) }}</div></div>
        @if((float) $salesOrder->service_charge_amount > 0 || (float) $salesOrder->delivery_charge_amount > 0 || (float) $salesOrder->tip_amount > 0)
            <div class="col-12">
                <div class="alert alert-info py-2 mb-0">Item refunds include proportional line discount and tax. The delivery charge is refunded only when the whole order comes back — a partial return keeps it, because the trip was still made. Service and tip charges are shown for audit but are not automatically refunded.</div>
            </div>
        @endif
    </div>
</div>

{{-- Step 3: select lines to return --}}
<form method="POST" action="{{ url('/sales-returns') }}" novalidate id="return-form">
    @csrf
    <input type="hidden" name="sales_order_id" value="{{ $salesOrder->id }}">

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <strong>Select Lines to Return</strong>
            <span class="small text-muted">Suggested refund updates as you enter quantities.</span>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table table-nowrap align-middle mb-0">
                <caption class="visually-hidden">Sale lines available to return</caption>
                <thead class="table-light">
                <tr>
                    <th scope="col">Product</th>
                    <th scope="col">Variant</th>
                    <th scope="col" class="text-end">Unit Price</th>
                    <th scope="col" class="text-end">Sold Qty</th>
                    <th scope="col" class="text-end">Already Returned</th>
                    <th scope="col" class="text-end">Returnable</th>
                    <th scope="col">Return Qty</th>
                    <th scope="col" class="text-end">Line Refund</th>
                </tr>
                </thead>
                <tbody>
                @foreach($salesOrder->lines as $i => $line)
                    @php
                        $returnable = (float) $line->quantity - (float) $line->returned_quantity;
                        $allocation = $returnAllocations->get($line->id, ['discount' => 0, 'tax' => 0]);
                        $remainingDiscount = max((float) $allocation['discount'] - (float) $line->returnLines->sum('discount_amount'), 0);
                        $remainingTax = max((float) $allocation['tax'] - (float) $line->returnLines->sum('tax_amount'), 0);
                    @endphp
                    <tr class="return-line"
                        data-quantity="{{ (float) $line->quantity }}"
                        data-returned-qty="{{ (float) $line->returned_quantity }}"
                        data-price="{{ (float) $line->unit_price }}"
                        data-discount-per-unit="{{ $returnable > 0 ? $remainingDiscount / $returnable : 0 }}"
                        data-tax-per-unit="{{ $returnable > 0 ? $remainingTax / $returnable : 0 }}">
                        <td>
                            <input type="hidden" name="lines[{{ $i }}][sales_order_line_id]" value="{{ $line->id }}">
                            {{ $line->product_name }}
                        </td>
                        <td>{{ $line->variant_name ?? '—' }}</td>
                        <td class="text-end">{{ number_format($line->unit_price, 2) }}</td>
                        <td class="text-end">{{ number_format($line->quantity, 3) }}</td>
                        <td class="text-end">{{ number_format($line->returned_quantity, 3) }}</td>
                        <td class="text-end">
                            <span class="badge {{ $returnable > 0 ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' }}">{{ number_format($returnable, 3) }}</span>
                        </td>
                        <td>
                            <input type="number" step="0.001" min="0" max="{{ $returnable }}"
                                   name="lines[{{ $i }}][quantity]"
                                   class="form-control form-control-sm text-end return-qty"
                                   style="width:110px"
                                   aria-label="Return quantity for {{ $line->product_name }}"
                                   value="0"
                                   {{ $returnable <= 0 ? 'disabled' : '' }}>
                            @if($returnable <= 0)
                                <small class="text-muted">Fully returned</small>
                            @endif
                        </td>
                        <td class="text-end line-refund">0.00</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr id="delivery-refund-row" class="d-none">
                        <td colspan="7" class="text-end">Plus Delivery Charge (whole order returned)</td>
                        <td class="text-end amt">0.00</td>
                    </tr>
                    <tr>
                        <td colspan="7" class="text-end">Suggested Refund</td>
                        <td class="text-end" id="suggested-refund">0.00</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Refund Details</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label for="refund_method" class="form-label required">Refund Method</label>
                {{-- "— None —" used to be selectable AND the default: a return could be posted with
                     no refund method, which credits Undeposited Funds and records NO cash movement,
                     so money sat in suspense and the drawer never reconciled. Goods coming back
                     always means money going back — the method must be stated. --}}
                <select id="refund_method" name="refund_method" required
                        class="form-select @error('refund_method') is-invalid @enderror">
                    <option value="" disabled @selected(! old('refund_method'))>Select refund method</option>
                    <option value="cash"          @selected(old('refund_method') === 'cash')>Cash</option>
                    <option value="bank_transfer" @selected(old('refund_method') === 'bank_transfer')>Bank Transfer</option>
                    <option value="card"          @selected(old('refund_method') === 'card')>Card</option>
                    <option value="other"         @selected(old('refund_method') === 'other')>Other</option>
                </select>
                @error('refund_method') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="refund_amount" class="form-label">Calculated Refund Amount</label>
                <input id="refund_amount" type="number" step="0.01" min="0"
                       name="refund_amount"
                       class="form-control @error('refund_amount') is-invalid @enderror"
                       value="{{ old('refund_amount', 0) }}" readonly>
                <div class="form-text">Based on selected item quantities, proportional line discount, and line tax.</div>
                @error('refund_amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-12">
                <label for="reason" class="form-label">Reason</label>
                <textarea id="reason" name="reason" rows="2"
                          class="form-control @error('reason') is-invalid @enderror">{{ old('reason') }}</textarea>
                @error('reason') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-primary" type="submit">Post Return</button>
        <a href="{{ url('/sales-returns') }}" class="btn btn-light">Cancel</a>
    </div>
</form>
@endif

@push('scripts')
<script>
(function () {
    var $ = window.jQuery;

    // Sale picker: searchable, branch-scoped; picking reloads with the sale.
    if ($ && $.fn.select2) {
        $('#sale-picker').select2({
            width: '100%', placeholder: 'Search sale no / customer / phone…', minimumInputLength: 1,
            ajax: {
                url: @json(url('/ajax/sales')), dataType: 'json', delay: 200, cache: false,
                data: function (params) { return { q: params.term || '', page: params.page || 1 }; },
                processResults: function (data, params) {
                    params.page = params.page || 1;
                    return { results: data.results || [], pagination: { more: !!(data.pagination && data.pagination.more) } };
                },
            },
        }).on('select2:select', function (e) {
            window.location = @json(url('/sales-returns/create')) + '?sales_order_id=' + e.params.data.id;
        });
    }

    var form = document.getElementById('return-form');
    if (!form) return;

    // Delivery still on the order after any earlier partial returns took their share.
    var outstandingDelivery = {{ (float) $outstandingDelivery }};

    /** Does every customer-facing line end up fully returned once this return posts? */
    function orderCompletelyReturned() {
        var complete = true;
        document.querySelectorAll('tr.return-line').forEach(function (row) {
            var qtyEl = row.querySelector('.return-qty');
            var takingNow = parseFloat(qtyEl && qtyEl.value || 0);
            var accountedFor = parseFloat(row.dataset.returnedQty || 0) + takingNow;
            if (accountedFor + 0.000001 < parseFloat(row.dataset.quantity || 0)) complete = false;
        });
        return complete;
    }

    function recalc() {
        var total = 0;
        document.querySelectorAll('tr.return-line').forEach(function (row) {
            var qtyEl = row.querySelector('.return-qty');
            var qty = parseFloat(qtyEl && qtyEl.value || 0);
            var max = parseFloat(qtyEl && qtyEl.max || 0);
            if (qtyEl) qtyEl.classList.toggle('is-invalid', qty > max);
            var gross = qty * parseFloat(row.dataset.price || 0);
            var discount = qty * parseFloat(row.dataset.discountPerUnit || 0);
            var tax = qty * parseFloat(row.dataset.taxPerUnit || 0);
            var refund = gross - discount + tax;
            row.querySelector('.line-refund').textContent = refund.toFixed(2);
            total += refund;
        });
        // The delivery charge comes back only when nothing of the order is left — mirror the
        // server rule exactly, or the posted refund would not match this preview and the sale
        // would be rejected at submit.
        var deliveryRow = document.getElementById('delivery-refund-row');
        var delivery = orderCompletelyReturned() ? outstandingDelivery : 0;
        if (deliveryRow) {
            deliveryRow.classList.toggle('d-none', delivery <= 0);
            deliveryRow.querySelector('.amt').textContent = delivery.toFixed(2);
        }
        total += delivery;

        document.getElementById('suggested-refund').textContent = total.toFixed(2);
        var refundMethod = document.getElementById('refund_method').value;
        document.getElementById('refund_amount').value = refundMethod ? total.toFixed(2) : '0.00';
        return total;
    }
    document.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('return-qty')) recalc();
    });
    document.getElementById('refund_method').addEventListener('change', recalc);

    form.addEventListener('submit', function (e) {
        if (this.dataset.confirmed) return;
        e.preventDefault();
        var totalQty = 0, over = false;
        document.querySelectorAll('tr.return-line .return-qty').forEach(function (el) {
            var q = parseFloat(el.value || 0);
            totalQty += q;
            if (q > parseFloat(el.max || 0)) over = true;
        });
        if (totalQty <= 0) {
            Swal.fire({ icon: 'warning', title: 'Nothing to return', text: 'Enter a return quantity on at least one line.' });
            return;
        }
        if (over) {
            Swal.fire({ icon: 'error', title: 'Quantity too high', text: 'A return quantity exceeds the returnable amount.' });
            return;
        }
        var f = this;
        var suggested = recalc();
        var refundMethod = document.getElementById('refund_method').value;
        var refund = parseFloat(document.getElementById('refund_amount').value || 0);
        if (refundMethod && Math.abs(refund - suggested) > 0.01) {
            Swal.fire({ icon: 'error', title: 'Refund amount mismatch', text: 'Use the calculated item refund of ' + suggested.toFixed(2) + '.' });
            return;
        }
        Swal.fire({
            title: 'Post this sales return?',
            html: 'Returned stock goes back into the branch.' + (refund > 0 ? '<br>Refund: <strong>' + refund.toFixed(2) + '</strong>' : '<br>No refund recorded.'),
            icon: 'question', showCancelButton: true, confirmButtonText: 'Post Return',
        }).then(function (res) { if (res.isConfirmed) { f.dataset.confirmed = '1'; f.submit(); } });
    });

    recalc();
})();
</script>
@endpush
@endsection
