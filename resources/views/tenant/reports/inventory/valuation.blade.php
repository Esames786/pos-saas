@extends('layouts.app')

@section('title', 'Stock Valuation Report')

@section('content')
        <div class="page-header">
            <div class="page-title"><h4>Stock Valuation</h4><h6>Current inventory value by product</h6></div>
            <div class="page-btn">
                @can('tenant.reports.inventory.movements')
                <a href="{{ url('/reports/inventory/movements') }}" class="btn btn-outline-secondary btn-sm">Movements</a>
                @endcan
                @can('tenant.reports.inventory.negative-stock')
                <a href="{{ url('/reports/inventory/negative-stock') }}" class="btn btn-outline-warning btn-sm">Negative Stock</a>
                @endcan
            </div>
        </div>

        {{-- Filters --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <form method="GET" action="{{ url('/reports/inventory/valuation') }}" class="row g-2 align-items-end">
                    @include('tenant.partials.branch-multiselect', [
                        'branches'          => $branches,
                        'selectedBranchIds' => $selectedBranchIds ?? [],
                        'colClass'          => 'col-md-3',
                    ])
                    <div class="col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                        <a href="{{ url('/reports/inventory/valuation') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                        <button type="submit" name="export_csv" value="1" class="btn btn-outline-success btn-sm">
                            <i class="ti ti-download me-1"></i>CSV
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Totals --}}
        <div class="row g-3 mb-3">
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                    <div class="text-muted small">Products in Stock</div>
                    <div class="fw-bold fs-5">{{ number_format($totals['total_items']) }}</div>
                </div></div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm text-center bg-success bg-opacity-10"><div class="card-body py-2">
                    <div class="text-muted small">Total Stock Value</div>
                    <div class="fw-bold fs-5 text-success">{{ number_format($totals['total_value'], 2) }}</div>
                </div></div>
            </div>
        </div>

        {{-- Table --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <caption class="visually-hidden">Stock valuation by product</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Branch</th>
                                <th scope="col">Product</th>
                                <th scope="col">Variant</th>
                                <th scope="col">SKU</th>
                                <th scope="col">Category</th>
                                <th scope="col" class="text-end">Qty On Hand</th>
                                <th scope="col" class="text-end">Avg Cost</th>
                                <th scope="col" class="text-end">Total Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                            <tr>
                                <td class="text-muted small">{{ $row['branch'] }}</td>
                                <td>{{ $row['product'] }}</td>
                                <td class="text-muted small">{{ $row['variant'] !== '—' ? $row['variant'] : '' }}</td>
                                <td class="text-muted small"><code>{{ $row['sku'] }}</code></td>
                                <td class="text-muted small">{{ $row['category'] }}</td>
                                <td class="text-end">{{ number_format($row['qty_on_hand'], 3) }}</td>
                                <td class="text-end text-muted">{{ number_format($row['average_cost'], 4) }}</td>
                                <td class="text-end fw-semibold">{{ number_format($row['total_value'], 2) }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No stock on hand.</td></tr>
                            @endforelse
                        </tbody>
                        @if($rows->isNotEmpty())
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="7" class="text-end">Total Value</td>
                                <td class="text-end text-success">{{ number_format($totals['total_value'], 2) }}</td>
                            </tr>
                        </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
@endsection
