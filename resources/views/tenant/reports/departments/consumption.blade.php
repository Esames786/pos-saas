@extends('layouts.app')

@section('title', 'Department Consumption Report')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Department Expected Consumption</h1>
        <p class="fw-medium text-muted mb-0">Existing branch stock movements grouped by the department expected to have consumed them.</p>
    </div>
    <a href="{{ url('/departments') }}" class="btn btn-light">Manage Departments</a>
</div>

<div class="card border-primary-subtle mb-3">
    <div class="card-body py-2 small">
        <i class="ti ti-info-circle me-1"></i>
        This report uses existing branch stock movements to estimate which department consumed stock.
        It does <strong>not</strong> create department stock balances yet.
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/reports/departments/consumption') }}" class="row g-3 align-items-end">
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
                <label for="movement_type" class="form-label">Movement Type</label>
                <select id="movement_type" name="movement_type" class="form-select">
                    <option value="">All consumption types</option>
                    @foreach($movementTypes as $type)
                        <option value="{{ $type }}" @selected($filters['movement_type'] === $type)>{{ ucwords(str_replace('_', ' ', $type)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <button class="btn btn-dark w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

@if($report['unassigned_count'] > 0)
    <div class="alert alert-warning small">
        <i class="ti ti-alert-triangle me-1"></i>
        <strong>{{ $report['unassigned_count'] }}</strong> consumed product/movement group(s) are not mapped to any department — shown as <em>Unassigned</em> below.
    </div>
@endif

<div class="row g-3 mb-3">
    {{-- Department summary --}}
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><strong>Department Expected Usage</strong></div>
            <div class="card-body table-responsive p-0">
                <table class="table table-nowrap align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Department</th>
                            <th scope="col">Branch</th>
                            <th scope="col" class="text-end">Qty Consumed</th>
                            <th scope="col" class="text-end">Usage Value (Cost)</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($report['summary'] as $row)
                        <tr>
                            <td>
                                @if($row['department'] === 'Unassigned')
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">Unassigned</span>
                                @else
                                    <span class="fw-semibold">{{ $row['department'] }}</span>
                                @endif
                            </td>
                            <td>{{ $branches->firstWhere('id', $row['branch_id'])?->name ?? ('#' . $row['branch_id']) }}</td>
                            <td class="text-end">{{ number_format($row['qty'], 3) }}</td>
                            <td class="text-end fw-semibold">{{ number_format($row['cost'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">No consumption movements found for the selected filters.</td></tr>
                    @endforelse
                    </tbody>
                    @if(!empty($report['summary']))
                    <tfoot class="table-light fw-semibold">
                        <tr>
                            <td colspan="2" class="text-end">Total</td>
                            <td class="text-end">{{ number_format(array_sum(array_column($report['summary'], 'qty')), 3) }}</td>
                            <td class="text-end">{{ number_format(array_sum(array_column($report['summary'], 'cost')), 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>

    {{-- Top consumed products --}}
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><strong>Top Consumed Products</strong></div>
            <div class="card-body table-responsive p-0">
                <table class="table table-nowrap align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">Department</th>
                            <th scope="col" class="text-end">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($report['top'] as $row)
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $row['product'] }}</span>
                                @if($row['sku'])<div class="small text-muted">{{ $row['sku'] }}</div>@endif
                            </td>
                            <td>{{ $row['department'] }}</td>
                            <td class="text-end">{{ number_format($row['cost'], 2) }}</td>
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

{{-- Movement detail --}}
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <strong>Movement Detail</strong>
        <span class="small text-muted">Latest {{ $report['line_limit'] }} matching movements (newest first).</span>
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Department</th>
                    <th scope="col">Product</th>
                    <th scope="col">SKU</th>
                    <th scope="col">Movement Type</th>
                    <th scope="col" class="text-end">Qty</th>
                    <th scope="col" class="text-end">Unit Cost</th>
                    <th scope="col" class="text-end">Total Cost</th>
                    <th scope="col">Reference</th>
                </tr>
            </thead>
            <tbody>
            @forelse($report['lines'] as $line)
                <tr>
                    <td>{{ $line->created_at?->format('Y-m-d H:i') }}</td>
                    <td>
                        @if($line->department_name === 'Unassigned')
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">Unassigned</span>
                        @else
                            {{ $line->department_name }}
                        @endif
                    </td>
                    <td>{{ $line->product?->name ?? ('#' . $line->product_id) }}</td>
                    <td><code>{{ $line->product?->sku ?? '—' }}</code></td>
                    <td><span class="badge bg-light text-dark border">{{ str_replace('_', ' ', $line->movement_type) }}</span></td>
                    <td class="text-end">{{ number_format($line->quantity, 3) }}</td>
                    <td class="text-end">{{ number_format($line->unit_cost, 4) }}</td>
                    <td class="text-end">{{ number_format($line->total_cost, 2) }}</td>
                    <td class="small text-muted">{{ $line->reference_no ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No movements found for the selected filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
