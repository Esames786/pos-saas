{{-- CATERING-CUSTOMER-BALANCES-1 (28 Sep) — ek graahak ki poori tasveer.

     Qadam 1 me sirf EVENTS ka khaana hai. Documents aur Ledger ke khaane aur
     "record payment" ka raasta agle qadam me aayenge — dekho
     docs/design/catering-customer-balances-module-2026-09-28.md. Adhoore
     khaane jaan-boojh kar nahi dikhaye ja rahe: ek khali tab ye keh deta hai
     ke data nahi, jab ke asal baat ye hai ke screen abhi nahi bani. --}}
@extends('layouts.app')

@section('title', $customer->name)

@section('content')
<div class="content">

    <div class="mb-3">
        <a href="{{ url('/catering/customer-balances') }}" class="text-muted fs-13 text-decoration-none">
            <i class="ti ti-arrow-left"></i> Customer Catering Balances
        </a>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between gap-3">
                <div>
                    <h4 class="mb-1">{{ $customer->name }}</h4>
                    <div class="text-muted fs-13">
                        @if($customer->phone)<span dir="ltr">{{ $customer->phone }}</span>@endif
                        @if($customer->address) &middot; {{ $customer->address }} @endif
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-4 text-end">
                    <div>
                        <div class="text-muted fs-12 text-uppercase">Billed</div>
                        <div class="fs-18 fw-semibold">{{ number_format($totals['billed'], 2) }}</div>
                    </div>
                    <div>
                        <div class="text-muted fs-12 text-uppercase">Received</div>
                        <div class="fs-18 fw-semibold">{{ number_format($totals['received'], 2) }}</div>
                    </div>
                    <div>
                        <div class="text-muted fs-12 text-uppercase">Balance</div>
                        <div class="fs-18 fw-semibold {{ $totals['balance'] > 0 ? 'text-danger' : 'text-muted' }}">
                            {{ number_format($totals['balance'], 2) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-muted fs-12 text-uppercase">Credit</div>
                        <div class="fs-18 fw-semibold {{ $totals['credit'] > 0 ? 'text-warning' : 'text-muted' }}">
                            {{ number_format($totals['credit'], 2) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body pb-1">
            <h5 class="mb-3">Events <span class="text-muted fs-14">({{ $events->count() }})</span></h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Booking</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th class="text-end">Billed</th>
                            <th class="text-end">Received</th>
                            <th class="text-end">Balance</th>
                            <th class="text-end">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($events as $row)
                            @php $event = $row['event']; @endphp
                            <tr>
                                <td>
                                    <a href="{{ url('/catering/events/'.$event->id) }}" class="fw-semibold text-decoration-none">
                                        {{ $event->event_no }}
                                    </a>
                                    {{-- Bill kis cheez par bana — invoice par, ya abhi tak sirf
                                         quotation par. Ye farq screen par likha ja raha hai kyunke
                                         "300,000 baqi" ka matlab in dono surton me alag hai: ek
                                         jaari shuda bill hai, doosra abhi tak sirf ek peshkash. --}}
                                    <div class="text-muted fs-12">
                                        @if($row['billed_source'] === 'invoice')
                                            invoiced {{ $event->finalInvoice?->invoice_no }}
                                        @elseif($row['billed_source'] === 'cancelled')
                                            cancelled — not billed
                                        @elseif($row['billed_source'] === 'estimate')
                                            quotation only
                                        @else
                                            nothing quoted yet
                                        @endif
                                    </div>
                                </td>
                                <td>{{ $event->event_date?->format('d M Y') }}</td>
                                <td><span class="badge bg-light text-dark fs-12">{{ $event->status }}</span></td>
                                <td class="text-end">{{ number_format($row['billed'], 2) }}</td>
                                <td class="text-end">{{ number_format($row['received'], 2) }}</td>
                                <td class="text-end">
                                    @if($row['balance'] > 0)
                                        <span class="badge bg-danger-transparent text-danger fs-13">{{ number_format($row['balance'], 2) }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if($row['credit'] > 0)
                                        <span class="badge bg-warning-transparent text-warning fs-13">{{ number_format($row['credit'], 2) }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="fw-semibold border-top">
                            <td colspan="3">Total</td>
                            <td class="text-end">{{ number_format($totals['billed'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['received'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['balance'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['credit'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

</div>
@endsection
