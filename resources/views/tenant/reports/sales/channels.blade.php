@extends('layouts.app')

@section('title', 'Sales by Delivery Channel')

@section('content')
        <div class="page-header">
            <div class="page-title"><h4>Sales by Channel</h4><h6>Paid delivery orders grouped by fulfilment channel</h6></div>
            <div class="page-btn">
                <a href="{{ url('/reports/sales/riders') }}" class="btn btn-outline-secondary btn-sm">Rider Report</a>
                <a href="{{ url('/reports/sales/summary') }}" class="btn btn-outline-secondary btn-sm">Summary</a>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <form method="GET" action="" class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="filter-date-from">From</label>
                        <input type="date" id="filter-date-from" name="date_from" class="form-control form-control-sm"
                            value="{{ $filters['date_from'] ?? today()->format('Y-m-d') }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="filter-date-to">To</label>
                        <input type="date" id="filter-date-to" name="date_to" class="form-control form-control-sm"
                            value="{{ $filters['date_to'] ?? today()->format('Y-m-d') }}">
                    </div>
                    @include('tenant.partials.branch-multiselect', [
                        'branches'          => $branches ?? [],
                        'selectedBranchIds' => $selectedBranchIds ?? [],
                        'colClass'          => 'col-md-3',
                    ])
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="filter-channel">Channel</label>
                        <select id="filter-channel" name="delivery_channel_id" class="form-select form-select-sm">
                            <option value="">All Channels</option>
                            @foreach($channels as $c)
                                <option value="{{ $c->id }}" @selected(($filters['delivery_channel_id'] ?? '') == $c->id)>{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                        <a href="{{ url()->current() }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                        <button type="submit" name="export_csv" value="1" class="btn btn-outline-success btn-sm">
                            <i class="ti ti-download me-1"></i>CSV
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <caption class="visually-hidden">Delivery sales by channel</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Channel</th>
                            <th scope="col">Type</th>
                            <th scope="col" class="text-end">Orders</th>
                            <th scope="col" class="text-end">Gross Amount</th>
                            <th scope="col" class="text-end">Commission %</th>
                            <th scope="col" class="text-end">Commission</th>
                            <th scope="col" class="text-end">Net After Commission</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                        <tr>
                            <td class="fw-medium">{{ $row->channel_name }}</td>
                            <td>
                                @if($row->channel_type)
                                    <span class="badge bg-{{ $row->channel_type === 'own' ? 'info' : 'warning text-dark' }}">
                                        {{ $row->channel_type === 'own' ? 'Own' : 'Aggregator' }}
                                    </span>
                                @else
                                    <span class="text-muted small">-</span>
                                @endif
                            </td>
                            <td class="text-end">{{ number_format($row->order_count) }}</td>
                            <td class="text-end">{{ number_format($row->gross_amount, 2) }}</td>
                            <td class="text-end">{{ number_format($row->commission_percent, 2) }}</td>
                            <td class="text-end text-danger">{{ number_format($row->commission_amount, 2) }}</td>
                            <td class="text-end fw-semibold">{{ number_format($row->gross_amount - $row->commission_amount, 2) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No paid delivery orders in the selected range.</td></tr>
                        @endforelse
                    </tbody>
                    @if($rows->isNotEmpty())
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="2">Total</td>
                            <td class="text-end">{{ number_format($rows->sum('order_count')) }}</td>
                            <td class="text-end">{{ number_format($rows->sum('gross_amount'), 2) }}</td>
                            <td></td>
                            <td class="text-end">{{ number_format($rows->sum('commission_amount'), 2) }}</td>
                            <td class="text-end">{{ number_format($rows->sum('gross_amount') - $rows->sum('commission_amount'), 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        <p class="text-muted small mt-2 mb-0">
            Commission is estimated at each channel's <em>current</em> commission rate - it is a visibility figure,
            not a settlement amount. Reconcile against the aggregator's statement before paying out.
        </p>
@endsection
