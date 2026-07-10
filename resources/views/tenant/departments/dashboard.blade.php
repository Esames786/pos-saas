@extends('layouts.app')

@section('title', 'Department Command Center')

@php $d = $dashboard; @endphp

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Department Command Center</h1>
        <p class="fw-medium text-muted mb-0">Custody stock, exceptions, counts, reconciliation, and allocation risk — one place.</p>
    </div>
    <a href="{{ url('/departments') }}" class="btn btn-light">Departments</a>
</div>

<div class="card border-primary-subtle mb-3">
    <div class="card-body py-2 small">
        <i class="ti ti-info-circle me-1"></i>
        This dashboard summarizes department custody stock, consumption exceptions, counts, reconciliation, and allocation risk.
        <strong>Branch stock remains the official financial inventory truth.</strong>
    </div>
</div>

{{-- Filter bar --}}
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/departments/dashboard') }}" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label for="date_from" class="form-label">Date From</label>
                <input id="date_from" type="date" name="date_from" value="{{ $d['filters']['date_from'] }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label for="date_to" class="form-label">Date To</label>
                <input id="date_to" type="date" name="date_to" value="{{ $d['filters']['date_to'] }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label for="branch_id" class="form-label">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($d['filters']['branch_id'] == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="department_id" class="form-label">Department</label>
                <select id="department_id" name="department_id" class="form-select">
                    <option value="">All departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}" @selected($d['filters']['department_id'] == $dept->id)>{{ $dept->name }} ({{ $dept->branch?->name }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-dark">Apply</button>
                <a href="{{ url('/departments/dashboard') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

{{-- Top cards --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-md-2"><div class="card h-100"><div class="card-body py-3 text-center"><div class="text-muted small">Custody Stock Value</div><div class="fw-bold fs-5">{{ number_format($d['cards']['stock_value'], 2) }}</div></div></div></div>
    <div class="col-6 col-md-2"><div class="card h-100"><div class="card-body py-3 text-center"><div class="text-muted small">Stocked Products</div><div class="fw-bold fs-5">{{ $d['cards']['stocked_products'] }}</div></div></div></div>
    <div class="col-6 col-md-2"><div class="card h-100 {{ $d['cards']['open_exceptions'] ? 'border-warning' : '' }}"><div class="card-body py-3 text-center"><div class="text-muted small">Open Exceptions</div><div class="fw-bold fs-5 {{ $d['cards']['open_exceptions'] ? 'text-warning' : '' }}">{{ $d['cards']['open_exceptions'] }}</div>
        <div class="text-muted" style="font-size:.72rem">{{ $d['exceptions_breakdown']['insufficient'] }} short · {{ $d['exceptions_breakdown']['no_mapping'] }} unmapped{{ $d['exceptions_breakdown']['oldest_days'] !== null ? ' · oldest ' . $d['exceptions_breakdown']['oldest_days'] . 'd' : '' }}</div>
    </div></div></div>
    <div class="col-6 col-md-2"><div class="card h-100 {{ $d['cards']['awaiting_approval'] ? 'border-info' : '' }}"><div class="card-body py-3 text-center"><div class="text-muted small">Counts Awaiting Approval</div><div class="fw-bold fs-5 {{ $d['cards']['awaiting_approval'] ? 'text-info' : '' }}">{{ $d['cards']['awaiting_approval'] }}</div>
        <div class="text-muted" style="font-size:.72rem">{{ $d['counts']['draft'] }} draft · {{ $d['counts']['approved'] }} approved · {{ $d['counts']['rejected'] }} rejected</div>
    </div></div></div>
    <div class="col-6 col-md-2"><div class="card h-100 {{ $d['cards']['negative_rows'] ? 'border-danger' : '' }}"><div class="card-body py-3 text-center"><div class="text-muted small">Negative Custody Rows</div><div class="fw-bold fs-5 {{ $d['cards']['negative_rows'] ? 'text-danger' : 'text-success' }}">{{ $d['cards']['negative_rows'] }}</div></div></div></div>
    <div class="col-6 col-md-2"><div class="card h-100 {{ $d['cards']['allocation_risk'] ? 'border-danger' : '' }}"><div class="card-body py-3 text-center"><div class="text-muted small">Allocation Risk Items</div><div class="fw-bold fs-5 {{ $d['cards']['allocation_risk'] ? 'text-danger' : 'text-success' }}">{{ $d['cards']['allocation_risk'] }}</div></div></div></div>
</div>

{{-- Quick links --}}
<div class="card mb-3">
    <div class="card-body py-2 d-flex flex-wrap gap-2 small align-items-center">
        <span class="text-muted me-1">Quick actions:</span>
        @can('tenant.departments.create')<a href="{{ url('/departments/create') }}" class="btn btn-sm btn-light"><i class="ti ti-plus me-1"></i>Create Department</a>@endcan
        @can('tenant.department-stock.transfers.create')<a href="{{ url('/department-stock/transfers/create') }}" class="btn btn-sm btn-success"><i class="ti ti-arrow-down-to-arc me-1"></i>Issue Stock</a>@endcan
        @can('tenant.department-counts.create')<a href="{{ url('/department-counts/create') }}" class="btn btn-sm btn-primary"><i class="ti ti-clipboard-check me-1"></i>Department Count</a>@endcan
        <span class="ms-auto"></span>
        @can('tenant.reports.departments.stock')<a href="{{ url('/reports/departments/stock') }}" class="btn btn-sm btn-light">Stock Report</a>@endcan
        @can('tenant.reports.departments.movements')<a href="{{ url('/reports/departments/movements') }}" class="btn btn-sm btn-light">Movements</a>@endcan
        @can('tenant.reports.departments.consumption-exceptions')<a href="{{ url('/reports/departments/consumption-exceptions') }}" class="btn btn-sm btn-light">Exceptions</a>@endcan
        @can('tenant.reports.departments.reconciliation')<a href="{{ url('/reports/departments/reconciliation') }}" class="btn btn-sm btn-light">Reconciliation</a>@endcan
        @can('tenant.reports.departments.allocation')<a href="{{ url('/reports/departments/allocation') }}" class="btn btn-sm btn-light">Allocation</a>@endcan
    </div>
</div>

{{-- Section 1: Department Health --}}
<div class="card mb-3">
    <div class="card-header"><strong>Department Health</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Department</th><th>Branch</th>
                    <th class="text-end">Stock Value</th><th class="text-end">Stocked</th>
                    <th class="text-end">Open Exceptions</th><th class="text-end">Pending Counts</th>
                    <th>Last Count</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            @forelse($d['health'] as $row)
                <tr>
                    <td><a href="{{ url('/departments/' . $row['id']) }}" class="fw-semibold">{{ $row['department'] }}</a></td>
                    <td>{{ $row['branch'] }}</td>
                    <td class="text-end">{{ number_format($row['stock_value'], 2) }}</td>
                    <td class="text-end">{{ $row['stocked'] }}</td>
                    <td class="text-end {{ $row['open_exceptions'] ? 'text-warning fw-semibold' : 'text-muted' }}">{{ $row['open_exceptions'] }}</td>
                    <td class="text-end {{ $row['pending_counts'] ? 'text-info fw-semibold' : 'text-muted' }}">{{ $row['pending_counts'] }}</td>
                    <td class="small">
                        {{ $row['last_count'] ?? 'never' }}
                        @if(!empty($row['count_due']))
                            <span class="badge bg-warning text-dark ms-1" title="End-day count required but not approved today">Count due</span>
                        @endif
                        @if(isset($row['allow_stock_issue']) && !$row['allow_stock_issue'])
                            <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1" title="Stock issue is turned off for this department">Issue off</span>
                        @endif
                    </td>
                    <td>
                        @if($row['status'] === 'healthy')
                            <span class="badge bg-success-subtle text-success-emphasis">Healthy</span>
                        @elseif($row['status'] === 'attention')
                            <span class="badge bg-warning-subtle text-warning-emphasis">Attention</span>
                        @else
                            <span class="badge bg-danger-subtle text-danger-emphasis">Critical</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No active departments for the selected filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3 mb-3">
    {{-- Section 2: Open exceptions --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Open Exceptions</strong>
                @can('tenant.reports.departments.consumption-exceptions')
                    <a href="{{ url('/reports/departments/consumption-exceptions?status=open') }}" class="small">View all</a>
                @endcan
            </div>
            <div class="card-body table-responsive p-0">
                <table class="table table-nowrap align-middle mb-0 small">
                    <thead class="table-light"><tr><th>Date</th><th>Dept</th><th>Product</th><th>Reason</th><th class="text-end">Qty</th><th>Ref</th></tr></thead>
                    <tbody>
                    @forelse($d['top_exceptions'] as $exc)
                        <tr>
                            <td>{{ $exc->created_at?->format('m-d H:i') }}</td>
                            <td>{{ $exc->department?->name ?? '—' }}</td>
                            <td>{{ $exc->product?->name ?? '—' }}</td>
                            <td><span class="badge bg-warning-subtle text-warning-emphasis">{{ str_replace('_', ' ', $exc->reason) }}</span></td>
                            <td class="text-end">{{ number_format($exc->quantity, 3) }}</td>
                            <td>{{ $exc->reference_no ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-3"><i class="ti ti-circle-check me-1"></i>No open exceptions.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Section 3: Counts awaiting approval --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Counts Awaiting Approval</strong>
                @can('tenant.department-counts.index')
                    <a href="{{ url('/department-counts?status=submitted') }}" class="small">View all</a>
                @endcan
            </div>
            <div class="card-body table-responsive p-0">
                <table class="table table-nowrap align-middle mb-0 small">
                    <thead class="table-light"><tr><th>Count No</th><th>Dept</th><th>Date</th><th class="text-end">Variance Value</th><th>Submitted</th><th></th></tr></thead>
                    <tbody>
                    @forelse($d['awaiting'] as $session)
                        <tr>
                            <td class="fw-semibold">{{ $session->count_no }}</td>
                            <td>{{ $session->department?->name }}</td>
                            <td>{{ $session->count_date?->format('Y-m-d') }}</td>
                            <td class="text-end {{ $session->totalVarianceValue() < 0 ? 'text-danger' : '' }}">{{ number_format($session->totalVarianceValue(), 2) }}</td>
                            <td>{{ $session->submittedBy?->name }} · {{ $session->submitted_at?->format('m-d H:i') }}</td>
                            <td class="text-end"><a href="{{ url('/department-counts/' . $session->id) }}" class="btn btn-sm btn-light">Review</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-3"><i class="ti ti-circle-check me-1"></i>Nothing awaiting approval.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    {{-- Section 4: Allocation risk --}}
    <div class="col-lg-6">
        <div class="card h-100 {{ count($d['over_allocated']) ? 'border-danger-subtle' : '' }}">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Allocation Risk</strong>
                @can('tenant.reports.departments.allocation')
                    <a href="{{ url('/reports/departments/allocation?only_allocated=1') }}" class="small">Full report</a>
                @endcan
            </div>
            <div class="card-body table-responsive p-0">
                <table class="table table-nowrap align-middle mb-0 small">
                    <thead class="table-light"><tr><th>Branch</th><th>Product</th><th class="text-end">Official</th><th class="text-end">Allocated</th><th class="text-end">Over</th></tr></thead>
                    <tbody>
                    @forelse($d['over_allocated'] as $row)
                        <tr>
                            <td>{{ $branches->firstWhere('id', $row['branch_id'])?->name ?? ('#' . $row['branch_id']) }}</td>
                            <td>{{ $row['sku'] ? $row['sku'] . ' — ' : '' }}{{ $row['product'] }}</td>
                            <td class="text-end">{{ number_format($row['official'], 3) }}</td>
                            <td class="text-end">{{ number_format($row['allocated'], 3) }}</td>
                            <td class="text-end text-danger fw-bold">{{ number_format($row['over'], 3) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-3"><i class="ti ti-circle-check me-1"></i>No products over-allocated — custody never exceeds official stock.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Reconciliation variance summary --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Reconciliation Variance <span class="text-muted small">(approved, period)</span></strong>
                @can('tenant.reports.departments.reconciliation')
                    <a href="{{ url('/reports/departments/reconciliation') }}" class="small">Full report</a>
                @endcan
            </div>
            <div class="card-body small">
                <div class="row text-center g-3 mb-2">
                    <div class="col-4"><div class="text-muted">Positive</div><div class="fw-bold text-success">{{ number_format($d['variance']['positive_value'], 2) }}</div></div>
                    <div class="col-4"><div class="text-muted">Negative</div><div class="fw-bold text-danger">{{ number_format($d['variance']['negative_value'], 2) }}</div></div>
                    <div class="col-4"><div class="text-muted">Movements ({{ $d['movements']['total'] }})</div><div class="fw-bold">{{ number_format($d['movements']['total_value'], 2) }}</div>
                        <div class="text-muted" style="font-size:.72rem">{{ $d['movements']['shadow'] }} shadow · {{ $d['movements']['issue_return_transfer'] }} moves · {{ $d['movements']['adjustments'] }} adj</div>
                    </div>
                </div>
                @if(count($d['variance']['top_products']))
                    <div class="border-top pt-2">
                        <div class="text-muted mb-1">Top variance products:</div>
                        @foreach($d['variance']['top_products'] as $row)
                            <div class="d-flex justify-content-between"><span>{{ $row['product'] }}</span><span class="fw-semibold">{{ number_format($row['value'], 2) }}</span></div>
                        @endforeach
                    </div>
                @else
                    <div class="text-muted text-center py-2">No approved count variances in this period.</div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Section 5: Recent movements --}}
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Recent Custody Movements</strong>
        @can('tenant.reports.departments.movements')
            <a href="{{ url('/reports/departments/movements') }}" class="small">View all</a>
        @endcan
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0 small">
            <thead class="table-light"><tr><th>Date</th><th>Department</th><th>Product</th><th>Movement</th><th>Dir</th><th class="text-end">Qty</th><th class="text-end">Value</th><th>Ref</th></tr></thead>
            <tbody>
            @forelse($d['recent_movements'] as $m)
                <tr>
                    <td>{{ $m->created_at?->format('m-d H:i') }}</td>
                    <td>{{ $m->department?->name }}</td>
                    <td>{{ $m->product?->name }}</td>
                    <td><span class="badge bg-light text-dark border">{{ str_replace('_', ' ', $m->movement_type) }}</span></td>
                    <td>{!! $m->direction === 'in' ? '<span class="badge bg-success-subtle text-success-emphasis">IN</span>' : '<span class="badge bg-warning-subtle text-warning-emphasis">OUT</span>' !!}</td>
                    <td class="text-end">{{ number_format($m->quantity, 3) }}</td>
                    <td class="text-end">{{ number_format($m->total_cost, 2) }}</td>
                    <td>{{ $m->reference_no ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-3">No custody movements yet — issue stock to a department to get started.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
