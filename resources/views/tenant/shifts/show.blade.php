@extends('layouts.app')

@section('title', 'Shift #' . $shift->id)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Shift #{{ $shift->id }}</h1>
        <p class="fw-medium">
            {{ $shift->branch?->name }} — {{ $shift->terminal?->name }}
            @if($shift->status === 'open')
                <span class="badge bg-warning text-dark ms-2">Open</span>
            @else
                <span class="badge bg-secondary ms-2">Closed</span>
            @endif
        </p>
    </div>
    <div class="action-toolbar">
        @if($shift->status === 'open')
            @can('tenant.shifts.close-form')
                <a href="{{ url('/shifts/' . $shift->id . '/close') }}" class="btn btn-danger">
                    Close Shift
                </a>
            @endcan
        @endif
        <a href="{{ url('/shifts') }}" class="btn btn-light">Back</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><h5 class="mb-0">Shift Summary</h5></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Branch</dt>
                    <dd class="col-sm-7">{{ $shift->branch?->name }}</dd>

                    <dt class="col-sm-5">Terminal</dt>
                    <dd class="col-sm-7">{{ $shift->terminal?->name }}</dd>

                    <dt class="col-sm-5">Opened By</dt>
                    <dd class="col-sm-7">{{ $shift->openedBy?->name }}</dd>

                    <dt class="col-sm-5">Opened At</dt>
                    <dd class="col-sm-7">{{ $shift->opened_at?->format('Y-m-d H:i') }}</dd>

                    <dt class="col-sm-5">Closed By</dt>
                    <dd class="col-sm-7">{{ $shift->closedBy?->name ?? '—' }}</dd>

                    <dt class="col-sm-5">Closed At</dt>
                    <dd class="col-sm-7">{{ $shift->closed_at?->format('Y-m-d H:i') ?? '—' }}</dd>

                    <dt class="col-sm-5">Opening Notes</dt>
                    <dd class="col-sm-7">{{ $shift->opening_notes ?? '—' }}</dd>

                    <dt class="col-sm-5">Closing Notes</dt>
                    <dd class="col-sm-7">{{ $shift->closing_notes ?? '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><h5 class="mb-0">Cash Summary</h5></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-6">Opening Cash</dt>
                    <dd class="col-sm-6">{{ number_format($shift->opening_cash, 2) }}</dd>

                    <dt class="col-sm-6">Total Sales</dt>
                    <dd class="col-sm-6">{{ number_format($shift->total_sales, 2) }}</dd>

                    <dt class="col-sm-6">Total Cash</dt>
                    <dd class="col-sm-6">{{ number_format($shift->total_cash, 2) }}</dd>

                    <dt class="col-sm-6">Total Card</dt>
                    <dd class="col-sm-6">{{ number_format($shift->total_card, 2) }}</dd>

                    <dt class="col-sm-6">Total Refunds</dt>
                    <dd class="col-sm-6">{{ number_format($shift->total_refunds, 2) }}</dd>

                    <dt class="col-sm-6">Expected Cash</dt>
                    <dd class="col-sm-6"><strong>{{ number_format($shift->expected_cash, 2) }}</strong></dd>

                    <dt class="col-sm-6">Counted Cash</dt>
                    <dd class="col-sm-6">{{ $shift->counted_cash !== null ? number_format($shift->counted_cash, 2) : '—' }}</dd>

                    <dt class="col-sm-6">Cash Variance</dt>
                    <dd class="col-sm-6">
                        @if($shift->cash_variance !== null)
                            <span class="{{ $shift->cash_variance < 0 ? 'text-danger' : ($shift->cash_variance > 0 ? 'text-warning' : 'text-success') }}">
                                {{ number_format($shift->cash_variance, 2) }}
                            </span>
                        @else
                            —
                        @endif
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    @if($shift->cashCountLines->count())
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Cash Count Breakdown</h5></div>
                <div class="card-body table-responsive">
                    <table class="table table-sm">
                        <caption class="visually-hidden">Cash denomination count</caption>
                        <thead>
                            <tr>
                                <th scope="col">Denomination</th>
                                <th scope="col">Type</th>
                                <th scope="col">Quantity</th>
                                <th scope="col">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($shift->cashCountLines as $line)
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
                                <td>{{ number_format($shift->cashCountLines->sum('amount'), 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
