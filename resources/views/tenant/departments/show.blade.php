@extends('layouts.app')

@section('title', 'Department — ' . $department->name)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">
            {{ $department->name }}
            <code class="fs-6">{{ $department->code }}</code>
            @if($department->status === 'active')
                <span class="badge bg-success align-middle">Active</span>
            @else
                <span class="badge bg-secondary align-middle">Inactive</span>
            @endif
        </h1>
        <p class="fw-medium text-muted mb-0">
            Branch: <strong>{{ $department->branch?->name }}</strong>
            @if($department->manager) · Manager: <strong>{{ $department->manager->name }}</strong> @endif
        </p>
    </div>
    <div class="d-flex gap-2">
        @can('tenant.departments.edit')
            <a href="{{ url('/departments/' . $department->id . '/edit') }}" class="btn btn-primary">Edit</a>
        @endcan
        <a href="{{ url('/departments') }}" class="btn btn-light">Back</a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert">{{ session('status') }}</div>
@endif

{{-- Setup coverage preview --}}
<div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <strong>Setup Preview</strong>
        <span class="small text-muted">Mapping coverage + activity over the last {{ $preview['period_days'] }} days — confirms setup before future stock phases.</span>
    </div>
    <div class="card-body">
        <div class="row text-center g-3 small">
            <div class="col-6 col-md-3">
                <div class="text-muted">Mapped Categories</div>
                <div class="fw-bold fs-5">{{ $preview['mapped_category_count'] }}</div>
                <div class="text-muted">incl. children</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted">Mapped Products (est.)</div>
                <div class="fw-bold fs-5">{{ number_format($preview['mapped_product_estimate']) }}</div>
                <div class="text-muted">+{{ $preview['include_override_count'] }} include / −{{ $preview['exclude_override_count'] }} exclude</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted">Sales ({{ $preview['period_days'] }}d)</div>
                <div class="fw-bold fs-5">{{ number_format($preview['sales_net_30d'], 2) }}</div>
                <div class="text-muted">{{ number_format($preview['sales_qty_30d'], 2) }} qty</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted">Expected Consumption ({{ $preview['period_days'] }}d)</div>
                <div class="fw-bold fs-5">{{ number_format($preview['consumption_cost_30d'], 2) }}</div>
                <div class="text-muted">{{ $preview['consumption_lines_30d'] }} recent movement lines</div>
            </div>
        </div>
        @if($preview['unassigned_sold_products'] > 0)
            <div class="alert alert-warning py-2 px-3 mt-3 mb-0 small">
                <i class="ti ti-alert-triangle me-1"></i>
                <strong>{{ $preview['unassigned_sold_products'] }}</strong> product(s) sold at this branch in the last {{ $preview['period_days'] }} days are
                not mapped to <em>any</em> department. Review the
                <a href="{{ url('/reports/departments/sales?branch_id=' . $department->branch_id) }}">Department Sales report</a> Unassigned section.
            </div>
        @endif
    </div>
</div>

<div class="row g-3">
    {{-- Category mappings --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><strong>Category Responsibility</strong></div>
            <div class="card-body table-responsive p-0">
                <table class="table table-nowrap align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Category</th>
                            <th scope="col">Include Children</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($department->categoryMaps as $map)
                        <tr>
                            <td>{{ $map->category?->name ?? ('#' . $map->category_id) }}</td>
                            <td>
                                @if($map->include_children)
                                    <span class="badge bg-success-subtle text-success-emphasis">Yes</span>
                                @else
                                    <span class="text-muted">No</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-center text-muted py-3">No categories mapped.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Product overrides --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><strong>Product Overrides</strong></div>
            <div class="card-body table-responsive p-0">
                <table class="table table-nowrap align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">Type</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($department->productOverrides as $override)
                        <tr>
                            <td>{{ $override->product?->sku ? $override->product->sku . ' — ' : '' }}{{ $override->product?->name ?? ('#' . $override->product_id) }}</td>
                            <td>
                                @if($override->mapping_type === 'include')
                                    <span class="badge bg-success-subtle text-success-emphasis">Include</span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning-emphasis">Exclude</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-center text-muted py-3">No product overrides.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center small">
        <span class="text-muted">Reporting flags:</span>
        @if($department->allow_stock_issue)
            <span class="badge bg-info-subtle text-info-emphasis">Stock issue allowed (future phase)</span>
        @endif
        @if($department->require_end_day_count)
            <span class="badge bg-primary-subtle text-primary-emphasis">End-day count required (future phase)</span>
        @endif
        <span class="text-muted ms-auto">
            Reports:
            <a href="{{ url('/reports/departments/sales?branch_id=' . $department->branch_id . '&department_id=' . $department->id) }}">Sales</a> ·
            <a href="{{ url('/reports/departments/consumption?branch_id=' . $department->branch_id . '&department_id=' . $department->id) }}">Consumption</a>
        </span>
    </div>
</div>
@endsection
