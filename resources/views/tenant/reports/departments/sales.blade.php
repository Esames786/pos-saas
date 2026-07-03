@extends('layouts.app')

@section('title', 'Department Sales Report')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Department Sales</h1>
        <p class="fw-medium text-muted mb-0">Paid sales grouped into departments via category mappings and product overrides.</p>
    </div>
    <a href="{{ url('/departments') }}" class="btn btn-light">Manage Departments</a>
</div>

<div class="card border-primary-subtle mb-3">
    <div class="card-body py-2 small">
        <i class="ti ti-info-circle me-1"></i>
        This report groups sales into departments using category mappings and product overrides.
        It does <strong>not</strong> change stock or accounting.
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/reports/departments/sales') }}" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label for="date_from" class="form-label">Date From</label>
                <input id="date_from" type="date" name="date_from" value="{{ $filters['date_from'] }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label for="date_to" class="form-label">Date To</label>
                <input id="date_to" type="date" name="date_to" value="{{ $filters['date_to'] }}" class="form-control">
            </div>
            <div class="col-md-2">
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
                        <option value="{{ $dept->id }}" @selected($filters['department_id'] == $dept->id)>
                            {{ $dept->name }} ({{ $dept->branch?->name }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="order_type" class="form-label">Order Type</label>
                <select id="order_type" name="order_type" class="form-select">
                    <option value="">All types</option>
                    @foreach($orderTypes as $type)
                        <option value="{{ $type }}" @selected($filters['order_type'] === $type)>{{ ucwords(str_replace('_', ' ', $type)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <button class="btn btn-dark w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

@if(!empty($report['warnings']))
    <div class="alert alert-warning small">
        <i class="ti ti-alert-triangle me-1"></i>
        <strong>Setup warning:</strong> {{ count($report['warnings']) }} product(s) match <em>multiple</em> departments — the first department by sort order was used.
        <span class="text-muted">
            ({{ collect($report['warnings'])->take(5)->map(fn ($w) => ($w['sku'] ? $w['sku'] . ' ' : '') . $w['product'] . ' → ' . $w['department'])->implode('; ') }}{{ count($report['warnings']) > 5 ? '…' : '' }})
        </span>
    </div>
@endif

<div class="card mb-3">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Department-wise sales</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col">Department</th>
                    <th scope="col">Branch</th>
                    <th scope="col" class="text-end">Orders</th>
                    <th scope="col" class="text-end">Qty Sold</th>
                    <th scope="col" class="text-end">Gross Sales</th>
                    <th scope="col" class="text-end">Discount</th>
                    <th scope="col" class="text-end">Net Sales</th>
                    <th scope="col" class="text-end">COGS</th>
                    <th scope="col" class="text-end">Gross Profit</th>
                </tr>
            </thead>
            <tbody>
            @forelse($report['rows'] as $row)
                <tr>
                    <td>
                        @if($row['department'] === 'Unassigned')
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">Unassigned</span>
                        @else
                            <span class="fw-semibold">{{ $row['department'] }}</span>
                        @endif
                    </td>
                    <td>{{ $branches->firstWhere('id', $row['branch_id'])?->name ?? ('#' . $row['branch_id']) }}</td>
                    <td class="text-end">{{ number_format($row['orders']) }}</td>
                    <td class="text-end">{{ number_format($row['qty'], 2) }}</td>
                    <td class="text-end">{{ number_format($row['gross'], 2) }}</td>
                    <td class="text-end">{{ number_format($row['discount'], 2) }}</td>
                    <td class="text-end fw-semibold">{{ number_format($row['net'], 2) }}</td>
                    <td class="text-end">{{ number_format($row['cogs'], 2) }}</td>
                    <td class="text-end {{ $row['gross_profit'] < 0 ? 'text-danger' : 'text-success' }}">{{ number_format($row['gross_profit'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No paid sales found for the selected filters.</td></tr>
            @endforelse
            </tbody>
            @if(!empty($report['rows']))
            <tfoot class="table-light fw-semibold">
                <tr>
                    <td colspan="3" class="text-end">Total</td>
                    <td class="text-end">{{ number_format($report['totals']['qty'], 2) }}</td>
                    <td class="text-end">{{ number_format($report['totals']['gross'], 2) }}</td>
                    <td class="text-end">{{ number_format($report['totals']['discount'], 2) }}</td>
                    <td class="text-end">{{ number_format($report['totals']['net'], 2) }}</td>
                    <td class="text-end">{{ number_format($report['totals']['cogs'], 2) }}</td>
                    <td class="text-end">{{ number_format($report['totals']['gross_profit'], 2) }}</td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>

{{-- Unassigned products — setup QA --}}
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <strong>Unassigned Products</strong>
        <span class="small text-muted">Products sold but not mapped to any department — map them via Departments setup.</span>
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th scope="col">SKU</th>
                    <th scope="col">Product</th>
                    <th scope="col">Branch</th>
                    <th scope="col" class="text-end">Qty Sold</th>
                    <th scope="col" class="text-end">Net Sales</th>
                </tr>
            </thead>
            <tbody>
            @forelse($report['unassigned'] as $row)
                <tr>
                    <td><code>{{ $row['sku'] ?? '—' }}</code></td>
                    <td>{{ $row['product'] }}</td>
                    <td>{{ $branches->firstWhere('id', $row['branch_id'])?->name ?? ('#' . $row['branch_id']) }}</td>
                    <td class="text-end">{{ number_format($row['qty'], 2) }}</td>
                    <td class="text-end">{{ number_format($row['net'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-3"><i class="ti ti-circle-check me-1"></i>All sold products are mapped to a department.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
