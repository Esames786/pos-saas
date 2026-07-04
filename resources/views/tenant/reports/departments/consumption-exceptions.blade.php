@extends('layouts.app')

@section('title', 'Department Consumption Exceptions')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Department Consumption Exceptions</h1>
        <p class="fw-medium text-muted mb-0">Shadow custody deductions that could not be applied — the POS sale itself always succeeded.</p>
    </div>
    <a href="{{ url('/department-stock') }}" class="btn btn-light">Department Stock</a>
</div>

<div class="card border-warning-subtle mb-3">
    <div class="card-body py-2 small">
        <i class="ti ti-info-circle me-1"></i>
        These exceptions do <strong>not</strong> mean the POS sale failed.
        They mean the official sale succeeded, but department custody stock could not be deducted.
        Fix by <strong>issuing stock</strong> to the department or <strong>mapping the product/category</strong>, then resolve/ignore the exception.
        A later successful shadow deduction for the same movement auto-resolves its exceptions.
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert">{{ session('status') }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/reports/departments/consumption-exceptions') }}" class="row g-3 align-items-end">
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
                <label for="reason" class="form-label">Reason</label>
                <select id="reason" name="reason" class="form-select">
                    <option value="">All reasons</option>
                    @foreach($reasons as $reason)
                        <option value="{{ $reason }}" @selected($filters['reason'] === $reason)>{{ ucwords(str_replace('_', ' ', $reason)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All</option>
                    @foreach(['open', 'resolved', 'ignored'] as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1"><button class="btn btn-dark w-100">Filter</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Department shadow consumption exceptions</caption>
            <thead class="table-light">
                <tr>
                    <th>Date</th><th>Branch</th><th>Department</th><th>Product</th>
                    <th>Reason</th><th class="text-end">Qty</th><th>Reference</th><th>Status</th><th>Message</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row->created_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ $row->branch?->name }}</td>
                    <td>{{ $row->department?->name ?? '—' }}</td>
                    <td>{{ $row->product?->sku ? $row->product->sku . ' — ' : '' }}{{ $row->product?->name ?? '—' }}</td>
                    <td>
                        @if($row->reason === 'insufficient_department_stock')
                            <span class="badge bg-warning-subtle text-warning-emphasis">{{ $row->reasonLabel() }}</span>
                        @elseif($row->reason === 'no_department_mapping')
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $row->reasonLabel() }}</span>
                        @else
                            <span class="badge bg-danger-subtle text-danger-emphasis">{{ $row->reasonLabel() }}</span>
                        @endif
                    </td>
                    <td class="text-end">{{ number_format($row->quantity, 3) }}</td>
                    <td class="small">{{ $row->reference_no ?? '—' }}</td>
                    <td>
                        @if($row->status === 'open')
                            <span class="badge bg-warning text-dark">Open</span>
                        @elseif($row->status === 'resolved')
                            <span class="badge bg-success">Resolved</span>
                        @else
                            <span class="badge bg-secondary">Ignored</span>
                        @endif
                    </td>
                    <td class="small text-muted" style="max-width:280px; white-space:normal;">{{ $row->message ?? '—' }}</td>
                    <td class="text-end">
                        @if($row->status === 'open')
                            @can('tenant.department-consumption-exceptions.resolve')
                                <form method="POST" action="{{ url('/department-consumption-exceptions/' . $row->id . '/resolve') }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-success">Resolve</button>
                                </form>
                            @endcan
                            @can('tenant.department-consumption-exceptions.ignore')
                                <form method="POST" action="{{ url('/department-consumption-exceptions/' . $row->id . '/ignore') }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-secondary">Ignore</button>
                                </form>
                            @endcan
                        @else
                            <span class="text-muted small">{{ $row->resolved_at?->format('Y-m-d') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="text-center text-muted py-4"><i class="ti ti-circle-check me-1"></i>No consumption exceptions — all shadow deductions applied cleanly.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $rows->links() }}</div>
</div>
@endsection
