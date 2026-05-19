@extends('layouts.app')

@section('title', 'Sale: ' . $salesOrder->sale_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Sale</h1>
        <p class="fw-medium"><code>{{ $salesOrder->sale_no }}</code></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @if($salesOrder->status === 'held')
            @can('tenant.sales-orders.split-bill')
                <a href="{{ url('/sales-orders/' . $salesOrder->id . '/split-bill') }}" class="btn btn-warning">Split Bill</a>
            @endcan
        @endif
        @if($salesOrder->restaurant_table_session_id)
            @can('tenant.restaurant.table-sessions.bill-preview')
                <a href="{{ url('/restaurant/table-sessions/' . $salesOrder->restaurant_table_session_id . '/bill-preview') }}" class="btn btn-dark">Table Bill</a>
            @endcan
        @endif
        @if(in_array($salesOrder->status, ['paid', 'partially_returned']))
            @can('tenant.sales-returns.create')
                <a href="{{ url('/sales-returns/create?sales_order_id=' . $salesOrder->id) }}"
                   class="btn btn-warning">Return Items</a>
            @endcan
        @endif
        @if(!in_array($salesOrder->status, ['paid', 'cancelled', 'returned']))
            @can('tenant.sales-orders.cancel')
                <form method="POST" action="{{ url('/sales-orders/' . $salesOrder->id . '/cancel') }}"
                      onsubmit="return confirm('Cancel this sale?')">
                    @csrf
                    <button class="btn btn-danger" type="submit">Cancel Sale</button>
                </form>
            @endcan
        @endif
        @can('tenant.printing.jobs.queue-receipt')
            <form method="POST" action="{{ url('/printing/jobs/receipt/' . $salesOrder->id) }}" target="_blank">
                @csrf
                <button type="submit" class="btn btn-outline-secondary">
                    <i class="ti ti-printer me-1"></i> Print Receipt
                </button>
            </form>
        @endcan
        @can('tenant.printing.jobs.queue-kot')
            <form method="POST" action="{{ url('/printing/jobs/kot/' . $salesOrder->id) }}" target="_blank">
                @csrf
                <button type="submit" class="btn btn-outline-secondary">
                    <i class="ti ti-chef-hat me-1"></i> Send KOT
                </button>
            </form>
        @endcan
        <a href="{{ url('/sales-orders') }}" class="btn btn-light">Back</a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger" role="alert" aria-live="polite">{{ $errors->first() }}</div>
@endif

<div class="row g-3">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><strong>Sale Details</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Sale No</dt>
                    <dd class="col-sm-8"><code>{{ $salesOrder->sale_no }}</code></dd>

                    <dt class="col-sm-4">Branch</dt>
                    <dd class="col-sm-8">{{ $salesOrder->branch?->name }}</dd>

                    @if($salesOrder->terminal)
                        <dt class="col-sm-4">Terminal</dt>
                        <dd class="col-sm-8">{{ $salesOrder->terminal->name }}</dd>
                    @endif

                    @if($salesOrder->shift)
                        <dt class="col-sm-4">Shift</dt>
                        <dd class="col-sm-8">{{ $salesOrder->shift->id }}</dd>
                    @endif

                    <dt class="col-sm-4">Sale Date</dt>
                    <dd class="col-sm-8">{{ $salesOrder->sale_date?->format('Y-m-d H:i') }}</dd>

                    <dt class="col-sm-4">Order Type</dt>
                    <dd class="col-sm-8">{{ str_replace('_', ' ', ucfirst($salesOrder->order_type)) }}</dd>

                    <dt class="col-sm-4">Source</dt>
                    <dd class="col-sm-8">{{ strtoupper($salesOrder->order_source) }}</dd>

                    <dt class="col-sm-4">Customer</dt>
                    <dd class="col-sm-8">
                        {{ $salesOrder->customer?->name ?? $salesOrder->customer_name ?? 'Walk-in' }}
                        @if($salesOrder->customer_phone)
                            <small class="text-muted">{{ $salesOrder->customer_phone }}</small>
                        @endif
                    </dd>

                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        <span class="badge bg-{{ match($salesOrder->status) {
                            'paid' => 'success',
                            'draft', 'held' => 'secondary',
                            'cancelled' => 'danger',
                            'partially_returned' => 'warning',
                            'returned' => 'info',
                            default => 'secondary'
                        } }} {{ $salesOrder->status === 'partially_returned' ? 'text-dark' : '' }}">
                            {{ str_replace('_', ' ', ucfirst($salesOrder->status)) }}
                        </span>
                    </dd>

                    <dt class="col-sm-4">Posted By</dt>
                    <dd class="col-sm-8">{{ $salesOrder->createdBy?->name ?? '—' }}</dd>

                    @if($salesOrder->notes)
                        <dt class="col-sm-4">Notes</dt>
                        <dd class="col-sm-8">{{ $salesOrder->notes }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><strong>Financials</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Subtotal</dt>
                    <dd class="col-sm-7">{{ number_format($salesOrder->subtotal, 2) }}</dd>

                    @if($salesOrder->discount_amount > 0)
                        <dt class="col-sm-5">Discount</dt>
                        <dd class="col-sm-7">
                            {{ number_format($salesOrder->discount_amount, 2) }}
                            @if($salesOrder->discount_type !== 'none')
                                <small class="text-muted">({{ $salesOrder->discount_type === 'percent' ? $salesOrder->discount_value . '%' : 'fixed' }})</small>
                            @endif
                        </dd>
                    @endif

                    @if($salesOrder->tax_amount > 0)
                        <dt class="col-sm-5">Tax</dt>
                        <dd class="col-sm-7">{{ number_format($salesOrder->tax_amount, 2) }}</dd>
                    @endif

                    <dt class="col-sm-5 fw-bold">Grand Total</dt>
                    <dd class="col-sm-7 fw-bold text-primary">{{ number_format($salesOrder->grand_total, 2) }}</dd>

                    <dt class="col-sm-5">Paid Amount</dt>
                    <dd class="col-sm-7 text-success">{{ number_format($salesOrder->paid_amount, 2) }}</dd>

                    @if($salesOrder->change_amount > 0)
                        <dt class="col-sm-5">Change Given</dt>
                        <dd class="col-sm-7">{{ number_format($salesOrder->change_amount, 2) }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>
</div>

{{-- Sale Lines --}}
<div class="card mt-3">
    <div class="card-header"><strong>Sale Lines</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Sale product lines</caption>
            <thead>
            <tr>
                <th scope="col">Product</th>
                <th scope="col">Variant</th>
                <th scope="col">Qty</th>
                <th scope="col">Unit Price</th>
                <th scope="col">Discount</th>
                <th scope="col">Tax</th>
                <th scope="col">Line Total</th>
            </tr>
            </thead>
            <tbody>
            @foreach($salesOrder->lines as $line)
                <tr>
                    <td>
                        <code>{{ $line->product?->sku }}</code>
                        <small class="d-block">{{ $line->product_name }}</small>
                    </td>
                    <td>{{ $line->variant_name ?? '—' }}</td>
                    <td>{{ number_format($line->quantity, 3) }}</td>
                    <td>{{ number_format($line->unit_price, 2) }}</td>
                    <td>{{ number_format($line->discount_amount, 2) }}</td>
                    <td>{{ number_format($line->tax_amount, 2) }}</td>
                    <td><strong>{{ number_format($line->line_total, 2) }}</strong></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Payments --}}
<div class="card mt-3">
    <div class="card-header"><strong>Payments</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Payments for this sale</caption>
            <thead>
            <tr>
                <th scope="col">Method</th>
                <th scope="col">Amount</th>
                <th scope="col">Tendered</th>
                <th scope="col">Change</th>
                <th scope="col">Reference</th>
            </tr>
            </thead>
            <tbody>
            @forelse($salesOrder->payments as $payment)
                <tr>
                    <td>{{ $payment->method?->name ?? '—' }}</td>
                    <td><strong>{{ number_format($payment->amount, 2) }}</strong></td>
                    <td>{{ $payment->tendered_amount ? number_format($payment->tendered_amount, 2) : '—' }}</td>
                    <td>{{ $payment->change_amount > 0 ? number_format($payment->change_amount, 2) : '—' }}</td>
                    <td>
                        {{ $payment->transaction_ref ?? $payment->cheque_no ?? $payment->card_last_four ?? '—' }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-3">No payments recorded.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
