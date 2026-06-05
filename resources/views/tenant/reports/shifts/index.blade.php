@extends('layouts.app')

@section('title', 'Shift Report')

@section('content')
<div class="page-wrapper">
    <div class="content">
        <div class="page-header">
            <div class="page-title"><h4>Shift Report</h4><h6>Cash, sales and variance by shift</h6></div>
        </div>

        {{-- Filters --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <form method="GET" action="{{ url('/reports/shifts') }}" class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="sf-from">From</label>
                        <input type="date" id="sf-from" name="date_from" class="form-control form-control-sm"
                            value="{{ $filters['date_from'] }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="sf-to">To</label>
                        <input type="date" id="sf-to" name="date_to" class="form-control form-control-sm"
                            value="{{ $filters['date_to'] }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="sf-branch">Branch</label>
                        <select id="sf-branch" name="branch_id" class="form-select form-select-sm">
                            <option value="">All Branches</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ $filters['branch_id'] == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="sf-status">Status</label>
                        <select id="sf-status" name="status" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="open"   {{ ($filters['status'] ?? '') === 'open'   ? 'selected' : '' }}>Open</option>
                            <option value="closed" {{ ($filters['status'] ?? '') === 'closed' ? 'selected' : '' }}>Closed</option>
                        </select>
                    </div>
                    <div class="col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                        <a href="{{ url('/reports/shifts') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        {{-- Summary cards --}}
        <div class="row g-3 mb-3">
            <div class="col-md-2 col-sm-4">
                <div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                    <div class="text-muted small">Shifts</div>
                    <div class="fw-bold fs-5">{{ number_format($totals->shift_count) }}</div>
                </div></div>
            </div>
            <div class="col-md-2 col-sm-4">
                <div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                    <div class="text-muted small">Total Sales</div>
                    <div class="fw-bold fs-5">{{ number_format($totals->total_sales, 2) }}</div>
                </div></div>
            </div>
            <div class="col-md-2 col-sm-4">
                <div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                    <div class="text-muted small">Cash</div>
                    <div class="fw-bold fs-5">{{ number_format($totals->total_cash, 2) }}</div>
                </div></div>
            </div>
            <div class="col-md-2 col-sm-4">
                <div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                    <div class="text-muted small">Refunds</div>
                    <div class="fw-bold fs-5 text-danger">{{ number_format($totals->total_refunds, 2) }}</div>
                </div></div>
            </div>
            <div class="col-md-2 col-sm-4">
                <div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                    <div class="text-muted small">Discounts</div>
                    <div class="fw-bold fs-5 text-warning">{{ number_format($totals->total_discount, 2) }}</div>
                </div></div>
            </div>
            <div class="col-md-2 col-sm-4">
                <div class="card border-0 shadow-sm text-center @if(abs($totals->total_variance) > 0.01) border-warning @endif">
                    <div class="card-body py-2">
                        <div class="text-muted small">Cash Variance</div>
                        <div class="fw-bold fs-5 @if(abs($totals->total_variance) > 0.01) text-danger @endif">
                            {{ number_format($totals->total_variance, 2) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Shift table --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <caption class="visually-hidden">Shift list</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Shift</th>
                                <th scope="col">Branch</th>
                                <th scope="col">Terminal</th>
                                <th scope="col">Opened By</th>
                                <th scope="col">Opened At</th>
                                <th scope="col">Closed At</th>
                                <th scope="col" class="text-end">Sales</th>
                                <th scope="col" class="text-end">Cash</th>
                                <th scope="col" class="text-end">Refunds</th>
                                <th scope="col" class="text-end">Expected Cash</th>
                                <th scope="col" class="text-end">Counted</th>
                                <th scope="col" class="text-end">Variance</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($shifts as $shift)
                            <tr>
                                <td><code class="small">#{{ $shift->id }}</code></td>
                                <td>{{ $shift->branch?->name }}</td>
                                <td>{{ $shift->terminal?->name ?? '—' }}</td>
                                <td>{{ $shift->openedBy?->name ?? '—' }}</td>
                                <td>{{ $shift->opened_at?->format('d/m/Y H:i') }}</td>
                                <td>{{ $shift->closed_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="text-end">{{ number_format($shift->total_sales, 2) }}</td>
                                <td class="text-end">{{ number_format($shift->total_cash, 2) }}</td>
                                <td class="text-end text-danger">{{ number_format($shift->total_refunds, 2) }}</td>
                                <td class="text-end">{{ number_format($shift->expected_cash, 2) }}</td>
                                <td class="text-end">{{ $shift->counted_cash !== null ? number_format($shift->counted_cash, 2) : '—' }}</td>
                                <td class="text-end @if(abs($shift->cash_variance ?? 0) > 0.01) text-danger fw-semibold @endif">
                                    {{ $shift->cash_variance !== null ? number_format($shift->cash_variance, 2) : '—' }}
                                </td>
                                <td>
                                    <span class="badge bg-{{ $shift->status === 'open' ? 'success' : 'secondary' }}">
                                        {{ ucfirst($shift->status) }}
                                    </span>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="13" class="text-center text-muted py-4">No shifts in selected range.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-3">{{ $shifts->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
