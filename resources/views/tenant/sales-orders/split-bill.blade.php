@extends('layouts.app')

@section('title', 'Split Bill')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Split Bill</h1>
        <p class="fw-medium">
            {{ $salesOrder->sale_no }}
            @if($salesOrder->restaurantTable)
                · Table {{ $salesOrder->restaurantTable?->table_no }}
            @endif
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @if($salesOrder->restaurant_table_session_id)
            <a href="{{ url('/restaurant/table-sessions/' . $salesOrder->restaurant_table_session_id . '/bill-preview') }}" class="btn btn-dark">
                Table Bill
            </a>
        @endif
        <a href="{{ url('/held-sales') }}" class="btn btn-light">Held Sales</a>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <form method="POST" action="{{ url('/sales-orders/' . $salesOrder->id . '/split-bill') }}">
            @csrf

            <div class="card mb-3">
                <div class="card-body table-responsive">
                    <table class="table table-nowrap align-middle">
                        <thead>
                        <tr>
                            <th>Product</th>
                            <th>Available Qty</th>
                            <th>Split Qty</th>
                            <th>Unit Price</th>
                            <th>Line Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($salesOrder->lines as $index => $line)
                            <tr>
                                <td>
                                    {{ $line->product_name }}
                                    @if($line->variant_name)
                                        <small class="d-block text-muted">{{ $line->variant_name }}</small>
                                    @endif
                                    <input type="hidden" name="lines[{{ $index }}][sales_order_line_id]" value="{{ $line->id }}">
                                </td>
                                <td>{{ number_format($line->quantity, 3) }}</td>
                                <td>
                                    <label for="split_qty_{{ $index }}" class="visually-hidden">Split quantity for {{ $line->product_name }}</label>
                                    <input
                                        id="split_qty_{{ $index }}"
                                        type="number"
                                        step="0.001"
                                        min="0"
                                        max="{{ $line->quantity }}"
                                        name="lines[{{ $index }}][quantity]"
                                        class="form-control split-qty"
                                        style="width:100px"
                                        data-unit-price="{{ $line->unit_price }}"
                                        data-available="{{ $line->quantity }}"
                                        data-line-total="{{ $line->line_total }}"
                                        placeholder="0"
                                    >
                                </td>
                                <td>{{ number_format($line->unit_price, 2) }}</td>
                                <td>{{ number_format($line->line_total, 2) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body row g-3">
                    <div class="col-md-4">
                        <label for="payment_method_id" class="form-label">Payment Method <span class="text-danger">*</span></label>
                        <select id="payment_method_id" name="payment_method_id" class="form-select" required>
                            @foreach($paymentMethods as $method)
                                <option value="{{ $method->id }}">{{ $method->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="tendered_amount" class="form-label">Tendered Amount</label>
                        <input id="tendered_amount" type="number" step="0.01" min="0" name="tendered_amount" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label for="transaction_ref" class="form-label">Reference</label>
                        <input id="transaction_ref" name="transaction_ref" class="form-control" placeholder="Card / wallet ref">
                    </div>
                    <div class="col-12">
                        <label for="notes" class="form-label">Notes</label>
                        <input id="notes" name="notes" class="form-control" placeholder="Split payment notes">
                    </div>
                </div>
            </div>

            <button class="btn btn-primary btn-lg" type="submit" onclick="return confirm('Post selected items as a paid split bill?')">
                Pay Selected Items
            </button>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="card sticky-top" style="top:1rem">
            <div class="card-body">
                <h2 class="h5 mb-3">Split Summary</h2>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Original Held Total</span>
                    <strong>{{ number_format($salesOrder->grand_total, 2) }}</strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Selected Split Total</span>
                    <strong id="split-total">0.00</strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">Est. Remaining</span>
                    <strong id="remaining-total">{{ number_format($salesOrder->grand_total, 2) }}</strong>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const originalTotal = Number(@json((float) $salesOrder->grand_total));
    const tendered = document.getElementById('tendered_amount');

    function money(v) {
        return Number(v || 0).toFixed(2);
    }

    function recalc() {
        let splitTotal = 0;

        document.querySelectorAll('.split-qty').forEach(function (input) {
            const qty       = Number(input.value || 0);
            const available = Number(input.dataset.available || 0);
            const lineTotal = Number(input.dataset.lineTotal || 0);
            if (qty > 0 && available > 0) {
                splitTotal += lineTotal * (qty / available);
            }
        });

        document.getElementById('split-total').textContent     = money(splitTotal);
        document.getElementById('remaining-total').textContent = money(Math.max(originalTotal - splitTotal, 0));

        if (!tendered.dataset.manual) {
            tendered.value = money(splitTotal);
        }
    }

    document.querySelectorAll('.split-qty').forEach(function (input) {
        input.addEventListener('input', recalc);
    });

    tendered.addEventListener('input', function () {
        this.dataset.manual = '1';
    });

    recalc();
});
</script>
@endsection
