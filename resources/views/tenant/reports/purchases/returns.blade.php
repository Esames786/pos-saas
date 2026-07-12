@extends('layouts.app')

@section('title', 'Purchase Returns Report')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Purchase Returns Report</h1>
        <p class="fw-medium text-muted mb-0">Goods returned to suppliers — quantities, values, and reasons.</p>
    </div>
    <a href="{{ url('/purchase-returns') }}" class="btn btn-light">Purchase Returns</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/reports/purchases/returns') }}" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label for="date_from" class="form-label">Date From</label>
                <input id="date_from" type="date" name="date_from" value="{{ $filters['date_from'] }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label for="date_to" class="form-label">Date To</label>
                <input id="date_to" type="date" name="date_to" value="{{ $filters['date_to'] }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label for="branch_id" class="form-label">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($filters['branch_id'] == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="supplier_id" class="form-label">Supplier</label>
                <select id="supplier_id" name="supplier_id" class="form-select">
                    <option value="">All suppliers</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected($filters['supplier_id'] == $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All</option>
                    @foreach(['draft', 'posted', 'cancelled'] as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="reason" class="form-label">Reason</label>
                <select id="reason" name="reason" class="form-select">
                    <option value="">All reasons</option>
                    @foreach($reasons as $reason)
                        <option value="{{ $reason }}" @selected($filters['reason'] === $reason)>{{ ucwords(str_replace('_', ' ', $reason)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1"><button class="btn btn-dark w-100">Filter</button></div>
        </form>
    </div>
</div>

{{-- Summary --}}
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body row text-center g-2 small">
                <div class="col-6"><div class="text-muted">Total Returned Qty</div><div class="fw-bold fs-5">{{ number_format($report['summary']['total_qty'], 3) }}</div></div>
                <div class="col-6"><div class="text-muted">Total Returned Value</div><div class="fw-bold fs-5">{{ number_format($report['summary']['total_value'], 2) }}</div></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header py-2"><strong class="small">By Supplier</strong></div>
            <div class="card-body py-2 small">
                @forelse(array_slice($report['summary']['by_supplier'], 0, 5, true) as $name => $value)
                    <div class="d-flex justify-content-between"><span>{{ $name }}</span><span class="fw-semibold">{{ number_format($value, 2) }}</span></div>
                @empty
                    <div class="text-muted">No returns in this period.</div>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header py-2"><strong class="small">By Reason</strong></div>
            <div class="card-body py-2 small">
                @forelse(array_slice($report['summary']['by_reason'], 0, 5, true) as $reason => $value)
                    <div class="d-flex justify-content-between"><span>{{ ucwords(str_replace('_', ' ', $reason)) }}</span><span class="fw-semibold">{{ number_format($value, 2) }}</span></div>
                @empty
                    <div class="text-muted">No returns in this period.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Purchase return lines</caption>
            <thead class="table-light">
                <tr>
                    <th>Date</th><th>Return No</th><th>Supplier</th><th>Branch</th><th>Product</th>
                    <th class="text-end">Qty</th><th class="text-end">Unit Cost</th><th class="text-end">Line Total</th>
                    <th>Reason</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            @forelse($report['rows'] as $line)
                <tr>
                    <td>{{ $line->purchaseReturn?->return_date?->format('Y-m-d') }}</td>
                    <td><a href="{{ url('/purchase-returns/' . $line->purchase_return_id) }}">{{ $line->purchaseReturn?->return_no }}</a></td>
                    <td>{{ $line->purchaseReturn?->supplier?->name }}</td>
                    <td>{{ $line->purchaseReturn?->branch?->name }}</td>
                    <td>{{ $line->product?->sku ? $line->product->sku . ' — ' : '' }}{{ $line->product?->name }}</td>
                    <td class="text-end">{{ number_format($line->quantity, 3) }}</td>
                    <td class="text-end">{{ number_format($line->unit_cost, 4) }}</td>
                    <td class="text-end fw-semibold">{{ number_format($line->line_total, 2) }}</td>
                    <td>
                        @php $reason = $line->reason_code ?: $line->purchaseReturn?->reason_code; @endphp
                        @if($reason)
                            <span class="badge bg-light text-dark border">{{ ucwords(str_replace('_', ' ', $reason)) }}</span>
                        @else — @endif
                    </td>
                    <td>
                        @if($line->purchaseReturn?->status === 'posted')
                            <span class="badge bg-success">Posted</span>
                        @elseif($line->purchaseReturn?->status === 'cancelled')
                            <span class="badge bg-secondary">Cancelled</span>
                        @else
                            <span class="badge bg-warning text-dark">Draft</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="text-center text-muted py-4">No purchase return lines for the selected filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $report['rows']->links() }}</div>
</div>
@endsection
