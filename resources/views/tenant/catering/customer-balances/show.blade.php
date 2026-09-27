{{-- CATERING-CUSTOMER-BALANCES-1 (28 Sep) — ek graahak ki poori tasveer.

     Malik: "is screen mai saare wo option hon — record payment, refund, advance
     — against customer order, wohi jaise event wali screen se karte hain, aur
     poora us ka ledger bhi dekh sakein."

     YAHAN KOI NAYA RAASTA NAHI. Neeche ke dono form BILKUL wohi endpoints par
     jate hain jo booking ki screen chalati hai:
         POST /catering/events/{event}/advances
         POST /catering/events/{event}/refunds
     Ye screen sirf EVENT CHUNTI hai. Paisa lene ka faisla, us ki hadd aur us
     ki posting jahan aaj hai wahin rehti hai — do jagah paisa lene wali screen
     ek din do alag qawaid par chalne lagti hai.

     Isi liye har action EVENT ke against hai, "graahak ke against" nahi:
     posting ka qaida hi event par mabni hai (invoice se pehle Cr 2300, baad me
     Cr 1300), aur ek screen jo sirf graahak jaanti ho wo faisla kar hi nahi
     sakti. --}}
@extends('layouts.app')

@section('title', $customer->name)

@section('content')
<div class="content">

    <div class="mb-3">
        <a href="{{ url('/catering/customer-balances') }}" class="text-muted fs-13 text-decoration-none">
            <i class="ti ti-arrow-left"></i> Customer Catering Balances
        </a>
    </div>

    @if(session('status'))
        <div class="alert alert-success py-2 px-3 fs-13">{{ session('status') }}</div>
    @endif
    @foreach(['advance', 'refund'] as $bag)
        @error($bag)<div class="alert alert-danger py-2 px-3 fs-13">{{ $message }}</div>@enderror
    @endforeach

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

    {{-- ── Events, aur har ek par wohi do actions ───────────────────────── --}}
    <div class="card mb-3">
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
                            <th class="text-end">Money</th>
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
                                    {{-- Bill kis cheez par bana — invoice par, ya abhi sirf
                                         quotation par. "300,000 baqi" ka matlab in dono surton
                                         me alag hai: ek jaari shuda bill hai, doosra abhi tak
                                         sirf ek peshkash. --}}
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
                                <td class="text-end text-nowrap">
                                    {{-- Wohi permissions jo booking ki screen dekhti hai. Ek
                                         doosri permission banana "kaun paisa le sakta hai" ka
                                         do jawab bana deta. --}}
                                    @can('tenant.catering.advances.store')
                                        @if($event->status !== 'cancelled')
                                            <button type="button" class="btn btn-sm btn-primary js-advance"
                                                    data-event="{{ $event->id }}" data-no="{{ $event->event_no }}"
                                                    data-balance="{{ $row['balance'] }}">Receive</button>
                                        @endif
                                    @endcan
                                    @can('tenant.catering.refunds.store')
                                        @if($row['credit'] > 0)
                                            <button type="button" class="btn btn-sm btn-outline-secondary js-refund"
                                                    data-event="{{ $event->id }}" data-no="{{ $event->event_no }}"
                                                    data-credit="{{ $row['credit'] }}">Refund</button>
                                        @endif
                                    @endcan
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
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- ── Ledger ───────────────────────────────────────────────────────────
         Har satar kisi mojood document se aati hai — koi satar screen ke liye
         ijaad nahi ki gayi. Running graahak ki satah ka hai: musbat = graahak
         ka lena, manfi = graahak par baqi, aur aakhri satar upar likhe
         Balance/Credit par hi khatam hoti hai. --}}
    <div class="card">
        <div class="card-body pb-1">
            <h5 class="mb-1">Ledger</h5>
            <p class="text-muted fs-13">
                Every payment, refund and invoice across this customer's bookings, oldest first.
                A positive running figure is credit owed to the customer; a negative one is a balance due.
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Booking</th>
                            <th>Entry</th>
                            <th class="text-end">Money in</th>
                            <th class="text-end">Money out</th>
                            <th class="text-end">Billed</th>
                            <th class="text-end">Running</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($ledger as $row)
                            <tr class="{{ ($row['informational'] ?? false) ? 'text-muted' : '' }}">
                                <td class="text-nowrap">{{ $row['date'] }}</td>
                                <td>
                                    <a href="{{ url('/catering/events/'.$row['event_id']) }}"
                                       class="text-decoration-none fs-13">{{ $row['event_no'] }}</a>
                                </td>
                                <td>
                                    {{ $row['type'] }}
                                    @if(!empty($row['reference']))
                                        <span class="text-muted fs-12">· {{ $row['reference'] }}</span>
                                    @endif
                                    @if(!empty($row['note']))
                                        <div class="text-muted fs-12">{{ $row['note'] }}</div>
                                    @endif
                                </td>
                                <td class="text-end">{{ $row['money_in'] > 0 ? number_format($row['money_in'], 2) : '' }}</td>
                                <td class="text-end">{{ $row['money_out'] > 0 ? number_format($row['money_out'], 2) : '' }}</td>
                                <td class="text-end">{{ $row['charged'] > 0 ? number_format($row['charged'], 2) : '' }}</td>
                                <td class="text-end fw-semibold {{ $row['running'] < 0 ? 'text-danger' : ($row['running'] > 0 ? 'text-warning' : '') }}">
                                    {{ number_format($row['running'], 2) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">Nothing has moved on this customer's bookings yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- ── Receive money ────────────────────────────────────────────────────
     Wohi khaane jo booking ki screen bhejti hai, kyunke endpoint wohi hai. --}}
@can('tenant.catering.advances.store')
<div class="modal fade" id="cbAdvanceModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="cbAdvanceForm">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Receive money — <span id="cbAdvanceNo"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted fs-13" id="cbAdvanceHint"></p>
                    <div class="mb-2">
                        <label class="form-label">Amount <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Received date <span class="text-danger">*</span></label>
                        <input type="date" name="received_date" class="form-control" required
                               value="{{ app(\App\Support\TenantClock::class)->now()->format('Y-m-d') }}">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Payment method</label>
                        <select name="payment_method_id" class="form-select">
                            <option value="">—</option>
                            @foreach($paymentMethods as $method)
                                <option value="{{ $method->id }}">{{ $method->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Reference</label>
                        <input type="text" name="reference" class="form-control" placeholder="Slip / transaction #">
                    </div>
                    <div class="alert alert-light border fs-12 mb-0">
                        Recording this <strong>posts to the general ledger</strong> and increases the mapped
                        cash/bank balance. It does not move stock.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Receive</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endcan

{{-- ── Refund ───────────────────────────────────────────────────────────
     Ye WAHID catering action hai jo paisa BAHAR nikalta hai, aur isi liye is
     ki apni permission hai. `return_customer` sirf ek adad hai — controller
     path khud banata hai, is liye yahan se koi URL nahi jata. --}}
@can('tenant.catering.refunds.store')
<div class="modal fade" id="cbRefundModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="cbRefundForm">
            @csrf
            <input type="hidden" name="return_customer" value="{{ $customer->id }}">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Refund customer — <span id="cbRefundNo"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted fs-13" id="cbRefundHint"></p>
                    <div class="mb-2">
                        <label class="form-label">Amount <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="cbRefundAmount" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Refund date <span class="text-danger">*</span></label>
                        <input type="date" name="refund_date" class="form-control" required
                               value="{{ app(\App\Support\TenantClock::class)->now()->format('Y-m-d') }}">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Payment method <span class="text-danger">*</span></label>
                        <select name="payment_method_id" class="form-select" required>
                            <option value="">Select…</option>
                            @foreach($paymentMethods as $method)
                                <option value="{{ $method->id }}">{{ $method->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Reference</label>
                        <input type="text" name="reference" class="form-control" placeholder="Cheque / transaction #">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Reason <span class="text-danger">*</span></label>
                        <input type="text" name="reason" class="form-control" required maxlength="255">
                    </div>
                    <div class="alert alert-light border fs-12 mb-0">
                        Money leaves the mapped cash/bank account and posts to the general ledger.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Refund</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endcan

@push('scripts')
<script>
// Har button apni booking ka id laata hai aur form ka action wahi banta hai.
// Ek hi modal, kyunke khaane har booking par wohi hain — sirf pata badalta hai.
document.querySelectorAll('.js-advance').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var form = document.getElementById('cbAdvanceForm');
        form.action = '/catering/events/' + btn.dataset.event + '/advances';
        document.getElementById('cbAdvanceNo').textContent = btn.dataset.no;
        var balance = parseFloat(btn.dataset.balance || '0');
        document.getElementById('cbAdvanceHint').textContent = balance > 0
            ? 'Outstanding on this booking: ' + balance.toLocaleString(undefined, {minimumFractionDigits: 2})
            : 'Nothing is outstanding on this booking.';
        new bootstrap.Modal(document.getElementById('cbAdvanceModal')).show();
    });
});

document.querySelectorAll('.js-refund').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var form = document.getElementById('cbRefundForm');
        form.action = '/catering/events/' + btn.dataset.event + '/refunds';
        document.getElementById('cbRefundNo').textContent = btn.dataset.no;
        var credit = parseFloat(btn.dataset.credit || '0');
        // Wohi hadd jo service lagati hai — screen par pehle hi likhi, taake
        // operator ko us ka pata form bharne se PEHLE ho, rad hone ke baad nahi.
        document.getElementById('cbRefundAmount').max = credit;
        document.getElementById('cbRefundHint').textContent =
            'Held for this customer on this booking: ' + credit.toLocaleString(undefined, {minimumFractionDigits: 2});
        new bootstrap.Modal(document.getElementById('cbRefundModal')).show();
    });
});
</script>
@endpush
@endsection
