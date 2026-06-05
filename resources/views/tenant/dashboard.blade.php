@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

        {{-- Header --}}
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h4 class="mb-1">{{ __('dashboard.tenant_panel') }}</h4>
                <p class="text-muted small mb-0">
                    Business: <strong class="text-primary">{{ app('tenant')->business_name }}</strong>
                    &nbsp;&mdash;&nbsp; {{ now()->format('l, d M Y') }}
                </p>
            </div>

            {{-- Branch filter --}}
            <form method="GET" action="{{ url('/dashboard') }}" class="d-flex gap-2 align-items-center">
                <select name="branch_id" class="form-select form-select-sm" style="min-width:160px" onchange="this.form.submit()">
                    <option value="">All Branches</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ $selectedBranch == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                    @endforeach
                </select>
            </form>
        </div>

        {{-- Alerts --}}
        @if($failedPrints > 0)
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-3">
            <i class="ti ti-printer fs-5"></i>
            <strong>{{ $failedPrints }} failed print job{{ $failedPrints > 1 ? 's' : '' }}</strong> in the last 24 hours.
            <a href="{{ url('/printing/jobs') }}?status=failed" class="ms-2 btn btn-sm btn-danger py-0">View &amp; Retry</a>
        </div>
        @endif

        @if($lowStockCount > 0)
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
            <i class="ti ti-alert-triangle fs-5"></i>
            <strong>{{ $lowStockCount }} product{{ $lowStockCount > 1 ? 's' : '' }}</strong> below reorder level.
            <a href="{{ url('/inventory/low-stock') }}" class="ms-2 btn btn-sm btn-warning py-0">View</a>
        </div>
        @endif

        {{-- Today KPI cards --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="bg-primary bg-opacity-10 rounded p-2"><i class="ti ti-currency-dollar text-primary fs-5"></i></span>
                            <span class="text-muted small">Net Sales Today</span>
                        </div>
                        <h4 class="mb-0 fw-bold">{{ number_format($today['net_sales'], 2) }}</h4>
                    </div>
                </div>
            </div>

            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="bg-success bg-opacity-10 rounded p-2"><i class="ti ti-shopping-cart text-success fs-5"></i></span>
                            <span class="text-muted small">Orders Today</span>
                        </div>
                        <h4 class="mb-0 fw-bold">{{ number_format($today['order_count']) }}</h4>
                    </div>
                </div>
            </div>

            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="bg-info bg-opacity-10 rounded p-2"><i class="ti ti-chart-line text-info fs-5"></i></span>
                            <span class="text-muted small">Avg Order Value</span>
                        </div>
                        <h4 class="mb-0 fw-bold">{{ number_format($today['avg_order_value'], 2) }}</h4>
                    </div>
                </div>
            </div>

            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="bg-warning bg-opacity-10 rounded p-2"><i class="ti ti-cash text-warning fs-5"></i></span>
                            <span class="text-muted small">Cash Today</span>
                        </div>
                        <h4 class="mb-0 fw-bold">{{ number_format($cashToday, 2) }}</h4>
                    </div>
                </div>
            </div>

            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="bg-secondary bg-opacity-10 rounded p-2"><i class="ti ti-credit-card text-secondary fs-5"></i></span>
                            <span class="text-muted small">Card/Bank Today</span>
                        </div>
                        <h4 class="mb-0 fw-bold">{{ number_format($cardToday, 2) }}</h4>
                    </div>
                </div>
            </div>

            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="bg-danger bg-opacity-10 rounded p-2"><i class="ti ti-tag text-danger fs-5"></i></span>
                            <span class="text-muted small">Discounts Today</span>
                        </div>
                        <h4 class="mb-0 fw-bold">{{ number_format($today['total_discount'], 2) }}</h4>
                    </div>
                </div>
            </div>
        </div>

        {{-- Row 2: extra stats --}}
        <div class="row g-3 mb-4">
            @if($today['total_service_charge'] > 0)
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm"><div class="card-body">
                    <span class="text-muted small">Service Charge</span>
                    <h5 class="mt-1 mb-0">{{ number_format($today['total_service_charge'], 2) }}</h5>
                </div></div>
            </div>
            @endif
            @if($today['total_tips'] > 0)
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm"><div class="card-body">
                    <span class="text-muted small">Tips</span>
                    <h5 class="mt-1 mb-0">{{ number_format($today['total_tips'], 2) }}</h5>
                </div></div>
            </div>
            @endif
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm"><div class="card-body">
                    <span class="text-muted small">Tax Collected</span>
                    <h5 class="mt-1 mb-0">{{ number_format($today['total_tax'], 2) }}</h5>
                </div></div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm"><div class="card-body">
                    <span class="text-muted small">Open Shifts</span>
                    <h5 class="mt-1 mb-0">{{ $openShifts }}</h5>
                </div></div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm @if($expiryCount > 0) border-warning @endif"><div class="card-body">
                    <span class="text-muted small">Expiry Alerts (30d)</span>
                    <h5 class="mt-1 mb-0 @if($expiryCount > 0) text-warning @endif">{{ $expiryCount }}</h5>
                </div></div>
            </div>
        </div>

        {{-- Bottom row: Top Products + 7-day sales --}}
        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 pb-0">
                        <h6 class="mb-0">Top 5 Products Today</h6>
                    </div>
                    <div class="card-body p-0">
                        @if($topProducts->isEmpty())
                            <p class="text-muted text-center py-4 small mb-0">No sales today.</p>
                        @else
                        <table class="table table-sm mb-0">
                            <caption class="visually-hidden">Top 5 products by quantity sold today</caption>
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Product</th>
                                    <th scope="col" class="text-end">Qty</th>
                                    <th scope="col" class="text-end">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($topProducts as $p)
                                <tr>
                                    <td>{{ $p->product_name }}</td>
                                    <td class="text-end">{{ number_format($p->qty_sold, 2) }}</td>
                                    <td class="text-end">{{ number_format($p->revenue, 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 pb-0">
                        <h6 class="mb-0">Last 7 Days — Net Sales</h6>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            <caption class="visually-hidden">Sales summary for last 7 days</caption>
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col" class="text-end">Orders</th>
                                    <th scope="col" class="text-end">Net Sales</th>
                                </tr>
                            </thead>
                            <tbody>
                                @for($i = 6; $i >= 0; $i--)
                                    @php $day = now()->subDays($i)->format('Y-m-d'); $row = $last7Days[$day] ?? null; @endphp
                                    <tr @if($day === today()->format('Y-m-d')) class="table-primary" @endif>
                                        <td>{{ \Carbon\Carbon::parse($day)->format('D, d M') }}</td>
                                        <td class="text-end">{{ $row ? number_format($row->orders) : '—' }}</td>
                                        <td class="text-end">{{ $row ? number_format($row->net_sales, 2) : '—' }}</td>
                                    </tr>
                                @endfor
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-white border-0 text-end">
                        <a href="{{ url('/reports/sales/summary') }}" class="small text-primary">View Sales Summary →</a>
                    </div>
                </div>
            </div>
        </div>

@endsection
