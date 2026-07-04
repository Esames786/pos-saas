@extends('layouts.app')

@section('title', 'Department Movement Report')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Department Stock Movements</h1>
        <p class="fw-medium text-muted mb-0">Custody movement ledger — issues, returns, and department transfers.</p>
    </div>
    <a href="{{ url('/department-stock/transfers') }}" class="btn btn-light">Documents</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/reports/departments/movements') }}" class="row g-3 align-items-end">
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
                <label for="department_id" class="form-label">Department</label>
                <select id="department_id" name="department_id" class="form-select">
                    <option value="">All departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}" @selected($filters['department_id'] == $dept->id)>{{ $dept->name }} ({{ $dept->branch?->name }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="movement_type" class="form-label">Movement Type</label>
                <select id="movement_type" name="movement_type" class="form-select">
                    <option value="">All types</option>
                    @foreach($movementTypes as $type)
                        <option value="{{ $type }}" @selected($filters['movement_type'] === $type)>{{ ucwords(str_replace('_', ' ', $type)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <label for="product" class="form-label">Product</label>
                <input id="product" name="product" value="{{ $filters['product'] }}" class="form-control" placeholder="SKU/name">
            </div>
            <div class="col-md-1"><button class="btn btn-dark w-100">Filter</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Department custody movements</caption>
            <thead class="table-light">
                <tr>
                    <th>Date</th><th>Department</th><th>Product</th><th>Movement Type</th><th>Dir</th>
                    <th class="text-end">Qty</th><th class="text-end">Unit Cost</th><th class="text-end">Total Cost</th>
                    <th class="text-end">Balance After</th><th>Reference</th><th>Notes</th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="fw-semibold">{{ $row->department?->name }}</td>
                    <td>{{ $row->product?->sku ? $row->product->sku . ' — ' : '' }}{{ $row->product?->name }}</td>
                    <td><span class="badge bg-light text-dark border">{{ str_replace('_', ' ', $row->movement_type) }}</span></td>
                    <td>
                        @if($row->direction === 'in')
                            <span class="badge bg-success-subtle text-success-emphasis">IN</span>
                        @else
                            <span class="badge bg-warning-subtle text-warning-emphasis">OUT</span>
                        @endif
                    </td>
                    <td class="text-end">{{ number_format($row->quantity, 3) }}</td>
                    <td class="text-end">{{ number_format($row->unit_cost, 4) }}</td>
                    <td class="text-end">{{ number_format($row->total_cost, 2) }}</td>
                    <td class="text-end">{{ number_format($row->balance_after, 3) }}</td>
                    <td class="small">{{ $row->reference_no ?? '—' }}</td>
                    <td class="small text-muted">{{ $row->notes ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="text-center text-muted py-4">No custody movements for the selected filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $rows->links() }}</div>
</div>
@endsection
