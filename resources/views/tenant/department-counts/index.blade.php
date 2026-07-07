@extends('layouts.app')

@section('title', 'Department Counts')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Department Counts</h1>
        <p class="fw-medium text-muted mb-0">End-day physical counts reconciling department custody stock.</p>
    </div>
    <div class="d-flex gap-2">
        @can('tenant.departments.dashboard')
            <a href="{{ url('/departments/dashboard') }}" class="btn btn-light"><i class="ti ti-layout-dashboard me-1"></i>Dashboard</a>
        @endcan
        @can('tenant.department-counts.create')
            <a href="{{ url('/department-counts/create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>New Count</a>
        @endcan
    </div>
</div>

<div class="card border-primary-subtle mb-3">
    <div class="card-body py-2 small">
        <i class="ti ti-info-circle me-1"></i>
        Department count reconciles <strong>internal custody stock only</strong>.
        It does not change official branch stock or accounting by default.
        Flow: <strong>Draft</strong> (count) → <strong>Submitted</strong> → <strong>Approved</strong> (custody becomes counted qty).
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/department-counts') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="branch_id" class="form-label">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="department_id" class="form-label">Department</label>
                <select id="department_id" name="department_id" class="form-select">
                    <option value="">All departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}" @selected(request('department_id') == $dept->id)>{{ $dept->name }} ({{ $dept->branch?->name }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All</option>
                    @foreach(['draft', 'submitted', 'approved', 'rejected', 'cancelled'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-dark">Filter</button>
                <a href="{{ url('/department-counts') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Department count sessions</caption>
            <thead class="table-light">
                <tr>
                    <th>Count No</th><th>Date</th><th>Branch</th><th>Department</th>
                    <th class="text-end">Lines</th><th class="text-end">Variance Qty</th><th class="text-end">Variance Value</th>
                    <th>Status</th><th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($sessions as $session)
                <tr>
                    <td><a href="{{ url('/department-counts/' . $session->id) }}" class="fw-semibold">{{ $session->count_no }}</a></td>
                    <td>{{ $session->count_date?->format('Y-m-d') }}</td>
                    <td>{{ $session->branch?->name }}</td>
                    <td>{{ $session->department?->name }}</td>
                    <td class="text-end">{{ $session->lines->count() }}</td>
                    <td class="text-end {{ $session->totalVarianceQty() < 0 ? 'text-danger' : '' }}">{{ number_format($session->totalVarianceQty(), 3) }}</td>
                    <td class="text-end {{ $session->totalVarianceValue() < 0 ? 'text-danger' : '' }}">{{ number_format($session->totalVarianceValue(), 2) }}</td>
                    <td>
                        @if($session->status === 'approved')
                            <span class="badge bg-success">Approved</span>
                        @elseif($session->status === 'submitted')
                            <span class="badge bg-info">Submitted</span>
                        @elseif($session->status === 'rejected')
                            <span class="badge bg-danger">Rejected</span>
                        @elseif($session->status === 'cancelled')
                            <span class="badge bg-secondary">Cancelled</span>
                        @else
                            <span class="badge bg-warning text-dark">Draft</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ url('/department-counts/' . $session->id) }}" class="btn btn-sm btn-light">View</a>
                        @if($session->isDraft())
                            @can('tenant.department-counts.edit')
                                <a href="{{ url('/department-counts/' . $session->id . '/edit') }}" class="btn btn-sm btn-primary">Count</a>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No department counts yet. Create one to reconcile a department's custody stock.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $sessions->links() }}</div>
    </div>
</div>
@endsection
