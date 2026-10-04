{{-- CATERING-CUSTOMER-BALANCES-1 (28 Sep) — graahak ka hisaab, booking ke
     bahar se.

     Balance aur Credit DO alag khaane hain, ek signed adad nahi: ek hi graahak
     ek booking par de sakta hai aur doosri par le sakta hai, aur net adad ye
     baat chhupa deta hai. --}}
@extends('layouts.app')

@section('title', 'Customer Catering Balances')

@section('content')
<div class="content">

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <h4 class="mb-1">Customer Catering Balances</h4>
            <p class="text-muted mb-0 fs-13">
                What each customer has been billed for their events, what they have paid, and what is still open.
            </p>
        </div>
    </div>

    {{-- Jo bookings kisi graahak se juri hi nahi, wo yahan SE BAHAR hain — is
         liye un ka hona saaf likha ja raha hai. Ek total jis me se rows
         khamoshi se nikal jayen, us total se bura hai jis ke saath baqiya
         saath likha ho. --}}
    @if(($unlinked['count'] ?? 0) > 0)
        <div class="alert alert-warning d-flex align-items-start gap-2 py-2 px-3 fs-13">
            <i class="ti ti-alert-triangle mt-1"></i>
            <div>
                <strong>{{ $unlinked['count'] }} booking{{ $unlinked['count'] === 1 ? '' : 's' }} not linked to a customer</strong>
                — not counted below.
                @if($unlinked['balance'] > 0)
                    They hold <strong>{{ number_format($unlinked['balance'], 2) }}</strong> of open balance.
                @endif
                A booking is linked by its phone number; these either have none or were made before linking existed.
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-body pb-1">
            <form method="GET" class="row g-2 align-items-end mb-3">
                <div class="col-sm-5 col-md-4">
                    <label class="form-label fs-13 mb-1">Search</label>
                    <input type="text" name="q" value="{{ $search }}" class="form-control"
                           placeholder="Name or phone">
                </div>
                <div class="col-sm-4 col-md-3">
                    <label class="form-label fs-13 mb-1">Branch</label>
                    <select name="branch_id" class="form-select">
                        <option value="">All branches</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected($branchId === $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- CATERING-BALANCES-STATUS-FILTER-1 — malik: "yahan event status
                     waghera dikha do, us ke filter ke hisaab se customer show
                     hon."

                     "Still open" sab se upar hai kyunke yehi wo sawal hai jis
                     ke liye ye screen kholi jati hai: kis CHALTI HUI booking par
                     paisa baqi hai. Us ki apni tareef nahi likhi ja rahi —
                     `CateringEvent::OPEN_STATUSES` pehle se mojood hai. --}}
                <div class="col-sm-4 col-md-3">
                    <label class="form-label fs-13 mb-1">Booking status</label>
                    <select name="status" class="form-select">
                        <option value="">All statuses</option>
                        <option value="open" @selected($status === 'open')>Still open (inquiry → confirmed)</option>
                        @foreach(\App\Models\Tenant\CateringEvent::STATUSES as $s)
                            <option value="{{ $s }}" @selected($status === $s)>{{ \App\Models\Tenant\CateringEvent::statusLabel($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-3 col-md-2">
                    <button class="btn btn-primary w-100">Go</button>
                </div>
            </form>

            {{-- Filter lagte hi in adad ka MATLAB badal jata hai: ab ye graahak
                 ka poora hisaab nahi, sirf in bookings ka hai. Ye baat chhupayi
                 nahi ja sakti — warna "Balance 9,59,597" parhne wala samajhta
                 hai ke graahak par itna baqi hai. --}}
            @if($status !== '')
                <div class="alert alert-info d-flex align-items-start gap-2 py-2 px-3 fs-13">
                    <i class="ti ti-filter mt-1"></i>
                    <div>
                        Showing only
                        <strong>{{ $status === 'open' ? 'still open' : \App\Models\Tenant\CateringEvent::statusLabel($status) }}</strong>
                        bookings. <strong>Billed, Received, Balance and Credit below count these bookings only</strong> —
                        they are not the customer's full position.
                        <a href="{{ url('/catering/customer-balances') }}" class="ms-1">Clear filter</a>
                    </div>
                </div>
            @endif

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Booking status</th>
                            <th class="text-center">Events</th>
                            <th class="text-end">Billed</th>
                            <th class="text-end">Received</th>
                            <th class="text-end">Balance</th>
                            <th class="text-end">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr>
                                <td>
                                    {{-- Filter andar bhi saath jata hai: jis haalat
                                         ke graahak dekh kar click kiya, wahi
                                         bookings tafseel me bhi milni chahiyen,
                                         warna adad badal jate hain. --}}
                                    <a href="{{ url('/catering/customer-balances/'.$row['customer_id'])
                                        .($status !== '' ? '?status='.$status : '') }}"
                                       class="fw-semibold text-decoration-none">{{ $row['name'] }}</a>
                                    @if($row['last_event_date'])
                                        <div class="text-muted fs-12">
                                            last event {{ \Carbon\Carbon::parse($row['last_event_date'])->format('d M Y') }}
                                        </div>
                                    @endif
                                </td>
                                <td dir="ltr">{{ $row['phone'] ?: '—' }}</td>
                                {{-- Ek graahak ke kai event ho sakte hain aur har
                                     ek apni haalat me, is liye yahan EK status
                                     nahi likha ja sakta — ginti likhi jati hai.
                                     Rang aur naam dono model se aate hain, taake
                                     bookings ki fehrist aur ye screen ek hi
                                     zabaan bolein. --}}
                                <td>
                                    @foreach($row['status_counts'] as $s => $n)
                                        <span class="badge bg-{{ \App\Models\Tenant\CateringEvent::statusBadge($s) }} fs-11 me-1">
                                            {{ $n > 1 ? $n.' ' : '' }}{{ \App\Models\Tenant\CateringEvent::statusLabel($s) }}
                                        </span>
                                    @endforeach
                                </td>
                                <td class="text-center">{{ $row['events'] }}</td>
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
                        @empty
                            {{-- Khali screen ki WAJAH batani zaroori hai. Filter
                                 ke sabab khali hone par "abhi koi graahak nahi"
                                 kehna jhoot hai — graahak mojood hain, bas is
                                 haalat me koi booking nahi. --}}
                            <tr><td colspan="8" class="text-center text-muted py-4">
                                @if($status !== '')
                                    No customer has a booking in this status.
                                    <a href="{{ url('/catering/customer-balances') }}">Clear the filter</a>
                                @else
                                    No linked catering customers yet.
                                @endif
                            </td></tr>
                        @endforelse
                    </tbody>
                    @if($rows->isNotEmpty())
                        <tfoot>
                            <tr class="fw-semibold border-top">
                                <td colspan="4">{{ $rows->count() }} customer{{ $rows->count() === 1 ? '' : 's' }}</td>
                                <td class="text-end">{{ number_format($totals['billed'], 2) }}</td>
                                <td class="text-end">{{ number_format($totals['received'], 2) }}</td>
                                <td class="text-end">{{ number_format($totals['balance'], 2) }}</td>
                                <td class="text-end">{{ number_format($totals['credit'], 2) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>

</div>
@endsection
