@extends('layouts.app')

@section('title', 'Expiry Alerts')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Expiry Alerts</h1>
        <p class="fw-medium">Batches expiring within the selected window.</p>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/inventory/expiry-alerts') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="ea-days" class="form-label">Show expiring within (days)</label>
                <input id="ea-days" type="number" name="days" min="1" max="365"
                       value="{{ $days }}" class="form-control">
            </div>
            <div class="col-md-2">
                <button class="btn btn-dark">Apply</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Expiring batches</caption>
            <thead>
                <tr>
                    <th scope="col">Branch</th>
                    <th scope="col">Product</th>
                    <th scope="col">Batch No</th>
                    <th scope="col">Expiry Date</th>
                    <th scope="col">Days Left</th>
                    <th scope="col">Qty on Hand</th>
                </tr>
            </thead>
            <tbody>
            @forelse($batches as $batch)
                @php
                    $daysLeft = now()->diffInDays($batch->expiry_date, false);
                    $isExpired = $daysLeft < 0;
                    $qtyOnHand = $batch->balances->sum('quantity_on_hand');
                @endphp
                <tr class="{{ $isExpired ? 'table-danger' : ($daysLeft <= 7 ? 'table-warning' : '') }}">
                    <td>{{ $batch->branch?->name ?? '—' }}</td>
                    <td>
                        {{ $batch->product?->name }}
                        @if($batch->variant)
                            <br><small class="text-muted">{{ $batch->variant->name }}</small>
                        @endif
                    </td>
                    <td><code>{{ $batch->batch_no ?? '—' }}</code></td>
                    <td>{{ $batch->expiry_date->format('d M Y') }}</td>
                    <td>
                        @if($isExpired)
                            <span class="badge bg-danger">Expired {{ abs($daysLeft) }}d ago</span>
                        @elseif($daysLeft === 0)
                            <span class="badge bg-danger">Today</span>
                        @else
                            <span class="badge bg-{{ $daysLeft <= 7 ? 'warning text-dark' : 'info' }}">
                                {{ $daysLeft }} days
                            </span>
                        @endif
                    </td>
                    <td>{{ number_format($qtyOnHand, 3) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        No batches expiring within {{ $days }} days.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $batches->links() }}</div>
    </div>
</div>
@endsection
