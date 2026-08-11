@extends('layouts.app')

@section('title', 'Return: ' . $salesReturn->return_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Sales Return</h1>
        <p class="fw-medium"><code>{{ $salesReturn->return_no }}</code></p>
    </div>
    <a href="{{ url('/sales-returns') }}" class="btn btn-light">Back</a>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Return Details</strong></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Return No</dt>
            <dd class="col-sm-9"><code>{{ $salesReturn->return_no }}</code></dd>

            <dt class="col-sm-3">Original Sale</dt>
            <dd class="col-sm-9">
                @can('tenant.sales-orders.show')
                    <a href="{{ url('/sales-orders/' . $salesReturn->order?->id) }}">
                        <code>{{ $salesReturn->order?->sale_no }}</code>
                    </a>
                @else
                    <code>{{ $salesReturn->order?->sale_no }}</code>
                @endcan
            </dd>

            <dt class="col-sm-3">Branch</dt>
            <dd class="col-sm-9">{{ $salesReturn->branch?->name }}</dd>

            <dt class="col-sm-3">Return Date</dt>
            <dd class="col-sm-9">{{ app(\App\Support\TenantClock::class)->format(
                $salesReturn->return_date,
                'Y-m-d H:i',
                app(\App\Support\TenantClock::class)->displayTimezone(null, $salesReturn->branch)
            ) }}</dd>

            <dt class="col-sm-3">Subtotal</dt>
            <dd class="col-sm-9">{{ number_format($salesReturn->subtotal, 2) }}</dd>

            @if($salesReturn->discount_amount > 0)
                <dt class="col-sm-3">Discount Reversed</dt>
                <dd class="col-sm-9">-{{ number_format($salesReturn->discount_amount, 2) }}</dd>
            @endif

            @if($salesReturn->tax_amount > 0)
                <dt class="col-sm-3">Tax</dt>
                <dd class="col-sm-9">+{{ number_format($salesReturn->tax_amount, 2) }}</dd>
            @endif

            <dt class="col-sm-3">Grand Total</dt>
            <dd class="col-sm-9"><strong>{{ number_format($salesReturn->grand_total, 2) }}</strong></dd>

            @if($salesReturn->refund_method)
                <dt class="col-sm-3">Refund Method</dt>
                <dd class="col-sm-9">{{ str_replace('_', ' ', ucfirst($salesReturn->refund_method)) }}</dd>

                <dt class="col-sm-3">Refund Amount</dt>
                <dd class="col-sm-9">{{ number_format($salesReturn->refund_amount, 2) }}</dd>
            @endif

            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9">
                <span class="badge bg-{{ $salesReturn->status === 'posted' ? 'success' : 'secondary' }}">
                    {{ ucfirst($salesReturn->status) }}
                </span>
            </dd>

            <dt class="col-sm-3">Posted By</dt>
            <dd class="col-sm-9">{{ $salesReturn->createdBy?->name ?? '—' }}</dd>

            @if($salesReturn->reason)
                <dt class="col-sm-3">Reason</dt>
                <dd class="col-sm-9">{{ $salesReturn->reason }}</dd>
            @endif
        </dl>
    </div>
</div>

@if($salesReturn->order && ((float) $salesReturn->order->service_charge_amount > 0 || (float) $salesReturn->order->delivery_charge_amount > 0 || (float) $salesReturn->order->tip_amount > 0))
<div class="alert alert-info">
    <strong>Original order charges:</strong>
    @if((float) $salesReturn->order->service_charge_amount > 0) Service {{ number_format($salesReturn->order->service_charge_amount, 2) }}. @endif
    @if((float) $salesReturn->order->delivery_charge_amount > 0) Delivery {{ number_format($salesReturn->order->delivery_charge_amount, 2) }}. @endif
    @if((float) $salesReturn->order->tip_amount > 0) Tip {{ number_format($salesReturn->order->tip_amount, 2) }}. @endif
    <span class="d-block small mt-1">These order-level charges remain visible for audit and were not included in this item return.</span>
</div>
@endif

<div class="card">
    <div class="card-header"><strong>Return Lines</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Returned product lines</caption>
            <thead>
            <tr>
                <th scope="col">Product</th>
                <th scope="col">Variant</th>
                <th scope="col">Return Qty</th>
                <th scope="col">Unit Price</th>
                <th scope="col">Discount</th>
                <th scope="col">Tax</th>
                <th scope="col">Refund Total</th>
            </tr>
            </thead>
            <tbody>
            @foreach($salesReturn->lines as $line)
                <tr>
                    <td>{{ $line->product?->name }}</td>
                    <td>{{ $line->variant?->name ?? '—' }}</td>
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
@endsection
