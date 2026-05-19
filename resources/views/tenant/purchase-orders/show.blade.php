@extends('layouts.app')

@section('title', 'PO: ' . $purchaseOrder->po_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Purchase Order</h1>
        <p class="fw-medium"><code>{{ $purchaseOrder->po_no }}</code></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ url('/purchase-orders') }}" class="btn btn-light">Back</a>
        @if($purchaseOrder->status === 'approved')
            @can('tenant.goods-receipts.create')
                <a href="{{ url('/goods-receipts/create?purchase_order_id=' . $purchaseOrder->id) }}"
                   class="btn btn-primary">Create GRN</a>
            @endcan
        @endif
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-header"><strong>Details</strong></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Supplier</dt>
            <dd class="col-sm-9">{{ $purchaseOrder->supplier?->name }}</dd>

            <dt class="col-sm-3">Branch</dt>
            <dd class="col-sm-9">{{ $purchaseOrder->branch?->name }}</dd>

            <dt class="col-sm-3">Order Date</dt>
            <dd class="col-sm-9">{{ $purchaseOrder->order_date?->format('Y-m-d') }}</dd>

            <dt class="col-sm-3">Expected Date</dt>
            <dd class="col-sm-9">{{ $purchaseOrder->expected_delivery_date?->format('Y-m-d') ?? '—' }}</dd>

            <dt class="col-sm-3">Total Amount</dt>
            <dd class="col-sm-9"><strong>{{ number_format($purchaseOrder->total_amount, 2) }}</strong></dd>

            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9">
                <span class="badge bg-{{ match($purchaseOrder->status) {
                    'approved' => 'success', 'cancelled' => 'danger', 'received' => 'primary', default => 'secondary'
                } }}">{{ ucfirst($purchaseOrder->status) }}</span>
            </dd>

            <dt class="col-sm-3">Created By</dt>
            <dd class="col-sm-9">{{ $purchaseOrder->createdBy?->name ?? '—' }}</dd>

            <dt class="col-sm-3">Approved By</dt>
            <dd class="col-sm-9">{{ $purchaseOrder->approvedBy?->name ?? '—' }}</dd>

            @if($purchaseOrder->approved_at)
                <dt class="col-sm-3">Approved At</dt>
                <dd class="col-sm-9">{{ $purchaseOrder->approved_at->format('Y-m-d H:i') }}</dd>
            @endif

            @if($purchaseOrder->notes)
                <dt class="col-sm-3">Notes</dt>
                <dd class="col-sm-9">{{ $purchaseOrder->notes }}</dd>
            @endif
        </dl>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Product Lines</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Purchase order product lines</caption>
            <thead>
            <tr>
                <th scope="col">Product</th>
                <th scope="col">Variant</th>
                <th scope="col">Qty Ordered</th>
                <th scope="col">Unit Cost</th>
                <th scope="col">Discount</th>
                <th scope="col">Tax</th>
                <th scope="col">Line Total</th>
            </tr>
            </thead>
            <tbody>
            @foreach($purchaseOrder->lines as $line)
                <tr>
                    <td>
                        <code>{{ $line->product?->sku }}</code>
                        <small class="d-block">{{ $line->product?->name }}</small>
                    </td>
                    <td>{{ $line->variant?->name ?? '—' }}</td>
                    <td>{{ number_format($line->quantity_ordered, 3) }}</td>
                    <td>{{ number_format($line->unit_cost, 4) }}</td>
                    <td>{{ number_format($line->discount_amount, 2) }}</td>
                    <td>{{ number_format($line->tax_amount, 2) }}</td>
                    <td>{{ number_format($line->line_total, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

@if(in_array($purchaseOrder->status, ['draft', 'approved']))
<div class="d-flex gap-2">
    @if($purchaseOrder->status === 'draft')
        @can('tenant.purchase-orders.approve')
            <form method="POST" action="{{ url('/purchase-orders/' . $purchaseOrder->id . '/approve') }}">
                @csrf
                <button class="btn btn-success" type="submit">Approve</button>
            </form>
        @endcan
    @endif

    @can('tenant.purchase-orders.cancel')
        <form method="POST" action="{{ url('/purchase-orders/' . $purchaseOrder->id . '/cancel') }}"
              onsubmit="return confirm('Cancel this purchase order?')">
            @csrf
            <button class="btn btn-outline-danger" type="submit">Cancel</button>
        </form>
    @endcan
</div>
@endif
@endsection
