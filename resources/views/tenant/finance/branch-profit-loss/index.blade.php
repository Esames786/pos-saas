@extends('layouts.app')

@section('title', 'Branch-wise Profit & Loss')

@php $t = $report['totals']; @endphp

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Branch-wise Profit &amp; Loss</h4>
                <h6>{{ $report['period']['from'] }} to {{ $report['period']['to'] }}</h6>
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
                    @include('tenant.finance.partials.branch-multiselect', ['branches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                    <div class="col-sm-2">
                        <button type="submit" class="btn btn-primary w-100">Apply</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <caption class="visually-hidden">Branch-wise profit and loss</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Branch</th>
                                <th scope="col" class="text-end">Gross Revenue</th>
                                <th scope="col" class="text-end">Discounts</th>
                                <th scope="col" class="text-end">Net Revenue</th>
                                <th scope="col" class="text-end">COGS</th>
                                <th scope="col" class="text-end">Gross Profit</th>
                                <th scope="col" class="text-end">Op. Expenses</th>
                                <th scope="col" class="text-end">Net Profit / Loss</th>
                                <th scope="col" class="text-end">GP %</th>
                                <th scope="col" class="text-end">NP %</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($report['rows'] as $r)
                            <tr>
                                <td class="fw-semibold">{{ $r['branch_name'] }}</td>
                                <td class="text-end">{{ number_format($r['gross_revenue'], 2) }}</td>
                                <td class="text-end">{{ $r['discounts'] != 0 ? number_format($r['discounts'], 2) : '—' }}</td>
                                <td class="text-end">{{ number_format($r['net_revenue'], 2) }}</td>
                                <td class="text-end">{{ number_format($r['cogs'], 2) }}</td>
                                <td class="text-end">{{ number_format($r['gross_profit'], 2) }}</td>
                                <td class="text-end">{{ number_format($r['operating_expenses'], 2) }}</td>
                                <td class="text-end fw-semibold {{ $r['net_profit'] >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($r['net_profit'], 2) }}</td>
                                <td class="text-end">{{ $r['net_revenue'] > 0 ? number_format($r['gross_margin_percent'], 1) . '%' : '—' }}</td>
                                <td class="text-end">{{ $r['net_revenue'] > 0 ? number_format($r['net_margin_percent'], 1) . '%' : '—' }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="10" class="text-center text-muted py-4">No general-ledger activity in this period.</td></tr>
                            @endforelse
                        </tbody>
                        @if(count($report['rows']))
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td>Total</td>
                                <td class="text-end">{{ number_format($t['gross_revenue'], 2) }}</td>
                                <td class="text-end">{{ $t['discounts'] != 0 ? number_format($t['discounts'], 2) : '—' }}</td>
                                <td class="text-end">{{ number_format($t['net_revenue'], 2) }}</td>
                                <td class="text-end">{{ number_format($t['cogs'], 2) }}</td>
                                <td class="text-end">{{ number_format($t['gross_profit'], 2) }}</td>
                                <td class="text-end">{{ number_format($t['operating_expenses'], 2) }}</td>
                                <td class="text-end {{ $t['net_profit'] >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($t['net_profit'], 2) }}</td>
                                <td class="text-end">{{ $t['net_revenue'] > 0 ? number_format($t['gross_margin_percent'], 1) . '%' : '—' }}</td>
                                <td class="text-end">{{ $t['net_revenue'] > 0 ? number_format($t['net_margin_percent'], 1) . '%' : '—' }}</td>
                            </tr>
                        </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
        <p class="text-muted small mt-2"><i class="ti ti-info-circle me-1"></i>Built from posted general-ledger journals, grouped by branch. Total reconciles to the overall Profit &amp; Loss.</p>
@endsection
