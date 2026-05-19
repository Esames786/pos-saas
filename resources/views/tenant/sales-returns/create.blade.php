@extends('layouts.app')

@section('title', 'Create Sales Return')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Create Sales Return</h1>
        <p class="fw-medium">Select a paid sale to return items from.</p>
    </div>
    <a href="{{ url('/sales-returns') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert" aria-live="polite">{{ $errors->first() }}</div>
@endif

{{-- Step 1: find the sale --}}
@if(!$salesOrder)
<div class="card mb-3">
    <div class="card-header"><strong>Find Sale</strong></div>
    <div class="card-body">
        <form method="GET" action="{{ url('/sales-returns/create') }}" class="row g-3 align-items-end">
            <div class="col-md-6">
                <label for="sales_order_id" class="form-label required">Sales Order ID</label>
                <input id="sales_order_id" name="sales_order_id" type="number" min="1" required
                       class="form-control" placeholder="Enter numeric Sale ID">
                <div class="form-text">Find the sale ID from the <a href="{{ url('/sales-orders') }}">Sales Orders</a> list.</div>
            </div>
            <div class="col-md-3">
                <button class="btn btn-dark" type="submit">Load Sale</button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- Step 2: select lines to return --}}
@if($salesOrder)
<form method="POST" action="{{ url('/sales-returns') }}" novalidate>
    @csrf
    <input type="hidden" name="sales_order_id" value="{{ $salesOrder->id }}">

    <div class="card mb-3">
        <div class="card-header"><strong>Sale: <code>{{ $salesOrder->sale_no }}</code></strong></div>
        <div class="card-body">
            <p class="mb-1">Branch: <strong>{{ $salesOrder->branch?->name }}</strong></p>
            <p class="mb-0">Grand Total: <strong>{{ number_format($salesOrder->grand_total, 2) }}</strong></p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Select Lines to Return</strong></div>
        <div class="card-body table-responsive p-0">
            <table class="table table-nowrap align-middle mb-0">
                <caption class="visually-hidden">Sale lines available to return</caption>
                <thead>
                <tr>
                    <th scope="col">Product</th>
                    <th scope="col">Variant</th>
                    <th scope="col">Sold Qty</th>
                    <th scope="col">Already Returned</th>
                    <th scope="col">Return Qty</th>
                </tr>
                </thead>
                <tbody>
                @foreach($salesOrder->lines as $i => $line)
                    @php $returnable = (float)$line->quantity - (float)$line->returned_quantity; @endphp
                    <tr>
                        <td>
                            <input type="hidden" name="lines[{{ $i }}][sales_order_line_id]" value="{{ $line->id }}">
                            {{ $line->product_name }}
                        </td>
                        <td>{{ $line->variant_name ?? '—' }}</td>
                        <td>{{ number_format($line->quantity, 3) }}</td>
                        <td>{{ number_format($line->returned_quantity, 3) }}</td>
                        <td>
                            <input type="number" step="0.001" min="0" max="{{ $returnable }}"
                                   name="lines[{{ $i }}][quantity]"
                                   class="form-control form-control-sm"
                                   style="width:100px"
                                   aria-label="Return quantity for {{ $line->product_name }}"
                                   value="0"
                                   {{ $returnable <= 0 ? 'disabled' : '' }}>
                            @if($returnable <= 0)
                                <small class="text-muted">Fully returned</small>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Refund Details</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label for="refund_method" class="form-label">Refund Method</label>
                <select id="refund_method" name="refund_method"
                        class="form-select @error('refund_method') is-invalid @enderror">
                    <option value="">— None —</option>
                    <option value="cash"          @selected(old('refund_method') === 'cash')>Cash</option>
                    <option value="bank_transfer" @selected(old('refund_method') === 'bank_transfer')>Bank Transfer</option>
                    <option value="card"          @selected(old('refund_method') === 'card')>Card</option>
                    <option value="other"         @selected(old('refund_method') === 'other')>Other</option>
                </select>
                @error('refund_method') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="refund_amount" class="form-label">Refund Amount</label>
                <input id="refund_amount" type="number" step="0.01" min="0"
                       name="refund_amount"
                       class="form-control @error('refund_amount') is-invalid @enderror"
                       value="{{ old('refund_amount', 0) }}">
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

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit"
                onclick="return confirm('Post this sales return?')">Post Return</button>
        <a href="{{ url('/sales-returns') }}" class="btn btn-light">Cancel</a>
    </div>
</form>
@endif
@endsection
