@extends('layouts.app')

@section('title', 'Sales Summary Report')

@section('content')
        <div class="page-header">
            <div class="page-title"><h4>Sales Summary</h4><h6>Daily breakdown of sales, discounts and collections</h6></div>
            <div class="page-btn d-flex gap-2">
                @can('tenant.reports.sales.items')
                <a href="{{ url('/reports/sales/items') }}" class="btn btn-outline-secondary btn-sm">By Item</a>
                @endcan
                @can('tenant.reports.sales.payments')
                <a href="{{ url('/reports/sales/payments') }}" class="btn btn-outline-secondary btn-sm">By Payment</a>
                @endcan
            </div>
        </div>

        @include('tenant.reports.partials.filters', ['showOrderType' => true, 'showCsvExport' => true])

        {{-- Totals banner --}}
        @php $t = $data['totals']; @endphp
        <div class="row g-3 mb-3">
            <div class="col-md-2 col-sm-4">
                <div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                    <div class="text-muted small">Orders</div>
                    <div class="fw-bold fs-5">{{ number_format($t->order_count) }}</div>
                </div></div>
            </div>
            <div class="col-md-2 col-sm-4">
                <div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                    <div class="text-muted small">Gross Sales</div>
                    <div class="fw-bold fs-5">{{ number_format($t->gross_sales, 2) }}</div>
                </div></div>
            </div>
            <div class="col-md-2 col-sm-4">
                <div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                    <div class="text-muted small">Discount</div>
                    <div class="fw-bold fs-5 text-danger">{{ number_format($t->total_discount, 2) }}</div>
                </div></div>
            </div>
            <div class="col-md-2 col-sm-4">
                <div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                    <div class="text-muted small">Tax</div>
                    <div class="fw-bold fs-5">{{ number_format($t->total_tax, 2) }}</div>
                </div></div>
            </div>
            @if((float) $t->total_service_charge > 0)
            <div class="col-md-2 col-sm-4">
                <div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                    <div class="text-muted small">Svc Charge</div>
                    <div class="fw-bold fs-5">{{ number_format($t->total_service_charge, 2) }}</div>
                </div></div>
            </div>
            @endif
            @if((float) $t->total_tips > 0)
            <div class="col-md-2 col-sm-4">
                <div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                    <div class="text-muted small">Tips</div>
                    <div class="fw-bold fs-5">{{ number_format($t->total_tips, 2) }}</div>
                </div></div>
            </div>
            @endif
            <div class="col-md-2 col-sm-4">
                <div class="card border-0 shadow-sm text-center bg-success bg-opacity-10"><div class="card-body py-2">
                    <div class="text-muted small">Net Sales</div>
                    <div class="fw-bold fs-5 text-success">{{ number_format($t->net_sales, 2) }}</div>
                </div></div>
            </div>
        </div>

        {{-- Daily table --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <caption class="visually-hidden">Daily sales summary</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col" class="text-end">Orders</th>
                                <th scope="col" class="text-end">Gross Sales</th>
                                <th scope="col" class="text-end">Discount</th>
                                <th scope="col" class="text-end">Tax</th>
                                <th scope="col" class="text-end">Svc Charge</th>
                                <th scope="col" class="text-end">Tips</th>
                                <th scope="col" class="text-end">Net Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($data['daily'] as $row)
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($row->sale_day)->format('D, d M Y') }}</td>
                                <td class="text-end">{{ number_format($row->order_count) }}</td>
                                <td class="text-end">{{ number_format($row->gross_sales, 2) }}</td>
                                <td class="text-end text-danger">{{ number_format($row->total_discount, 2) }}</td>
                                <td class="text-end">{{ number_format($row->total_tax, 2) }}</td>
                                <td class="text-end">{{ number_format($row->total_service_charge, 2) }}</td>
                                <td class="text-end">{{ number_format($row->total_tips, 2) }}</td>
                                <td class="text-end fw-semibold">{{ number_format($row->net_sales, 2) }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No sales in selected range.</td></tr>
                            @endforelse
                        </tbody>
                        @if($data['daily']->isNotEmpty())
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td>Total</td>
                                <td class="text-end">{{ number_format($t->order_count) }}</td>
                                <td class="text-end">{{ number_format($t->gross_sales, 2) }}</td>
                                <td class="text-end text-danger">{{ number_format($t->total_discount, 2) }}</td>
                                <td class="text-end">{{ number_format($t->total_tax, 2) }}</td>
                                <td class="text-end">{{ number_format($t->total_service_charge, 2) }}</td>
                                <td class="text-end">{{ number_format($t->total_tips, 2) }}</td>
                                <td class="text-end text-success">{{ number_format($t->net_sales, 2) }}</td>
                            </tr>
                        </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
@endsection
