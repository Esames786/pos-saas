{{-- W4 R3.6 — one SALES RETURN on the Branch Server (Online tenant/sales-returns/show.blade.php). --}}
@extends('edge.finance.layout')

@section('title', 'Return: ' . $salesReturn->return_no)

@section('nav')
    @if($canIndex)
        <a class="navbtn" id="sales-return-list-link" href="{{ url('/edge/local/pos/sales-returns') }}">Sales Returns</a>
    @endif
@endsection

@section('content')
@php $order = $salesReturn->order; @endphp
<div class="page-head">
    <div>
        <h2>Sales Return</h2>
        <p><code>{{ $salesReturn->return_no }}</code> <span class="badge {{ $salesReturn->edge_sync_label['state'] }}" id="sales-return-sync">{{ $salesReturn->edge_sync_label['label'] }}</span></p>
    </div>
    @if($canIndex)
        <a class="btn" href="{{ url('/edge/local/pos/sales-returns') }}">Back</a>
    @endif
</div>

<div class="card">
    <div class="card-h">Return Details</div>
    <div class="card-b">
        <dl class="kv" id="sales-return-details">
            <dt>Return No</dt><dd><code>{{ $salesReturn->return_no }}</code></dd>
            <dt>Original Sale</dt><dd><code>{{ $order?->sale_no }}</code></dd>
            <dt>Branch</dt><dd>{{ $branchName }}</dd>
            <dt>Return Date</dt><dd>{{ $clock->format($salesReturn->return_date, 'Y-m-d H:i', $tz) }}</dd>
            <dt>Subtotal</dt><dd>{{ number_format((float) $salesReturn->subtotal, 2) }}</dd>
            @if((float) $salesReturn->discount_amount > 0)
                <dt>Discount Reversed</dt><dd>-{{ number_format((float) $salesReturn->discount_amount, 2) }}</dd>
            @endif
            @if((float) $salesReturn->tax_amount > 0)
                <dt>Tax</dt><dd>+{{ number_format((float) $salesReturn->tax_amount, 2) }}</dd>
            @endif
            @if((float) $salesReturn->delivery_charge_amount > 0)
                <dt>Delivery Refunded</dt><dd>+{{ number_format((float) $salesReturn->delivery_charge_amount, 2) }}</dd>
            @endif
            <dt>Grand Total</dt><dd><strong>{{ number_format((float) $salesReturn->grand_total, 2) }}</strong></dd>
            @if($salesReturn->refund_method)
                <dt>Refund Method</dt><dd>{{ str_replace('_', ' ', ucfirst($salesReturn->refund_method)) }}</dd>
                <dt>Refund Amount</dt><dd>{{ number_format((float) $salesReturn->refund_amount, 2) }}</dd>
            @endif
            <dt>Status</dt><dd><span class="badge posted">{{ $salesReturn->status === 'cloud_mirror' ? 'Posted' : ucfirst($salesReturn->status) }}</span></dd>
            <dt>Posted By</dt><dd>{{ $salesReturn->createdBy?->name ?? '—' }}</dd>
            @if($salesReturn->reason)
                <dt>Reason</dt><dd>{{ $salesReturn->reason }}</dd>
            @endif
        </dl>
    </div>
</div>

@if($order && ((float) $order->service_charge_amount > 0 || (float) $order->delivery_charge_amount > 0 || (float) $order->tip_amount > 0))
    <div class="note">
        <strong>Original order charges:</strong>
        @if((float) $order->service_charge_amount > 0) Service {{ number_format((float) $order->service_charge_amount, 2) }}. @endif
        @if((float) $order->delivery_charge_amount > 0) Delivery {{ number_format((float) $order->delivery_charge_amount, 2) }}. @endif
        @if((float) $order->tip_amount > 0) Tip {{ number_format((float) $order->tip_amount, 2) }}. @endif
        These order-level charges remain visible for audit and were not included in this item return.
    </div>
@endif

<div class="card">
    <div class="card-h">Return Lines</div>
    <div class="card-b table-wrap">
        <table id="sales-return-lines">
            <thead><tr><th>Product</th><th>Variant</th><th class="num">Return Qty</th><th class="num">Unit Price</th><th class="num">Discount</th><th class="num">Tax</th><th class="num">Refund Total</th></tr></thead>
            <tbody>
            @forelse($salesReturn->lines as $line)
                <tr>
                    <td>{{ $line->product?->name }}</td>
                    <td>{{ $line->variant?->name ?? '—' }}</td>
                    <td class="num">{{ number_format((float) $line->quantity, 3) }}</td>
                    <td class="num">{{ number_format((float) $line->unit_price, 2) }}</td>
                    <td class="num">{{ number_format((float) $line->discount_amount, 2) }}</td>
                    <td class="num">{{ number_format((float) $line->tax_amount, 2) }}</td>
                    <td class="num"><strong>{{ number_format((float) $line->line_total, 2) }}</strong></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">No line detail is held on this branch server for this return.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
