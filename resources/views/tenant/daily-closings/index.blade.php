@extends('layouts.app')

@section('title', 'Daily Closings')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Daily Closings</h1>
        <p class="fw-medium">End-of-day cash reconciliation per branch.</p>
    </div>

    @can('tenant.daily-closings.create')
        <a href="{{ url('/daily-closings/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>New Daily Closing
        </a>
    @endcan
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/daily-closings') }}" class="row g-3 align-items-end">
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
            <div class="col-md-2">
                <button class="btn btn-dark" type="submit">Filter</button>
                <a href="{{ url('/daily-closings') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption>Daily closing history</caption>
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Branch</th>
                    <th scope="col">Total Sales</th>
                    <th scope="col">Expected Cash</th>
                    <th scope="col">Counted Cash</th>
                    <th scope="col">Variance</th>
                    <th scope="col">Closed By</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($closings as $closing)
                <tr>
                    <td>{{ $closing->closing_date->format('Y-m-d') }}</td>
                    <td>{{ $closing->branch?->name }}</td>
                    <td>{{ number_format($closing->total_sales, 2) }}</td>
                    <td>{{ number_format($closing->expected_cash, 2) }}</td>
                    <td>{{ $closing->counted_cash !== null ? number_format($closing->counted_cash, 2) : '—' }}</td>
                    <td>
                        @if($closing->cash_variance !== null)
                            <span class="{{ $closing->cash_variance < 0 ? 'text-danger' : ($closing->cash_variance > 0 ? 'text-warning' : 'text-success') }}">
                                {{ number_format($closing->cash_variance, 2) }}
                            </span>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $closing->closedBy?->name }}</td>
                    <td>
                        @if($closing->status === 'approved')
                            <span class="badge bg-success">Approved</span>
                        @else
                            <span class="badge bg-secondary">Closed</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <div class="action-toolbar justify-content-end">
                            @can('tenant.daily-closings.show')
                                <a href="{{ url('/daily-closings/' . $closing->id) }}" class="btn btn-sm btn-dark">View</a>
                            @endcan

                            @if($closing->status === 'closed')
                                @can('tenant.daily-closings.approve')
                                    <form method="POST"
                                          action="{{ url('/daily-closings/' . $closing->id . '/approve') }}"
                                          class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                    </form>
                                @endcan
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">No daily closings found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        <div class="mt-3">{{ $closings->links() }}</div>
    </div>
</div>
@endsection
