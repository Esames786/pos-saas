@extends('layouts.app')

@section('title', 'Daily Closing Report')

@section('content')
        <div class="page-header">
            <div class="page-title"><h4>Daily Closings</h4><h6>Daily closing reconciliation</h6></div>
        </div>

        @include('tenant.reports.partials.filters', ['showTerminal' => true, 'showOrderType' => false, 'showCsvExport' => false])

        @php $t = $totals; @endphp
        <div class="row g-3 mb-3">
            <div class="col-md-2 col-sm-4"><div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                <div class="text-muted small">Closings</div>
                <div class="fw-bold fs-5">{{ number_format($t->closing_count) }}</div>
            </div></div></div>
            <div class="col-md-2 col-sm-4"><div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                <div class="text-muted small">Total Sales</div>
                <div class="fw-bold fs-5">{{ number_format($t->total_sales, 2) }}</div>
            </div></div></div>
            <div class="col-md-2 col-sm-4"><div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                <div class="text-muted small">Cash</div>
                <div class="fw-bold fs-5">{{ number_format($t->total_cash, 2) }}</div>
            </div></div></div>
            <div class="col-md-2 col-sm-4"><div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                <div class="text-muted small">Expected Cash</div>
                <div class="fw-bold fs-5">{{ number_format($t->expected_cash, 2) }}</div>
            </div></div></div>
            <div class="col-md-2 col-sm-4"><div class="card border-0 shadow-sm text-center"><div class="card-body py-2">
                <div class="text-muted small">Counted Cash</div>
                <div class="fw-bold fs-5">{{ number_format($t->counted_cash, 2) }}</div>
            </div></div></div>
            <div class="col-md-2 col-sm-4"><div class="card border-0 shadow-sm text-center @if(abs($t->total_variance) > 0.01) border-warning @endif"><div class="card-body py-2">
                <div class="text-muted small">Variance</div>
                <div class="fw-bold fs-5 @if(abs($t->total_variance) > 0.01) text-danger @endif">{{ number_format($t->total_variance, 2) }}</div>
            </div></div></div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <caption class="visually-hidden">Daily closing list</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Branch</th>
                                <th scope="col">Terminal</th>
                                <th scope="col">Closed By</th>
                                <th scope="col" class="text-end">Sales</th>
                                <th scope="col" class="text-end">Expected</th>
                                <th scope="col" class="text-end">Counted</th>
                                <th scope="col" class="text-end">Variance</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($closings as $c)
                            <tr>
                                <td>{{ $c->closing_date->format('d/m/Y') }}</td>
                                <td>{{ $c->branch?->name }}</td>
                                <td>{{ $c->terminal?->name ?? '—' }}</td>
                                <td>{{ $c->closedBy?->name ?? '—' }}</td>
                                <td class="text-end">{{ number_format($c->total_sales, 2) }}</td>
                                <td class="text-end">{{ number_format($c->expected_cash, 2) }}</td>
                                <td class="text-end">{{ $c->counted_cash !== null ? number_format($c->counted_cash, 2) : '—' }}</td>
                                <td class="text-end @if($c->cash_variance && abs($c->cash_variance) > 0.01) text-danger fw-semibold @endif">
                                    {{ $c->cash_variance !== null ? number_format($c->cash_variance, 2) : '—' }}
                                </td>
                                <td><span class="badge bg-{{ $c->status === 'approved' ? 'success' : 'secondary' }}">{{ ucfirst($c->status) }}</span></td>
                            </tr>
                            @empty
                            <tr><td colspan="9" class="text-center text-muted py-4">No closings in range.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-3">{{ $closings->links() }}</div>
            </div>
        </div>
@endsection
