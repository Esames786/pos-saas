@extends('layouts.app')

@section('title', 'Stock Adjustments')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Stock Adjustments</h1>
        <p class="fw-medium">Opening stock, increases, decreases, and wastage.</p>
    </div>
    @can('tenant.stock-adjustments.create')
        <a href="{{ url('/stock-adjustments/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1"></i>New Adjustment
        </a>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/stock-adjustments') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="sa-branch" class="form-label">Branch</label>
                <select id="sa-branch" name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="sa-type" class="form-label">Type</label>
                <select id="sa-type" name="adjustment_type" class="form-select">
                    <option value="">All Types</option>
                    <option value="opening"  @selected(request('adjustment_type') === 'opening')>Opening</option>
                    <option value="increase" @selected(request('adjustment_type') === 'increase')>Increase</option>
                    <option value="decrease" @selected(request('adjustment_type') === 'decrease')>Decrease</option>
                    <option value="wastage"  @selected(request('adjustment_type') === 'wastage')>Wastage</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-dark">Filter</button>
                <a href="{{ url('/stock-adjustments') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Stock adjustments list</caption>
            <thead>
                <tr>
                    <th scope="col">Adj. No</th>
                    <th scope="col">Branch</th>
                    <th scope="col">Type</th>
                    <th scope="col">Date</th>
                    <th scope="col">Status</th>
                    <th scope="col">Posted By</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($adjustments as $adjustment)
                <tr>
                    <td><code>{{ $adjustment->adjustment_no }}</code></td>
                    <td>{{ $adjustment->branch?->name }}</td>
                    <td><span class="badge bg-light text-dark">{{ ucfirst($adjustment->adjustment_type) }}</span></td>
                    <td>{{ $adjustment->adjustment_date->format('d M Y') }}</td>
                    <td>
                        <span class="badge bg-{{ $adjustment->status === 'posted' ? 'success' : 'secondary' }}">
                            {{ ucfirst($adjustment->status) }}
                        </span>
                    </td>
                    <td>{{ $adjustment->postedBy?->name ?? '—' }}</td>
                    <td class="text-end">
                        @can('tenant.stock-adjustments.show')
                            <a href="{{ url('/stock-adjustments/' . $adjustment->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No adjustments found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $adjustments->links() }}</div>
    </div>
</div>
@endsection
