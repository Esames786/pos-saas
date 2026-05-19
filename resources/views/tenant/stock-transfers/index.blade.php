@extends('layouts.app')

@section('title', 'Stock Transfers')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Stock Transfers</h1>
        <p class="fw-medium">Move inventory between branches.</p>
    </div>
    @can('tenant.stock-transfers.create')
        <a href="{{ url('/stock-transfers/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1"></i>New Transfer
        </a>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/stock-transfers') }}" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="st-branch" class="form-label">Branch (From or To)</label>
                <select id="st-branch" name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-dark">Filter</button>
                <a href="{{ url('/stock-transfers') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Stock transfers list</caption>
            <thead>
                <tr>
                    <th scope="col">Transfer No</th>
                    <th scope="col">From</th>
                    <th scope="col">To</th>
                    <th scope="col">Date</th>
                    <th scope="col">Status</th>
                    <th scope="col">Posted By</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($transfers as $transfer)
                <tr>
                    <td><code>{{ $transfer->transfer_no }}</code></td>
                    <td>{{ $transfer->fromBranch?->name }}</td>
                    <td>{{ $transfer->toBranch?->name }}</td>
                    <td>{{ $transfer->transfer_date->format('d M Y') }}</td>
                    <td>
                        <span class="badge bg-{{ $transfer->status === 'posted' ? 'success' : 'secondary' }}">
                            {{ ucfirst($transfer->status) }}
                        </span>
                    </td>
                    <td>{{ $transfer->postedBy?->name ?? '—' }}</td>
                    <td class="text-end">
                        @can('tenant.stock-transfers.show')
                            <a href="{{ url('/stock-transfers/' . $transfer->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No transfers found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $transfers->links() }}</div>
    </div>
</div>
@endsection
