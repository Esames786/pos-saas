{{--
    KASHIF-CATERING-OPERATOR-UI-1 — the owner's catering morning glance.

    Every card is a count/sum of facts other screens already show. The balance
    figure comes from CateringFinancialPositionService — the SAME refund-aware
    authority the booking workspace uses — never a dashboard-local formula.
    (Net Sales already sits above in the canonical today-stats cards, told by
    the same SalesReportService the Report Centre uses; catering does not get a
    second, competing version of that number.)
--}}
@php $k = $cateringKpis; @endphp
<div class="row g-3 mb-3">
    <div class="col-6 col-lg">
        <div class="card h-100">
            <div class="card-body py-3">
                <div class="fs-13 fw-semibold text-body-secondary">Today's Events</div>
                <div class="fs-2 fw-bold lh-1 mt-1">{{ number_format($k['today']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card h-100">
            <div class="card-body py-3">
                <div class="fs-13 fw-semibold text-body-secondary">Next 7 Days</div>
                <div class="fs-2 fw-bold lh-1 mt-1">{{ number_format($k['next7']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card h-100">
            <div class="card-body py-3">
                <div class="fs-13 fw-semibold text-body-secondary">Awaiting Finalization</div>
                <div class="fs-2 fw-bold lh-1 mt-1">{{ number_format($k['drafts']) }}</div>
                <div class="fs-12 text-body-secondary">draft quotations</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card h-100">
            <div class="card-body py-3">
                <div class="fs-13 fw-semibold text-body-secondary">Production Pending</div>
                <div class="fs-2 fw-bold lh-1 mt-1">{{ number_format($k['production_pending']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg">
        <div class="card h-100">
            <div class="card-body py-3">
                <div class="fs-13 fw-semibold text-body-secondary">Outstanding Customer Balance</div>
                <div class="fs-3 fw-bold lh-1 mt-1">{{ number_format($k['outstanding_balance'], 2) }}</div>
                <div class="fs-12 text-body-secondary">open bookings, net of refunds</div>
            </div>
        </div>
    </div>
</div>

@if(! empty($cateringNextSeven))
<div class="card mb-3">
    <div class="card-header bg-transparent">
        <h5 class="mb-0"><i class="ti ti-calendar-due me-1"></i>Upcoming — Next 7 Days</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr class="fs-13 fw-semibold text-body-secondary">
                        <th class="ps-3">Booking</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Venue</th>
                        <th class="text-end">PAX</th>
                        <th>Booking</th>
                        <th>Next Action</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($cateringNextSeven as $ev)
                    <tr>
                        <td class="ps-3 fw-semibold">{{ $ev['event_no'] }}</td>
                        <td>{{ $ev['date'] }}</td>
                        <td>{{ $ev['time'] ?? '—' }}</td>
                        <td>{{ $ev['customer'] }}</td>
                        <td>{{ $ev['phone'] ?? '—' }}</td>
                        <td>{{ $ev['venue'] ?? '—' }}</td>
                        <td class="text-end">{{ number_format($ev['pax']) }}</td>
                        <td>
                            <span class="badge bg-{{ $ev['tone'] === 'confirmed' ? 'success' : ($ev['tone'] === 'overdue' ? 'danger' : 'info text-dark') }}">
                                {{ $ev['status_label'] }}
                            </span>
                        </td>
                        <td class="fw-semibold">{{ $ev['next_action'] }}</td>
                        <td class="text-end pe-3">
                            <a class="btn btn-sm btn-outline-primary" href="{{ url($ev['url']) }}">Open</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif
