@extends('layouts.app')

@section('title', 'Shifts')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Shifts</h1>
        <p class="fw-medium">View and manage terminal shifts.</p>
    </div>

    @can('tenant.shifts.create')
        <a href="{{ url('/shifts/open') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>Open Shift
        </a>
    @endcan
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
            <caption>Shift history</caption>
            <thead>
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">Branch / Terminal</th>
                    <th scope="col">Opened By</th>
                    <th scope="col">Opened At</th>
                    <th scope="col">Closed At</th>
                    <th scope="col">Opening Cash</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($shifts as $shift)
                <tr>
                    <td>{{ $shift->id }}</td>
                    <td>
                        <strong>{{ $shift->branch?->name }}</strong>
                        <small class="d-block text-muted">{{ $shift->terminal?->name }}</small>
                    </td>
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
                        <div class="action-toolbar justify-content-end">
                            @can('tenant.shifts.show')
                                <a href="{{ url('/shifts/' . $shift->id) }}" class="btn btn-sm btn-dark">View</a>
                            @endcan

                            @if($shift->status === 'open')
                                @can('tenant.shifts.close-form')
                                    <a href="{{ url('/shifts/' . $shift->id . '/close') }}" class="btn btn-sm btn-danger">
                                        Close
                                    </a>
                                @endcan
                            @endif
                        </div>
                    </td>
                </tr>
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
