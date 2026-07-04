@extends('layouts.app')

@section('title', 'Department Stock Report')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Department Stock Available</h1>
        <p class="fw-medium text-muted mb-0">Custody balances per department — a sub-ledger of official branch stock.</p>
    </div>
    <a href="{{ url('/department-stock') }}" class="btn btn-light">Manage Department Stock</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/reports/departments/stock') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="branch_id" class="form-label">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($filters['branch_id'] == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="department_id" class="form-label">Department</label>
                <select id="department_id" name="department_id" class="form-select">
                    <option value="">All departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}" @selected($filters['department_id'] == $dept->id)>{{ $dept->name }} ({{ $dept->branch?->name }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="nonzero" value="1" id="nonzero" @checked($filters['nonzero'])>
                    <label class="form-check-label" for="nonzero">Only non-zero</label>
                </div>
            </div>
            <div class="col-md-1"><button class="btn btn-dark w-100">Filter</button></div>
            <div class="col-md-3 text-md-end">
                <div class="text-muted small">Total Custody Value</div>
                <div class="fw-bold fs-4">{{ number_format($report['total_value'], 2) }}</div>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><strong>Department-wise Totals</strong></div>
            <div class="card-body table-responsive p-0">
                <table class="table table-nowrap align-middle mb-0">
                    <thead class="table-light"><tr><th>Branch</th><th>Department</th><th class="text-end">Qty</th><th class="text-end">Value</th></tr></thead>
                    <tbody>
                    @forelse($report['dept_totals'] as $row)
                        <tr>
                            <td>{{ $row['branch'] }}</td>
                            <td class="fw-semibold">{{ $row['department'] }}</td>
                            <td class="text-end">{{ number_format($row['qty'], 3) }}</td>
                            <td class="text-end fw-semibold">{{ number_format($row['value'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">No custody stock.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><strong>Top Products by Custody Value</strong></div>
            <div class="card-body table-responsive p-0">
                <table class="table table-nowrap align-middle mb-0">
                    <thead class="table-light"><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Value</th></tr></thead>
                    <tbody>
                    @forelse($report['product_totals'] as $row)
                        <tr>
                            <td>{{ $row['product'] }} @if($row['sku'])<span class="text-muted small">({{ $row['sku'] }})</span>@endif</td>
                            <td class="text-end">{{ number_format($row['qty'], 3) }}</td>
                            <td class="text-end fw-semibold">{{ number_format($row['value'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-3">No data.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Balances Detail</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Branch</th><th>Department</th><th>Product</th><th>SKU</th>
                    <th class="text-end">Quantity</th><th class="text-end">Avg Cost</th><th class="text-end">Stock Value</th>
                </tr>
            </thead>
            <tbody>
            @forelse($report['rows'] as $row)
                <tr>
                    <td>{{ $row->branch?->name }}</td>
                    <td class="fw-semibold">{{ $row->department?->name }}</td>
                    <td>{{ $row->product?->name }}</td>
                    <td><code>{{ $row->product?->sku }}</code></td>
                    <td class="text-end">{{ number_format($row->quantity_on_hand, 3) }} {{ $row->product?->unit?->code }}</td>
                    <td class="text-end">{{ number_format($row->average_cost, 4) }}</td>
                    <td class="text-end fw-semibold">{{ number_format((float) $row->quantity_on_hand * (float) $row->average_cost, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No department custody stock for the selected filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
