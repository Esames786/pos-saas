@extends('layouts.app')

@section('title', 'Balance Sheet')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Balance Sheet</h4>
                <h6>As of {{ $bs['as_of_date'] }}</h6>
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
                        <label class="form-label mb-1">As of</label>
                        <input type="date" name="as_of_date" class="form-control" value="{{ $filters['as_of_date'] }}">
                    </div>
                    @include('tenant.finance.partials.branch-multiselect', ['branches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                    <div class="col-sm-2">
                        <button type="submit" class="btn btn-primary w-100">Apply</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="alert {{ $bs['is_balanced'] ? 'alert-success' : 'alert-danger' }}">
            <i class="ti {{ $bs['is_balanced'] ? 'ti-circle-check' : 'ti-alert-triangle' }} me-1"></i>
            Total Assets {{ number_format($bs['total_assets'], 2) }} | Total Liabilities + Equity {{ number_format($bs['total_liabilities_equity'], 2) }} | Difference {{ number_format($bs['difference'], 2) }}
            {{ $bs['is_balanced'] ? '— Balanced' : '— OUT OF BALANCE' }}
        </div>

        <div class="row">
            {{-- Assets --}}
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-0">
                        <table class="table mb-0">
                            <caption class="visually-hidden">Assets</caption>
                            <tbody>
                                <tr class="table-light"><th colspan="2">Assets</th></tr>
                                @forelse($bs['asset_rows'] as $r)
                                    <tr><td class="ps-4">{{ $r['code'] }} — {{ $r['name'] }}</td><td class="text-end">{{ number_format($r['amount'], 2) }}</td></tr>
                                @empty
                                    <tr><td class="ps-4 text-muted">No asset balances</td><td class="text-end">0.00</td></tr>
                                @endforelse
                                <tr class="fw-bold border-top"><td class="ps-4">Total Assets</td><td class="text-end">{{ number_format($bs['total_assets'], 2) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Liabilities + Equity --}}
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-0">
                        <table class="table mb-0">
                            <caption class="visually-hidden">Liabilities and equity</caption>
                            <tbody>
                                <tr class="table-light"><th colspan="2">Liabilities</th></tr>
                                @forelse($bs['liability_rows'] as $r)
                                    <tr><td class="ps-4">{{ $r['code'] }} — {{ $r['name'] }}</td><td class="text-end">{{ number_format($r['amount'], 2) }}</td></tr>
                                @empty
                                    <tr><td class="ps-4 text-muted">No liability balances</td><td class="text-end">0.00</td></tr>
                                @endforelse
                                <tr class="fw-semibold"><td class="ps-4">Total Liabilities</td><td class="text-end">{{ number_format($bs['total_liabilities'], 2) }}</td></tr>

                                <tr class="table-light"><th colspan="2">Equity</th></tr>
                                @foreach($bs['equity_rows'] as $r)
                                    <tr><td class="ps-4">{{ $r['code'] }} — {{ $r['name'] }}</td><td class="text-end">{{ number_format($r['amount'], 2) }}</td></tr>
                                @endforeach
                                <tr><td class="ps-4">Current Earnings</td><td class="text-end {{ $bs['current_earnings'] >= 0 ? '' : 'text-danger' }}">{{ number_format($bs['current_earnings'], 2) }}</td></tr>
                                <tr class="fw-semibold"><td class="ps-4">Total Equity</td><td class="text-end">{{ number_format($bs['total_equity'] + $bs['current_earnings'], 2) }}</td></tr>

                                <tr class="fw-bold border-top"><td class="ps-4">Total Liabilities + Equity</td><td class="text-end">{{ number_format($bs['total_liabilities_equity'], 2) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <p class="text-muted small mt-2"><i class="ti ti-info-circle me-1"></i>Current Earnings is calculated from open income/expense accounts. Year-end closing is not posted yet.</p>
@endsection
