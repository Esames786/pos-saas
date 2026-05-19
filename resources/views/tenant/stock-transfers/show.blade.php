@extends('layouts.app')

@section('title', 'Transfer ' . $stockTransfer->transfer_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $stockTransfer->transfer_no }}</h1>
        <p class="fw-medium text-muted mb-0">
            {{ $stockTransfer->fromBranch?->name }} &rarr; {{ $stockTransfer->toBranch?->name }} &middot;
            {{ $stockTransfer->transfer_date->format('d M Y') }}
        </p>
    </div>
    <a href="{{ url('/stock-transfers') }}" class="btn btn-light">Back</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><strong>Details</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        <span class="badge bg-{{ $stockTransfer->status === 'posted' ? 'success' : 'secondary' }}">
                            {{ ucfirst($stockTransfer->status) }}
                        </span>
                    </dd>
                    <dt class="col-sm-4">Posted By</dt>
                    <dd class="col-sm-8">{{ $stockTransfer->postedBy?->name ?? '—' }}</dd>
                    <dt class="col-sm-4">Posted At</dt>
                    <dd class="col-sm-8">{{ $stockTransfer->posted_at?->format('d M Y H:i') ?? '—' }}</dd>
                    @if($stockTransfer->notes)
                    <dt class="col-sm-4">Notes</dt>
                    <dd class="col-sm-8">{{ $stockTransfer->notes }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><strong>Transfer Lines</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Transfer product lines</caption>
            <thead>
                <tr>
                    <th scope="col">Product</th>
                    <th scope="col">Variant</th>
                    <th scope="col">Qty</th>
                    <th scope="col">Unit Cost</th>
                    <th scope="col">Total Value</th>
                </tr>
            </thead>
            <tbody>
            @foreach($stockTransfer->lines as $line)
                <tr>
                    <td>
                        <code>{{ $line->product?->sku }}</code><br>
                        <small>{{ $line->product?->name }}</small>
                    </td>
                    <td>{{ $line->variant?->name ?? '—' }}</td>
                    <td>{{ number_format($line->quantity, 3) }}</td>
                    <td>{{ number_format($line->unit_cost, 4) }}</td>
                    <td>{{ number_format($line->quantity * $line->unit_cost, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Ledger Entries</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Ledger entries for this transfer</caption>
            <thead>
                <tr>
                    <th scope="col">Branch</th>
                    <th scope="col">Product</th>
                    <th scope="col">Movement</th>
                    <th scope="col">Direction</th>
                    <th scope="col">Qty</th>
                    <th scope="col">Unit Cost</th>
                    <th scope="col">Balance After</th>
                </tr>
            </thead>
            <tbody>
            @forelse($ledgers as $ledger)
                <tr>
                    <td>{{ $ledger->branch?->name }}</td>
                    <td>{{ $ledger->product?->name }}</td>
                    <td><span class="badge bg-light text-dark">{{ str_replace('_', ' ', ucfirst($ledger->movement_type)) }}</span></td>
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
                    <td colspan="7" class="text-center text-muted py-3">No ledger entries.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
