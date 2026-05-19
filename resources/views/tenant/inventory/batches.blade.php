@extends('layouts.app')

@section('title', 'Inventory Batches')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Inventory Batches</h1>
        <p class="fw-medium">Batch and lot tracking by product.</p>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/inventory/batches') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="bt-branch" class="form-label">Branch</label>
                <select id="bt-branch" name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="bt-expiry" class="form-label">Expiry Status</label>
                <select id="bt-expiry" name="expiry_status" class="form-select">
                    <option value="">All</option>
                    <option value="expiring" @selected(request('expiry_status') === 'expiring')>Expiring (next 30 days)</option>
                    <option value="expired"  @selected(request('expiry_status') === 'expired')>Expired</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-dark">Filter</button>
                <a href="{{ url('/inventory/batches') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Inventory batches list</caption>
            <thead>
                <tr>
                    <th scope="col">Branch</th>
                    <th scope="col">Product</th>
                    <th scope="col">Batch No</th>
                    <th scope="col">Received</th>
                    <th scope="col">Expiry Date</th>
                    <th scope="col">Unit Cost</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
            @forelse($batches as $batch)
                @php
                    $isExpired  = $batch->expiry_date && $batch->expiry_date->isPast();
                    $isExpiring = !$isExpired && $batch->expiry_date && $batch->expiry_date->diffInDays(now()) <= 30;
                @endphp
                <tr class="{{ $isExpired ? 'table-danger' : ($isExpiring ? 'table-warning' : '') }}">
                    <td>{{ $batch->branch?->name ?? '—' }}</td>
                    <td>
                        {{ $batch->product?->name }}
                        @if($batch->variant)
                            <br><small class="text-muted">{{ $batch->variant->name }}</small>
                        @endif
                    </td>
                    <td><code>{{ $batch->batch_no ?? '—' }}</code></td>
                    <td>{{ $batch->received_date?->format('d M Y') ?? '—' }}</td>
                    <td>
                        @if($batch->expiry_date)
                            {{ $batch->expiry_date->format('d M Y') }}
                            @if($isExpired)
                                <span class="badge bg-danger ms-1">Expired</span>
                            @elseif($isExpiring)
                                <span class="badge bg-warning text-dark ms-1">Expiring</span>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ number_format($batch->unit_cost, 4) }}</td>
                    <td>
                        @if($batch->status === 'active')
                            <span class="badge bg-success">Active</span>
                        @elseif($batch->status === 'expired')
                            <span class="badge bg-danger">Expired</span>
                        @else
                            <span class="badge bg-secondary">Closed</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No batches found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $batches->links() }}</div>
    </div>
</div>
@endsection
