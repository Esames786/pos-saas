@extends('layouts.app')

@section('title', 'Profit & Loss Statement')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Profit &amp; Loss Statement</h4>
                <h6>{{ $pl['period']['from'] }} to {{ $pl['period']['to'] }}</h6>
            </div>
            <div class="page-btn">
                <form method="GET" class="d-inline">
                    @foreach($filters as $k => $val)
                        @if(is_array($val))
                            @foreach($val as $item)
                                @if($item !== null && $item !== '')<input type="hidden" name="{{ $k }}[]" value="{{ $item }}">@endif
                            @endforeach
                        @else
                            @if($val !== null && $val !== '')<input type="hidden" name="{{ $k }}" value="{{ $val }}">@endif
                        @endif
                    @endforeach
                    <button type="submit" name="export_csv" value="1" class="btn btn-outline-success btn-sm"><i class="ti ti-download me-1"></i>CSV</button>
                    {{-- FINANCE-PDF-DOWNLOADS-1: the same statement, heads and detail as this screen, as an A4 PDF. --}}
                    <button type="submit" name="format" value="pdf" id="pl-pdf" class="btn btn-outline-danger btn-sm"><i class="ti ti-file-type-pdf me-1"></i>PDF</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-sm-3">
                        <label class="form-label mb-1">From</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] }}">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label mb-1">To</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] }}">
                    </div>
                    @include('tenant.finance.partials.branch-multiselect', ['branches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                    {{-- PNL-DETAIL-1: what each expense is made of (categories, suppliers). On by default; unticked sends 0. --}}
                    <div class="col-sm-2">
                        <input type="hidden" name="detail" value="0">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="detail" value="1" id="pl-detail" @checked($report['detail'])>
                            <label class="form-check-label" for="pl-detail">Show detail</label>
                        </div>
                    </div>
                    <div class="col-sm-2">
                        <button type="submit" class="btn btn-primary w-100">Apply</button>
                    </div>
                </form>
            </div>
        </div>

        @php $netProfit = $pl['net_profit']; @endphp

        <div class="row">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-0">
                        <table class="table mb-0">
                            <caption class="visually-hidden">Profit and loss statement</caption>
                            <tbody>
                                {{-- Revenue --}}
                                {{-- PNL-DETAIL-1: each part as ProfitLossReport laid it out — parents over children, detail
                                     under each cost / expense account. The totals below are statement()'s, unchanged. --}}
                                <tr class="table-light"><th colspan="2">Revenue</th></tr>
                                @include('tenant.finance.profit-loss.partials.lines', ['lines' => $report['parts']['revenue'], 'empty' => 'No revenue in period'])
                                <tr class="fw-semibold"><td class="ps-4">Gross Revenue</td><td class="text-end">{{ number_format($pl['gross_revenue'], 2) }}</td></tr>

                                @include('tenant.finance.profit-loss.partials.lines', ['lines' => $report['parts']['discounts'], 'less' => true])
                                <tr class="fw-semibold border-top"><td class="ps-4">Net Revenue</td><td class="text-end">{{ number_format($pl['net_revenue'], 2) }}</td></tr>

                                {{-- COGS --}}
                                <tr class="table-light"><th colspan="2">Cost of Sales</th></tr>
                                @include('tenant.finance.profit-loss.partials.lines', ['lines' => $report['parts']['cost_of_sales'], 'empty' => 'No cost of sales in period'])
                                <tr class="fw-semibold"><td class="ps-4">Total Cost of Sales</td><td class="text-end">{{ number_format($pl['total_cogs'], 2) }}</td></tr>

                                {{-- Gross Profit --}}
                                <tr class="fw-bold border-top">
                                    <td class="ps-4">Gross Profit @if($pl['net_revenue'] > 0)<small class="text-muted">({{ number_format($pl['gross_margin_percent'], 1) }}%)</small>@endif</td>
                                    <td class="text-end">{{ number_format($pl['gross_profit'], 2) }}</td>
                                </tr>

                                {{-- Operating Expenses --}}
                                <tr class="table-light"><th colspan="2">Operating Expenses</th></tr>
                                @include('tenant.finance.profit-loss.partials.lines', ['lines' => $report['parts']['expenses'], 'empty' => 'No operating expenses in period'])
                                <tr class="fw-semibold"><td class="ps-4">Total Operating Expenses</td><td class="text-end">{{ number_format($pl['total_expenses'], 2) }}</td></tr>

                                {{-- Net Profit --}}
                                <tr class="fw-bold border-top {{ $netProfit >= 0 ? 'table-success' : 'table-danger' }}">
                                    <td class="ps-4">Net {{ $netProfit >= 0 ? 'Profit' : 'Loss' }} @if($pl['net_revenue'] > 0)<small>({{ number_format($pl['net_margin_percent'], 1) }}%)</small>@endif</td>
                                    <td class="text-end">{{ number_format($netProfit, 2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="fw-bold mb-3">Summary</h6>
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Net Revenue</span><strong>{{ number_format($pl['net_revenue'], 2) }}</strong></div>
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Gross Profit</span><strong>{{ number_format($pl['gross_profit'], 2) }}</strong></div>
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Operating Expenses</span><strong>{{ number_format($pl['total_expenses'], 2) }}</strong></div>
                        <hr>
                        <div class="d-flex justify-content-between"><span class="fw-bold">Net {{ $netProfit >= 0 ? 'Profit' : 'Loss' }}</span><strong class="{{ $netProfit >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($netProfit, 2) }}</strong></div>
                        <p class="text-muted small mt-3 mb-0"><i class="ti ti-info-circle me-1"></i>Built from posted general-ledger journals.</p>
                    </div>
                </div>
            </div>
        </div>
@endsection
