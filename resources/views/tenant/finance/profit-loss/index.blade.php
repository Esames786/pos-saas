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
                        @if($val)<input type="hidden" name="{{ $k }}" value="{{ $val }}">@endif
                    @endforeach
                    <button type="submit" name="export_csv" value="1" class="btn btn-outline-success btn-sm"><i class="ti ti-download me-1"></i>CSV</button>
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
                    <div class="col-sm-3">
                        <label class="form-label mb-1">Branch</label>
                        <select name="branch_id" class="form-select">
                            <option value="">All branches</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ (string)($filters['branch_id'] ?? '') === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
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
                                <tr class="table-light"><th colspan="2">Revenue</th></tr>
                                @forelse($pl['revenue_rows'] as $r)
                                    <tr><td class="ps-4">{{ $r['code'] }} — {{ $r['name'] }}</td><td class="text-end">{{ number_format($r['amount'], 2) }}</td></tr>
                                @empty
                                    <tr><td class="ps-4 text-muted">No revenue in period</td><td class="text-end">0.00</td></tr>
                                @endforelse
                                <tr class="fw-semibold"><td class="ps-4">Gross Revenue</td><td class="text-end">{{ number_format($pl['gross_revenue'], 2) }}</td></tr>

                                @if(count($pl['discount_rows']))
                                    @foreach($pl['discount_rows'] as $r)
                                        <tr><td class="ps-4 text-muted">Less: {{ $r['code'] }} — {{ $r['name'] }}</td><td class="text-end text-muted">({{ number_format($r['amount'], 2) }})</td></tr>
                                    @endforeach
                                @endif
                                <tr class="fw-semibold border-top"><td class="ps-4">Net Revenue</td><td class="text-end">{{ number_format($pl['net_revenue'], 2) }}</td></tr>

                                {{-- COGS --}}
                                <tr class="table-light"><th colspan="2">Cost of Goods Sold</th></tr>
                                @forelse($pl['cogs_rows'] as $r)
                                    <tr><td class="ps-4">{{ $r['code'] }} — {{ $r['name'] }}</td><td class="text-end">{{ number_format($r['amount'], 2) }}</td></tr>
                                @empty
                                    <tr><td class="ps-4 text-muted">No COGS in period</td><td class="text-end">0.00</td></tr>
                                @endforelse
                                <tr class="fw-semibold"><td class="ps-4">Total COGS</td><td class="text-end">{{ number_format($pl['total_cogs'], 2) }}</td></tr>

                                {{-- Gross Profit --}}
                                <tr class="fw-bold border-top">
                                    <td class="ps-4">Gross Profit @if($pl['net_revenue'] > 0)<small class="text-muted">({{ number_format($pl['gross_margin_percent'], 1) }}%)</small>@endif</td>
                                    <td class="text-end">{{ number_format($pl['gross_profit'], 2) }}</td>
                                </tr>

                                {{-- Operating Expenses --}}
                                <tr class="table-light"><th colspan="2">Operating Expenses</th></tr>
                                @forelse($pl['expense_rows'] as $r)
                                    <tr><td class="ps-4">{{ $r['code'] }} — {{ $r['name'] }}</td><td class="text-end">{{ number_format($r['amount'], 2) }}</td></tr>
                                @empty
                                    <tr><td class="ps-4 text-muted">No operating expenses in period</td><td class="text-end">0.00</td></tr>
                                @endforelse
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
