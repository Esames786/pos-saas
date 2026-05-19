@extends('layouts.app')

@section('title', 'Stock Movements')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Stock Movements</h1>
        <p class="fw-medium">Full ledger of all inventory movements.</p>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/inventory/movements') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="mv-branch" class="form-label">Branch</label>
                <select id="mv-branch" name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="mv-type" class="form-label">Movement Type</label>
                <select id="mv-type" name="movement_type" class="form-select">
                    <option value="">All Types</option>
                    @foreach([
                        'opening_stock'     => 'Opening Stock',
                        'adjustment_in'     => 'Adjustment In',
                        'adjustment_out'    => 'Adjustment Out',
                        'wastage'           => 'Wastage',
                        'transfer_in'       => 'Transfer In',
                        'transfer_out'      => 'Transfer Out',
                        'purchase'          => 'Purchase',
                        'purchase_return'   => 'Purchase Return',
                        'sale'              => 'Sale',
                        'sale_return'       => 'Sale Return',
                        'recipe_consumption'=> 'Recipe Consumption',
                        'production_in'     => 'Production In',
                    ] as $val => $label)
                        <option value="{{ $val }}" @selected(request('movement_type') === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-dark">Filter</button>
                <a href="{{ url('/inventory/movements') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Stock movement ledger</caption>
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Branch</th>
                    <th scope="col">Product</th>
                    <th scope="col">Type</th>
                    <th scope="col">Direction</th>
                    <th scope="col">Qty</th>
                    <th scope="col">Unit Cost</th>
                    <th scope="col">Balance After</th>
                    <th scope="col">Reference</th>
                    <th scope="col">By</th>
                </tr>
            </thead>
            <tbody>
            @forelse($ledgers as $ledger)
                <tr>
                    <td>{{ $ledger->created_at->format('d M Y H:i') }}</td>
                    <td>{{ $ledger->branch?->name ?? '—' }}</td>
                    <td>
                        {{ $ledger->product?->name }}
                        @if($ledger->variant && !$ledger->variant->is_default)
                            <br><small class="text-muted">{{ $ledger->variant->name }}</small>
                        @endif
                    </td>
                    <td><span class="badge bg-light text-dark">{{ str_replace('_', ' ', ucfirst($ledger->movement_type)) }}</span></td>
                    <td>
                        @if($ledger->direction === 'in')
                            <span class="badge bg-success">In</span>
                        @else
                            <span class="badge bg-danger">Out</span>
                        @endif
                    </td>
                    <td>{{ number_format($ledger->quantity, 3) }}</td>
                    <td>{{ number_format($ledger->unit_cost, 4) }}</td>
                    <td>{{ number_format($ledger->balance_after, 3) }}</td>
                    <td>
                        @if($ledger->reference_no)
                            <code>{{ $ledger->reference_no }}</code>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $ledger->createdBy?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="text-center text-muted py-4">No movements found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $ledgers->links() }}</div>
    </div>
</div>
@endsection
