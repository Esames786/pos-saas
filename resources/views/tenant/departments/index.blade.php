@extends('layouts.app')

@section('title', 'Departments')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Departments</h1>
        <p class="fw-medium text-muted mb-0">Internal responsibility areas inside each branch.</p>
    </div>
    <div class="d-flex gap-2">
        @can('tenant.departments.dashboard')
            <a href="{{ url('/departments/dashboard') }}" class="btn btn-light"><i class="ti ti-layout-dashboard me-1"></i>Dashboard</a>
        @endcan
        @can('tenant.departments.create')
            <a href="{{ url('/departments/create') }}" class="btn btn-primary">
                <i class="ti ti-plus me-1"></i>Create Department
            </a>
        @endcan
    </div>
</div>

<div class="card border-primary-subtle mb-3">
    <div class="card-body d-flex flex-wrap align-items-start gap-3 py-2">
        <span class="badge bg-primary-subtle text-primary-emphasis mt-1"><i class="ti ti-building-warehouse me-1"></i>Mapping only</span>
        <div class="small">
            Departments are internal responsibility areas inside a branch, such as <strong>Kitchen, Bar, Bakery, Packing, or Main Store</strong>.
            They help report department-wise sales and expected stock usage.
            <div class="text-muted mt-1">They do <strong>not</strong> change official branch stock in this phase — branch inventory remains the single source of truth.</div>
        </div>
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
        <form method="GET" action="{{ url('/departments') }}" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="dept-search" class="form-label">Search</label>
                <input id="dept-search" type="text" name="search" value="{{ request('search') }}"
                       class="form-control" placeholder="Code or name">
            </div>
            <div class="col-md-3">
                <label for="dept-branch" class="form-label">Branch</label>
                <select id="dept-branch" name="branch_id" class="form-select">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-dark">Filter</button>
                <a href="{{ url('/departments') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Departments list</caption>
            <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Name</th>
                    <th scope="col">Branch</th>
                    <th scope="col">Mapped Categories</th>
                    <th scope="col">Product Overrides</th>
                    <th scope="col">End-Day Count</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($departments as $department)
                <tr>
                    <td><code>{{ $department->code }}</code></td>
                    <td>
                        <a href="{{ url('/departments/' . $department->id) }}" class="fw-semibold">{{ $department->name }}</a>
                    </td>
                    <td>{{ $department->branch?->name ?? '—' }}</td>
                    <td>
                        @if($department->category_maps_count)
                            <span class="badge bg-info-subtle text-info-emphasis">{{ $department->category_maps_count }} categories</span>
                            <span class="text-muted small">
                                {{ $department->categoryMaps->take(3)->map(fn ($m) => $m->category?->name)->filter()->implode(', ') }}{{ $department->category_maps_count > 3 ? '…' : '' }}
                            </span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($department->include_overrides_count)
                            <span class="badge bg-success-subtle text-success-emphasis">+{{ $department->include_overrides_count }} include</span>
                        @endif
                        @if($department->exclude_overrides_count)
                            <span class="badge bg-warning-subtle text-warning-emphasis">−{{ $department->exclude_overrides_count }} exclude</span>
                        @endif
                        @if(!$department->include_overrides_count && !$department->exclude_overrides_count)
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($department->require_end_day_count)
                            <span class="badge bg-primary-subtle text-primary-emphasis">Required</span>
                        @else
                            <span class="text-muted">No</span>
                        @endif
                    </td>
                    <td>
                        @if($department->status === 'active')
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        @can('tenant.departments.show')
                            <a href="{{ url('/departments/' . $department->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                        @can('tenant.departments.edit')
                            <a href="{{ url('/departments/' . $department->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                        @endcan
                        @can('tenant.departments.destroy')
                            <form method="POST" action="{{ url('/departments/' . $department->id) }}" class="d-inline"
                                  onsubmit="return confirm('Delete this department? Its category/product mappings will be removed. Sales and stock data are NOT affected — its products will simply show as Unassigned in reports.')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        No departments found yet.<br>
                        <span class="small">Create departments such as Kitchen, Packing, Bar, Bakery, or Main Store.</span>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $departments->links() }}</div>
    </div>
</div>
@endsection
