@extends('layouts.app')

@section('title', 'GRN: ' . $goodsReceipt->grn_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Goods Receipt Note</h1>
        <p class="fw-medium"><code>{{ $goodsReceipt->grn_no }}</code></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ url('/goods-receipts') }}" class="btn btn-light">Back</a>
        @if(!$goodsReceipt->bill)
            @can('tenant.purchase-bills.create')
                <a href="{{ url('/purchase-bills/create?goods_receipt_id=' . $goodsReceipt->id) }}"
                   class="btn btn-primary">Create Purchase Bill</a>
            @endcan
        @endif
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Details</strong></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Supplier</dt>
            <dd class="col-sm-9">{{ $goodsReceipt->supplier?->name }}</dd>

            <dt class="col-sm-3">Branch</dt>
            <dd class="col-sm-9">{{ $goodsReceipt->branch?->name }}</dd>

            <dt class="col-sm-3">Purchase Order</dt>
            <dd class="col-sm-9">{{ $goodsReceipt->purchaseOrder?->po_no ?? '—' }}</dd>

            <dt class="col-sm-3">Receipt Date</dt>
            <dd class="col-sm-9">{{ $goodsReceipt->receipt_date?->format('Y-m-d') }}</dd>

            <dt class="col-sm-3">Received By</dt>
            <dd class="col-sm-9">{{ $goodsReceipt->receivedBy?->name ?? '—' }}</dd>

            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9">
                <span class="badge bg-success">{{ ucfirst($goodsReceipt->status) }}</span>
            </dd>

            <dt class="col-sm-3">Purchase Bill</dt>
            <dd class="col-sm-9">
                @if($goodsReceipt->bill)
                    <a href="{{ url('/purchase-bills/' . $goodsReceipt->bill->id) }}">
                        {{ $goodsReceipt->bill->bill_no }}
                    </a>
                @else
                    <span class="badge bg-warning text-dark">Pending</span>
                @endif
            </dd>

            @if($goodsReceipt->notes)
                <dt class="col-sm-3">Notes</dt>
                <dd class="col-sm-9">{{ $goodsReceipt->notes }}</dd>
            @endif
        </dl>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Received Lines</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">GRN product lines</caption>
            <thead>
            <tr>
                <th scope="col">Product</th>
                <th scope="col">Variant</th>
                <th scope="col">Batch</th>
                <th scope="col">Expiry</th>
                <th scope="col">Qty</th>
                <th scope="col">Unit Cost</th>
                <th scope="col">Line Total</th>
            </tr>
            </thead>
            <tbody>
            @foreach($goodsReceipt->lines as $line)
                <tr>
                    <td>
                        <code>{{ $line->product?->sku }}</code>
                        <small class="d-block">{{ $line->product?->name }}</small>
                    </td>
                    <td>{{ $line->variant?->name ?? '—' }}</td>
                    <td>{{ $line->batch_no ?: '—' }}</td>
                    <td>{{ $line->expiry_date?->format('Y-m-d') ?? '—' }}</td>
                    <td>{{ number_format($line->quantity_received, 3) }}</td>
                    <td>{{ number_format($line->unit_cost, 4) }}</td>
                    <td>{{ number_format($line->line_total, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Inventory Ledger Entries</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Stock ledger entries from this GRN</caption>
            <thead>
            <tr>
                <th scope="col">Product</th>
                <th scope="col">Branch</th>
                <th scope="col">Direction</th>
                <th scope="col">Qty</th>
                <th scope="col">Unit Cost</th>
                <th scope="col">Balance After</th>
            </tr>
            </thead>
            <tbody>
            @forelse($ledgers as $ledger)
                <tr>
                    <td>{{ $ledger->product?->name }}</td>
                    <td>{{ $ledger->branch?->name }}</td>
                    <td>
                        <span class="badge bg-{{ $ledger->direction === 'in' ? 'success' : 'danger' }}">
                            {{ ucfirst($ledger->direction) }}
                        </span>
                    </td>
                    <td>{{ number_format($ledger->quantity, 3) }}</td>
                    <td>{{ number_format($ledger->unit_cost, 4) }}</td>
                    <td>{{ number_format($ledger->balance_after, 3) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-3">No ledger entries.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
