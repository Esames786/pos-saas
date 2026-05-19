@extends('layouts.app')

@section('title', 'Stock Balances')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Stock Balances</h1>
        <p class="fw-medium">Current on-hand quantities by product and branch.</p>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/inventory') }}" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label for="inv-search" class="form-label">Search</label>
                <input id="inv-search" type="text" name="search" value="{{ request('search') }}"
                       class="form-control" placeholder="SKU or product name">
            </div>
            <div class="col-md-3">
                <label for="inv-branch" class="form-label">Branch</label>
                <select id="inv-branch" name="branch_id" class="form-select">
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
                <a href="{{ url('/inventory') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Stock balances list</caption>
            <thead>
                <tr>
                    <th scope="col">Branch</th>
                    <th scope="col">SKU</th>
                    <th scope="col">Product</th>
                    <th scope="col">Variant</th>
                    <th scope="col">Batch</th>
                    <th scope="col">Qty on Hand</th>
                    <th scope="col">Avg Cost</th>
                    <th scope="col">Stock Value</th>
                </tr>
            </thead>
            <tbody>
            @forelse($balances as $balance)
                <tr>
                    <td>{{ $balance->branch?->name ?? '—' }}</td>
                    <td><code>{{ $balance->product?->sku }}</code></td>
                    <td>{{ $balance->product?->name }}</td>
                    <td>{{ $balance->variant?->name ?? '—' }}</td>
                    <td>{{ $balance->batch?->batch_no ?? '—' }}</td>
                    <td class="{{ $balance->quantity_on_hand < 0 ? 'text-danger fw-bold' : '' }}">
                        {{ number_format($balance->quantity_on_hand, 3) }}
                    </td>
                    <td>{{ number_format($balance->average_cost, 4) }}</td>
                    <td>{{ number_format($balance->quantity_on_hand * $balance->average_cost, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">No stock balances found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $balances->links() }}</div>
    </div>
</div>
@endsection
