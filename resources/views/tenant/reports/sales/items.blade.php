@extends('layouts.app')

@section('title', 'Sales by Item Report')

@section('content')
<div class="page-wrapper">
    <div class="content">
        <div class="page-header">
            <div class="page-title"><h4>Sales by Item</h4><h6>Product-level sales breakdown</h6></div>
            <div class="page-btn">
                <a href="{{ url('/reports/sales/summary') }}" class="btn btn-outline-secondary btn-sm">Summary</a>
            </div>
        </div>

        @include('tenant.reports.partials.filters', ['showOrderType' => true, 'showCsvExport' => true])

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <caption class="visually-hidden">Sales by product</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Product</th>
                                <th scope="col">Variant</th>
                                <th scope="col">Category</th>
                                <th scope="col" class="text-end">Qty Sold</th>
                                <th scope="col" class="text-end">Gross</th>
                                <th scope="col" class="text-end">Discount</th>
                                <th scope="col" class="text-end">Tax</th>
                                <th scope="col" class="text-end">Net</th>
                                <th scope="col" class="text-end">Cost</th>
                                <th scope="col" class="text-end">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                            <tr>
                                <td>{{ $row->product_name }}</td>
                                <td class="text-muted small">{{ $row->variant_name ?: '—' }}</td>
                                <td class="text-muted small">{{ $row->category_name }}</td>
                                <td class="text-end">{{ number_format($row->qty_sold, 2) }}</td>
                                <td class="text-end">{{ number_format($row->gross_amount, 2) }}</td>
                                <td class="text-end text-danger">{{ number_format($row->total_discount, 2) }}</td>
                                <td class="text-end">{{ number_format($row->total_tax, 2) }}</td>
                                <td class="text-end fw-semibold">{{ number_format($row->net_amount, 2) }}</td>
                                <td class="text-end text-muted">{{ number_format($row->total_cost, 2) }}</td>
                                <td class="text-end @if($row->profit > 0) text-success @elseif($row->profit < 0) text-danger @endif">
                                    {{ number_format($row->profit, 2) }}
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="10" class="text-center text-muted py-4">No sales in selected range.</td></tr>
                            @endforelse
                        </tbody>
                        @if($rows->isNotEmpty())
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="3">Total</td>
                                <td class="text-end">{{ number_format($rows->sum('qty_sold'), 2) }}</td>
                                <td class="text-end">{{ number_format($rows->sum('gross_amount'), 2) }}</td>
                                <td class="text-end text-danger">{{ number_format($rows->sum('total_discount'), 2) }}</td>
                                <td class="text-end">{{ number_format($rows->sum('total_tax'), 2) }}</td>
                                <td class="text-end">{{ number_format($rows->sum('net_amount'), 2) }}</td>
                                <td class="text-end">{{ number_format($rows->sum('total_cost'), 2) }}</td>
                                <td class="text-end text-success">{{ number_format($rows->sum('profit'), 2) }}</td>
                            </tr>
                        </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
