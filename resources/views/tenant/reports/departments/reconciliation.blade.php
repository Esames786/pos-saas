@extends('layouts.app')

@section('title', 'Department Reconciliation Report')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Department Reconciliation</h1>
        <p class="fw-medium text-muted mb-0">End-day count variances — expected vs actual department custody stock.</p>
    </div>
    <a href="{{ url('/department-counts') }}" class="btn btn-light">Department Counts</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/reports/departments/reconciliation') }}" class="row g-3 align-items-end">
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
            <div class="col-md-1">
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All</option>
                    @foreach($statuses as $status)
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
    <div class="col-md-7">
        <div class="card h-100">
            <div class="card-body row text-center g-3 small">
                <div class="col-6 col-md-3"><div class="text-muted">Positive Variance</div><div class="fw-bold fs-6 text-success">{{ number_format($report['summary']['positive_qty'], 3) }}</div><div class="text-muted">{{ number_format($report['summary']['positive_value'], 2) }}</div></div>
                <div class="col-6 col-md-3"><div class="text-muted">Negative Variance</div><div class="fw-bold fs-6 text-danger">{{ number_format($report['summary']['negative_qty'], 3) }}</div><div class="text-muted">{{ number_format($report['summary']['negative_value'], 2) }}</div></div>
                <div class="col-6 col-md-3"><div class="text-muted">Awaiting Approval</div><div class="fw-bold fs-6">{{ $report['summary']['awaiting_approval'] }}</div></div>
                <div class="col-6 col-md-3">
                    <div class="text-muted">By Department</div>
                    @forelse($report['summary']['by_department'] as $name => $value)
                        <div class="small">{{ $name }}: <span class="{{ $value < 0 ? 'text-danger' : '' }}">{{ number_format($value, 2) }}</span></div>
                    @empty
                        <div class="text-muted">—</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card h-100">
            <div class="card-header py-2"><strong class="small">Top Variance Products</strong></div>
            <div class="card-body py-2 small">
                @forelse($report['summary']['top_products'] as $row)
                    <div class="d-flex justify-content-between"><span>{{ $row['product'] }}</span><span class="fw-semibold">{{ number_format($row['value'], 2) }}</span></div>
                @empty
                    <div class="text-muted">No variances in this period.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Department count reconciliation lines</caption>
            <thead class="table-light">
                <tr>
                    <th>Count No</th><th>Date</th><th>Department</th><th>Product</th>
                    <th class="text-end">Expected</th><th class="text-end">Counted</th>
                    <th class="text-end">Variance Qty</th><th class="text-end">Variance Value</th>
                    <th>Reason</th><th>Status</th><th>Approved By</th>
                </tr>
            </thead>
            <tbody>
            @forelse($report['rows'] as $line)
                <tr>
                    <td><a href="{{ url('/department-counts/' . $line->department_count_session_id) }}">{{ $line->session?->count_no }}</a></td>
                    <td>{{ $line->session?->count_date?->format('Y-m-d') }}</td>
                    <td>{{ $line->session?->department?->name }}</td>
                    <td>{{ $line->product?->sku ? $line->product->sku . ' — ' : '' }}{{ $line->product?->name }}</td>
                    <td class="text-end">{{ number_format($line->expected_qty, 3) }}</td>
                    <td class="text-end">{{ number_format($line->counted_qty, 3) }}</td>
                    <td class="text-end {{ (float) $line->variance_qty < 0 ? 'text-danger fw-semibold' : ((float) $line->variance_qty > 0 ? 'text-success fw-semibold' : 'text-muted') }}">{{ number_format($line->variance_qty, 3) }}</td>
                    <td class="text-end {{ (float) $line->variance_value < 0 ? 'text-danger' : '' }}">{{ number_format($line->variance_value, 2) }}</td>
                    <td>
                        @if($line->reason_code)
                            <span class="badge bg-light text-dark border">{{ ucwords(str_replace('_', ' ', $line->reason_code)) }}</span>
                        @else — @endif
                    </td>
                    <td>
                        @if($line->session?->status === 'approved')
                            <span class="badge bg-success">Approved</span>
                        @elseif($line->session?->status === 'submitted')
                            <span class="badge bg-info">Submitted</span>
                        @else
                            <span class="badge bg-light text-dark border">{{ ucfirst($line->session?->status ?? '—') }}</span>
                        @endif
                    </td>
                    <td class="small">{{ $line->session?->approvedBy?->name ?? '—' }}{{ $line->session?->approved_at ? ' · ' . $line->session->approved_at->format('m-d H:i') : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="text-center text-muted py-4">No count lines for the selected filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $report['rows']->links() }}</div>
</div>
@endsection
