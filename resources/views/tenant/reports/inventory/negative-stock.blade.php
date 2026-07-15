@extends('layouts.app')

@section('title', 'Negative Stock Report')

@section('content')
        <div class="page-header">
            <div class="page-title"><h4>Negative Stock</h4><h6>Backorder balances and the movements that took stock below zero</h6></div>
            <div class="page-btn">
                <a href="{{ url('/reports/inventory/valuation') }}" class="btn btn-outline-secondary btn-sm">Valuation</a>
                <a href="{{ url('/reports/inventory/movements') }}" class="btn btn-outline-secondary btn-sm">Movements</a>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <form method="GET" action="" class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="filter-date-from">From (audit)</label>
                        <input type="date" id="filter-date-from" name="date_from" class="form-control form-control-sm"
                            value="{{ $filters['date_from'] }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="filter-date-to">To (audit)</label>
                        <input type="date" id="filter-date-to" name="date_to" class="form-control form-control-sm"
                            value="{{ $filters['date_to'] }}">
                    </div>
                    @include('tenant.partials.branch-multiselect', [
                        'branches'          => $branches ?? [],
                        'selectedBranchIds' => $selectedBranchIds ?? [],
                        'colClass'          => 'col-md-3',
                    ])
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="filter-movement-type">Movement Type</label>
                        <select id="filter-movement-type" name="movement_type" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            @foreach(['sale', 'recipe_consumption', 'modifier_consumption'] as $mt)
                                <option value="{{ $mt }}" @selected(($filters['movement_type'] ?? '') === $mt)>{{ ucfirst(str_replace('_', ' ', $mt)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                        <a href="{{ url()->current() }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                        <button type="submit" name="export_csv" value="1" class="btn btn-outline-success btn-sm">
                            <i class="ti ti-download me-1"></i>CSV
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-transparent fw-semibold">Current Negative Balances</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <caption class="visually-hidden">Current negative stock balances</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Branch</th>
                            <th scope="col">Product</th>
                            <th scope="col">Variant</th>
                            <th scope="col">Batch</th>
                            <th scope="col" class="text-end">Current Qty</th>
                            <th scope="col" class="text-end">Average Cost</th>
                            <th scope="col" class="text-end">Negative Value</th>
                            <th scope="col">Last Movement</th>
                            <th scope="col">Reference</th>
                            <th scope="col">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($balances as $row)
                        <tr>
                            <td>{{ $row['branch'] }}</td>
                            <td class="fw-medium">{{ $row['product'] }}</td>
                            <td>{{ $row['variant'] }}</td>
                            <td>{{ $row['batch'] }}</td>
                            <td class="text-end text-danger fw-semibold">{{ number_format($row['qty_on_hand'], 3) }}</td>
                            <td class="text-end">{{ number_format($row['average_cost'], 4) }}</td>
                            <td class="text-end text-danger">{{ number_format($row['negative_value'], 2) }}</td>
                            <td class="text-capitalize">{{ str_replace('_', ' ', $row['last_type']) }}</td>
                            <td>{{ $row['last_reference'] }}</td>
                            <td class="text-muted small">{{ $row['last_date'] }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="10" class="text-center text-muted py-4">No negative stock balances — all branches are clean.</td></tr>
                        @endforelse
                    </tbody>
                    @if($balances->isNotEmpty())
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="6">Total ({{ $balances->count() }} rows)</td>
                            <td class="text-end text-danger">{{ number_format($balances->sum('negative_value'), 2) }}</td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent fw-semibold">Negative-Crossing Movement Audit <span class="text-muted small fw-normal">(out-movements that left the balance below zero, latest 500)</span></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <caption class="visually-hidden">Movements that took stock negative</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Branch</th>
                            <th scope="col">Product</th>
                            <th scope="col">Variant</th>
                            <th scope="col">Type</th>
                            <th scope="col">Reference</th>
                            <th scope="col" class="text-end">Qty</th>
                            <th scope="col" class="text-end">Unit Cost</th>
                            <th scope="col" class="text-end">Balance After</th>
                            <th scope="col">By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($crossings as $row)
                        <tr>
                            <td class="text-muted small">{{ $row->created_at?->format('Y-m-d H:i') }}</td>
                            <td>{{ $row->branch?->name ?? '—' }}</td>
                            <td class="fw-medium">{{ $row->product?->name ?? $row->product_id }}</td>
                            <td>{{ $row->variant?->name ?? '—' }}</td>
                            <td class="text-capitalize">{{ str_replace('_', ' ', $row->movement_type) }}</td>
                            <td>{{ $row->reference_no ?? '—' }}</td>
                            <td class="text-end">{{ number_format((float) $row->quantity, 3) }}</td>
                            <td class="text-end">{{ number_format((float) $row->unit_cost, 4) }}</td>
                            <td class="text-end text-danger fw-semibold">{{ number_format((float) $row->balance_after, 3) }}</td>
                            <td>{{ $row->createdBy?->name ?? '—' }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="10" class="text-center text-muted py-4">No negative-crossing movements in the selected range.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-muted small mt-2 mb-0">
            COGS for items sold while stock was negative is an estimate at the last known average cost.
            Receive purchases promptly; margins for backorder periods are approximate.
        </p>
@endsection
