@extends('layouts.app')

@section('title', 'Daily Closing — ' . $dailyClosing->closing_date->format('Y-m-d'))

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Daily Closing — {{ $dailyClosing->closing_date->format('d M Y') }}</h1>
        <p class="fw-medium">
            {{ $dailyClosing->branch?->name }}
            @if($dailyClosing->status === 'approved')
                <span class="badge bg-success ms-2">Approved</span>
            @else
                <span class="badge bg-secondary ms-2">Closed</span>
            @endif
        </p>
    </div>
    <div class="action-toolbar">
        @if($dailyClosing->status === 'closed')
            @can('tenant.daily-closings.approve')
                <form method="POST" action="{{ url('/daily-closings/' . $dailyClosing->id . '/approve') }}">
                    @csrf
                    <button type="submit" class="btn btn-success">Approve</button>
                </form>
            @endcan
        @endif
        <a href="{{ url('/daily-closings') }}" class="btn btn-light">Back</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><h5 class="mb-0">Summary</h5></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Branch</dt>
                    <dd class="col-sm-7">{{ $dailyClosing->branch?->name }}</dd>

                    <dt class="col-sm-5">Closing Date</dt>
                    <dd class="col-sm-7">{{ $dailyClosing->closing_date->format('Y-m-d') }}</dd>

                    <dt class="col-sm-5">Closed By</dt>
                    <dd class="col-sm-7">{{ $dailyClosing->closedBy?->name }}</dd>

                    <dt class="col-sm-5">Notes</dt>
                    <dd class="col-sm-7">{{ $dailyClosing->notes ?? '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><h5 class="mb-0">Financial Summary</h5></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-6">Total Sales</dt>
                    <dd class="col-sm-6">{{ number_format($dailyClosing->total_sales, 2) }}</dd>

                    <dt class="col-sm-6">Total Cash</dt>
                    <dd class="col-sm-6">{{ number_format($dailyClosing->total_cash, 2) }}</dd>

                    <dt class="col-sm-6">Total Card</dt>
                    <dd class="col-sm-6">{{ number_format($dailyClosing->total_card, 2) }}</dd>

                    <dt class="col-sm-6">Total Refunds</dt>
                    <dd class="col-sm-6">{{ number_format($dailyClosing->total_refunds, 2) }}</dd>

                    <dt class="col-sm-6">Total Tax</dt>
                    <dd class="col-sm-6">{{ number_format($dailyClosing->total_tax, 2) }}</dd>

                    <dt class="col-sm-6">Expected Cash</dt>
                    <dd class="col-sm-6"><strong>{{ number_format($dailyClosing->expected_cash, 2) }}</strong></dd>

                    <dt class="col-sm-6">Counted Cash</dt>
                    <dd class="col-sm-6">{{ $dailyClosing->counted_cash !== null ? number_format($dailyClosing->counted_cash, 2) : '—' }}</dd>

                    <dt class="col-sm-6">Cash Variance</dt>
                    <dd class="col-sm-6">
                        @if($dailyClosing->cash_variance !== null)
                            <span class="{{ $dailyClosing->cash_variance < 0 ? 'text-danger' : ($dailyClosing->cash_variance > 0 ? 'text-warning' : 'text-success') }}">
                                {{ number_format($dailyClosing->cash_variance, 2) }}
                            </span>
                        @else
                            —
                        @endif
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    @if($dailyClosing->cashCountLines->count())
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Cash Count Breakdown</h5></div>
                <div class="card-body table-responsive">
                    <table class="table table-sm">
                        <caption class="visually-hidden">Cash denomination count for this daily closing</caption>
                        <thead>
                            <tr>
                                <th scope="col">Denomination</th>
                                <th scope="col">Type</th>
                                <th scope="col">Quantity</th>
                                <th scope="col">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($dailyClosing->cashCountLines as $line)
                            <tr>
                                <td>{{ number_format($line->denomination?->denomination_value, 2) }}</td>
                                <td>{{ ucfirst($line->denomination?->denomination_type) }}</td>
                                <td>{{ $line->quantity }}</td>
                                <td>{{ number_format($line->amount, 2) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="fw-bold">
                                <td colspan="3">Total</td>
                                <td>{{ number_format($dailyClosing->cashCountLines->sum('amount'), 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
