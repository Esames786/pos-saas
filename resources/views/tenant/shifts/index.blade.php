@extends('layouts.app')

@section('title', 'Shifts')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Shifts</h1>
        <p class="fw-medium">View and manage terminal shifts.</p>
    </div>

    <div class="d-flex gap-2">
        @can('tenant.shifts.close')
            <a href="{{ url('/shifts-close-branch') }}" class="btn btn-outline-danger">
                <i class="ti ti-lock me-1" aria-hidden="true"></i>Close Branch
            </a>
        @endcan
        @can('tenant.shifts.create')
            <a href="{{ url('/shifts/open') }}" class="btn btn-primary">
                <i class="ti ti-plus me-1" aria-hidden="true"></i>Open Shift
            </a>
        @endcan
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/shifts') }}" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="branch-filter" class="form-label">Branch</label>
                <select id="branch-filter" name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="status-filter" class="form-label">Status</label>
                <select id="status-filter" name="status" class="form-select">
                    <option value="">All</option>
                    <option value="open" @selected(request('status') === 'open')>Open</option>
                    <option value="closed" @selected(request('status') === 'closed')>Closed</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-dark" type="submit">Filter</button>
                <a href="{{ url('/shifts') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption>Shift history (grouped by branch)</caption>
            <thead>
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">Terminal</th>
                    <th scope="col">Opened By</th>
                    <th scope="col">Opened At</th>
                    <th scope="col">Closed At</th>
                    <th scope="col">Opening Cash</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @php $grouped = collect($shifts->items())->groupBy('branch_id'); @endphp
            @forelse($grouped as $branchId => $branchShifts)
                @php
                    $branchName = $branchShifts->first()->branch?->name ?? ('Branch #' . $branchId);
                    $branchOpen = (int) ($openCounts[$branchId] ?? 0);
                @endphp
                <tr class="table-light">
                    <td colspan="7">
                        <i class="ti ti-building-store me-1" aria-hidden="true"></i>
                        <strong>{{ $branchName }}</strong>
                        @if($branchOpen > 0)
                            <span class="badge bg-warning text-dark ms-2">{{ $branchOpen }} open</span>
                        @else
                            <span class="badge bg-secondary ms-2">all closed</span>
                        @endif
                    </td>
                    <td class="text-end">
                        @if($branchOpen > 0)
                            @can('tenant.shifts.close')
                                <a href="{{ url('/shifts-close-branch?branch_id=' . $branchId) }}" class="btn btn-sm btn-danger">
                                    <i class="ti ti-lock me-1" aria-hidden="true"></i>Close Branch
                                </a>
                            @endcan
                        @endif
                    </td>
                </tr>
                @foreach($branchShifts as $shift)
                    <tr>
                        <td class="text-muted">{{ $shift->id }}</td>
                        <td class="ps-4">{{ $shift->terminal?->name ?? ('Terminal #' . $shift->terminal_id) }}</td>
                        <td>{{ $shift->openedBy?->name }}</td>
                        <td>{{ $shift->opened_at?->format('Y-m-d H:i') }}</td>
                        <td>{{ $shift->closed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td>{{ number_format($shift->opening_cash, 2) }}</td>
                        <td>
                            @if($shift->status === 'open')
                                <span class="badge bg-warning text-dark">Open</span>
                            @else
                                <span class="badge bg-secondary">Closed</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @can('tenant.shifts.show')
                                <a href="{{ url('/shifts/' . $shift->id) }}" class="btn btn-sm btn-dark">View</a>
                            @endcan
                        </td>
                    </tr>
                @endforeach
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">No shifts found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        <div class="mt-3">{{ $shifts->links() }}</div>
    </div>
</div>
@endsection
