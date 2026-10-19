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
                <div class="col-sm-3 col-md-2">
                    <button class="btn btn-primary w-100">Go</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Phone</th>
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
                                    <a href="{{ url('/catering/customer-balances/'.$row['customer_id']) }}"
                                       class="fw-semibold text-decoration-none">{{ $row['name'] }}</a>
                                    @if($row['last_event_date'])
                                        <div class="text-muted fs-12">
                                            last event {{ \Carbon\Carbon::parse($row['last_event_date'])->format('d M Y') }}
                                        </div>
                                    @endif
                                </td>
                                <td dir="ltr">{{ $row['phone'] ?: '—' }}</td>
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
                            <tr><td colspan="7" class="text-center text-muted py-4">No linked catering customers yet.</td></tr>
                        @endforelse
                    </tbody>
                    @if($rows->isNotEmpty())
                        <tfoot>
                            <tr class="fw-semibold border-top">
                                <td colspan="3">{{ $rows->count() }} customer{{ $rows->count() === 1 ? '' : 's' }}</td>
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
