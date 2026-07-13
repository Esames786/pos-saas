@extends('layouts.app')

@section('title', 'Rider Deliveries Report')

@section('content')
        <div class="page-header">
            <div class="page-title"><h4>Rider Deliveries</h4><h6>Paid delivery orders per rider</h6></div>
            <div class="page-btn">
                <a href="{{ url('/reports/sales/channels') }}" class="btn btn-outline-secondary btn-sm">Channel Report</a>
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
                        <label class="form-label small mb-1" for="filter-rider">Rider</label>
                        <select id="filter-rider" name="delivery_rider_id" class="form-select form-select-sm">
                            <option value="">All Riders</option>
                            @foreach($riders as $r)
                                <option value="{{ $r->id }}" @selected(($filters['delivery_rider_id'] ?? '') == $r->id)>{{ $r->name }}</option>
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

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-transparent fw-semibold">Rider Totals</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <caption class="visually-hidden">Rider delivery totals</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Rider</th>
                            <th scope="col">Phone</th>
                            <th scope="col">Branch</th>
                            <th scope="col" class="text-end">Deliveries</th>
                            <th scope="col" class="text-end">Total Amount</th>
                            <th scope="col" class="text-end">Avg / Delivery</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                        <tr>
                            <td class="fw-medium">{{ $row->rider_name }}</td>
                            <td>{{ $row->rider_phone ?: '-' }}</td>
                            <td>{{ $row->rider_branch }}</td>
                            <td class="text-end">{{ number_format($row->delivery_count) }}</td>
                            <td class="text-end fw-semibold">{{ number_format($row->total_amount, 2) }}</td>
                            <td class="text-end">{{ number_format($row->delivery_count > 0 ? $row->total_amount / $row->delivery_count : 0, 2) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No paid delivery orders in the selected range.</td></tr>
                        @endforelse
                    </tbody>
                    @if($rows->isNotEmpty())
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="3">Total</td>
                            <td class="text-end">{{ number_format($rows->sum('delivery_count')) }}</td>
                            <td class="text-end">{{ number_format($rows->sum('total_amount'), 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        @if($daily->isNotEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent fw-semibold">Per-Day Breakdown</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <caption class="visually-hidden">Rider deliveries per day</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Rider</th>
                            <th scope="col" class="text-end">Deliveries</th>
                            <th scope="col" class="text-end">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($daily as $row)
                        <tr>
                            <td>{{ $row->sale_day }}</td>
                            <td>{{ $row->rider_name }}</td>
                            <td class="text-end">{{ number_format($row->delivery_count) }}</td>
                            <td class="text-end">{{ number_format($row->total_amount, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
@endsection
