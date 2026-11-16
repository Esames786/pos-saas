@extends('layouts.app')
@section('title', 'Cash Book')
@section('content')
@php
    // CASH-BOOK-REPORT-1 — the same filters drive the screen, the CSV and the print.
    $query = array_filter([
        'date_from' => $filters['date_from'], 'date_to' => $filters['date_to'], 'account_ids' => $filters['account_ids'],
        'direction' => $filters['direction'], 'mode' => $filters['mode'], 'branch_ids' => $selectedBranchIds,
    ], fn ($v) => $v !== [] && $v !== null && $v !== '');
    $link = fn (array $extra) => url('/reports/cash-book') . '?' . http_build_query(array_merge($query, $extra));
    $money = fn ($v) => number_format((float) $v, 2);
    $typeName = ['cash' => 'Cash', 'bank' => 'Bank', 'card' => 'Card', 'wallet' => 'Wallet', 'other' => 'Other'];
@endphp
<div class="page-header">
    <div class="page-title">
        <h4>Cash Book</h4>
        <h6>Money in, money out and the balance left — per cash drawer and bank, {{ \Illuminate\Support\Carbon::parse($filters['date_from'])->format('d M Y') }} to {{ \Illuminate\Support\Carbon::parse($filters['date_to'])->format('d M Y') }}</h6>
    </div>
    <div class="page-btn d-flex gap-2">
        <a href="{{ $link(['format' => 'csv']) }}" class="btn btn-outline-success btn-sm" id="cash-book-csv"><i class="ti ti-download me-1"></i>CSV</a>
        <a href="{{ $link(['format' => 'print']) }}" target="_blank" class="btn btn-outline-secondary btn-sm" id="cash-book-print"><i class="ti ti-printer me-1"></i>Print</a>
    </div>
</div>

{{-- CASH-BOOK-VOID-1 — kisi void ki nakami (jaise invoice ke baad receipt) yahin wapas aati hai. --}}
@if($errors->any())
    <div class="alert alert-danger" id="cash-book-errors">
        @foreach($errors->all() as $err)<div>{{ $err }}</div>@endforeach
    </div>
@endif

<div class="card">
    <div class="card-body">
        <form method="GET" action="{{ url('/reports/cash-book') }}" class="row g-2 align-items-end" id="cash-book-filters">
            <div class="col-sm-2">
                <label class="form-label mb-1">From</label>
                <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] }}">
            </div>
            <div class="col-sm-2">
                <label class="form-label mb-1">To</label>
                <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] }}">
            </div>
            <div class="col-sm-3">
                <label class="form-label mb-1">Cash / Bank <span class="text-muted small">({{ $filters['account_ids'] ? count($filters['account_ids']) . ' chosen' : 'all' }})</span></label>
                <div class="border rounded p-2" style="max-height:130px; overflow-y:auto;" id="cash-book-accounts">
                    @foreach($accountOptions as $acc)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="account_ids[]" value="{{ $acc->id }}" id="cba-{{ $acc->id }}" @checked(in_array($acc->id, $filters['account_ids'], true))>
                            <label class="form-check-label small" for="cba-{{ $acc->id }}">{{ $acc->name }} <span class="text-muted">· {{ $typeName[$acc->account_type] ?? $acc->account_type }}</span></label>
                        </div>
                    @endforeach
                </div>
            </div>
            @if($branches->count() > 1)
                @include('tenant.partials.branch-multiselect', ['branches' => $branches, 'selectedBranchIds' => $selectedBranchIds, 'colClass' => 'col-sm-2'])
            @endif
            <div class="col-sm-1">
                <label class="form-label mb-1">Show</label>
                <select name="direction" class="form-select">
                    @foreach(['all' => 'All', 'in' => 'In', 'out' => 'Out'] as $v => $l)
                        <option value="{{ $v }}" @selected($filters['direction'] === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-1">
                <label class="form-label mb-1">View</label>
                <select name="mode" class="form-select">
                    <option value="entries" @selected($filters['mode'] === 'entries')>Entries</option>
                    <option value="daily" @selected($filters['mode'] === 'daily')>Day-wise</option>
                </select>
            </div>
            <div class="col-sm-1">
                <button type="submit" class="btn btn-primary w-100">Apply</button>
            </div>
        </form>
    </div>
</div>

{{-- Totals of every cash drawer / bank chosen --}}
<div class="row g-3 mb-3" id="cash-book-totals">
    @foreach(['opening' => ['Opening balance', 'text-dark'], 'in' => ['Money in', 'text-success'], 'out' => ['Money out', 'text-danger'], 'closing' => ['Closing balance', 'text-primary']] as $k => [$label, $cls])
        <div class="col-6 col-md-3">
            <div class="card mb-0"><div class="card-body py-3">
                <div class="text-muted small">{{ $label }}</div>
                <div class="fs-4 fw-bold {{ $cls }}" id="cash-book-total-{{ $k }}">{{ $money($totals[$k]) }}</div>
            </div></div>
        </div>
    @endforeach
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0" id="cash-book-summary">
                <thead class="table-light">
                    <tr><th>Cash / Bank</th><th class="text-end">Opening</th><th class="text-end">In</th><th class="text-end">Out</th><th class="text-end">Closing</th><th class="text-end">Entries</th></tr>
                </thead>
                <tbody>
                    @forelse($summary as $id => $s)
                        <tr>
                            <td>{{ $s['account']->name }} <span class="text-muted small">· {{ $typeName[$s['account']->account_type] ?? $s['account']->account_type }}{{ $s['account']->bank_name ? ' · ' . $s['account']->bank_name : '' }}</span></td>
                            <td class="text-end">{{ $money($s['opening']) }}</td>
                            <td class="text-end text-success">{{ $money($s['in']) }}</td>
                            <td class="text-end text-danger">{{ $money($s['out']) }}</td>
                            <td class="text-end fw-semibold">{{ $money($s['closing']) }}</td>
                            <td class="text-end text-muted">{{ number_format($s['entries']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No cash or bank accounts.</td></tr>
                    @endforelse
                </tbody>
                @if(count($summary) > 1)
                    <tfoot class="table-light fw-bold">
                        <tr><td>Total</td><td class="text-end">{{ $money($totals['opening']) }}</td><td class="text-end">{{ $money($totals['in']) }}</td><td class="text-end">{{ $money($totals['out']) }}</td><td class="text-end">{{ $money($totals['closing']) }}</td><td class="text-end">{{ number_format($entryCount) }}</td></tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>

@if($mode === 'daily')
    @if(! request()->filled('mode') && $entryCount > \App\Http\Controllers\Tenant\Reports\CashBookController::DAILY_ABOVE)
        <div class="alert alert-light border small mt-3 mb-0">{{ number_format($entryCount) }} entries in this period, so it opens day by day. Click a day to see its entries.</div>
    @endif
    @foreach($summary as $id => $s)
        @continue(empty($daily[$id]))
        <div class="card border-0 shadow-sm mt-3 cash-book-daily" data-account="{{ $id }}">
            <div class="card-header bg-white"><strong>{{ $s['account']->name }}</strong> <span class="text-muted small">· opening {{ $money($s['opening']) }}</span></div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Date</th><th class="text-end">Opening</th><th class="text-end">In</th><th class="text-end">Out</th><th class="text-end">Closing</th><th class="text-end">Entries</th></tr></thead>
                    <tbody>
                        @foreach($daily[$id] as $d)
                            <tr>
                                <td><a href="{{ url('/reports/cash-book') . '?' . http_build_query(['date_from' => $d['date'], 'date_to' => $d['date'], 'account_ids' => [$id], 'direction' => $filters['direction'], 'mode' => 'entries']) }}">{{ \Illuminate\Support\Carbon::parse($d['date'])->format('d M Y') }}</a></td>
                                <td class="text-end">{{ $money($d['opening']) }}</td>
                                <td class="text-end text-success">{{ $d['in'] ? $money($d['in']) : '' }}</td>
                                <td class="text-end text-danger">{{ $d['out'] ? $money($d['out']) : '' }}</td>
                                <td class="text-end fw-semibold">{{ $money($d['closing']) }}</td>
                                <td class="text-end text-muted">{{ $d['entries'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light fw-bold"><tr><td>Period</td><td class="text-end">{{ $money($s['opening']) }}</td><td class="text-end">{{ $money($s['in']) }}</td><td class="text-end">{{ $money($s['out']) }}</td><td class="text-end">{{ $money($s['closing']) }}</td><td class="text-end">{{ number_format($s['entries']) }}</td></tr></tfoot>
                </table>
            </div></div>
        </div>
    @endforeach
@else
    @php $byAccount = $entries ? $entries->getCollection()->groupBy('cash_bank_account_id') : collect(); @endphp
    @forelse($byAccount as $id => $rows)
        @php $first = $rows->first(); $broughtForward = round($first->balance - ($first->direction === 'in' ? (float) $first->amount : -(float) $first->amount), 2); @endphp
        <div class="card border-0 shadow-sm mt-3 cash-book-entries" data-account="{{ $id }}">
            <div class="card-header bg-white"><strong>{{ $summary[$id]['account']->name }}</strong></div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>Type</th><th>Ref</th><th>From / to</th><th class="text-end">In</th><th class="text-end">Out</th><th class="text-end">Balance</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                        <tr class="table-light"><td colspan="6" class="text-muted small">Balance brought forward</td><td class="text-end fw-semibold">{{ $money($broughtForward) }}</td><td></td></tr>
                        @foreach($rows as $e)
                            <tr>
                                <td class="text-nowrap">{{ \Illuminate\Support\Carbon::parse($e->transaction_date)->format('d M Y') }}</td>
                                <td class="small">{{ $e->type_label }}</td>
                                <td class="small text-nowrap">
                                    @if($e->url && $e->route && auth()->user()?->can($e->route))<a href="{{ url($e->url) }}">{{ $e->ref }}</a>@else{{ $e->ref }}@endif
                                </td>
                                <td class="small">{{ $e->party }}</td>
                                <td class="text-end text-success">{{ $e->direction === 'in' ? $money($e->amount) : '' }}</td>
                                <td class="text-end text-danger">{{ $e->direction === 'out' ? $money($e->amount) : '' }}</td>
                                <td class="text-end fw-semibold">{{ $money($e->balance) }}</td>
                                {{-- CASH-BOOK-VOID-1 — ghalat entry yahin se ulti. Har void apne mojooda darwaze par jata hai. --}}
                                <td class="text-end text-nowrap">
                                    @if($e->void)
                                        @if($e->void['voided'])
                                            <span class="badge bg-secondary">{{ $e->void['kind'] === 'manual_journal' ? 'Reversed' : 'Voided' }}</span>
                                            @if(in_array($e->void['kind'], ['manual_journal', 'expense', 'supplier_payment', 'catering_receipt'], true))
                                                @include('tenant.finance.voided-entries.partials.purge-button', [
                                                    'purgeType' => $e->void['kind'], 'purgeId' => $e->void['doc'],
                                                    'purgeLabel' => $e->type_label . ' ' . $e->ref, 'purgeAmount' => $money($e->amount),
                                                    'purgeDate' => \Illuminate\Support\Carbon::parse($e->transaction_date)->format('d M Y'),
                                                ])
                                            @endif
                                        @elseif(auth()->user()?->can($e->void['can']))
                                            @if($e->void['action'])
                                                <button type="button" class="btn btn-sm btn-outline-danger cash-book-void"
                                                    data-action="{{ url($e->void['action']) }}" data-kind="{{ $e->void['kind'] }}"
                                                    data-ref="{{ $e->ref }}" data-party="{{ $e->party }}" data-type="{{ $e->type_label }}"
                                                    data-date="{{ \Illuminate\Support\Carbon::parse($e->transaction_date)->format('d M Y') }}"
                                                    data-amount="{{ $money($e->amount) }}" data-direction="{{ $e->direction }}"
                                                    data-drawer="{{ $summary[$id]['account']->name }}">Void</button>
                                            @else
                                                <a href="{{ url($e->void['open']) }}" class="btn btn-sm btn-outline-secondary" title="A POS sale is cancelled on its own page, with manager approval">Cancel on sale</a>
                                            @endif
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light fw-bold"><tr><td colspan="4">Period total · {{ $summary[$id]['account']->name }}</td><td class="text-end">{{ $money($summary[$id]['in']) }}</td><td class="text-end">{{ $money($summary[$id]['out']) }}</td><td class="text-end">{{ $money($summary[$id]['closing']) }}</td><td></td></tr></tfoot>
                </table>
            </div></div>
        </div>
    @empty
        <div class="card mt-3"><div class="card-body text-center text-muted">No money moved in this period.</div></div>
    @endforelse
    @if($entries && $entries->hasPages())
        <div class="mt-3">{{ $entries->appends($query)->links() }}</div>
    @endif
@endif

{{-- CASH-BOOK-VOID-1 — ek hi modal, har satar ka button isay bhar kar khol-ta hai. Form usi satar ke
     mojooda void darwaze par jata hai (receipt / expense / journal / supplier payment) — wohi ijazat,
     wohi hisaab — aur `return_to` kaam ke baad yahin, isi tareekh par wapas laata hai. --}}
<div class="modal fade" id="cashBookVoidModal" tabindex="-1" aria-labelledby="cashBookVoidTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="" id="cash-book-void-form">
                @csrf
                <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
                <input type="hidden" name="void_reason" id="cash-book-void-reason-copy" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="cashBookVoidTitle">Void this entry?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <dl class="row small mb-2">
                        <dt class="col-4">Date</dt><dd class="col-8" id="cbv-date"></dd>
                        <dt class="col-4">Type</dt><dd class="col-8" id="cbv-type"></dd>
                        <dt class="col-4">Ref</dt><dd class="col-8" id="cbv-ref"></dd>
                        <dt class="col-4">From / to</dt><dd class="col-8" id="cbv-party"></dd>
                        <dt class="col-4">Amount</dt><dd class="col-8 fw-semibold" id="cbv-amount"></dd>
                    </dl>
                    <div class="alert alert-warning small mb-3" id="cbv-effect"></div>
                    {{-- VOID-ON-ORIGINAL-DATE-1: the row's own date is filled in when the modal opens. --}}
                    @include('partials.void-date-choice', ['originalDate' => null, 'idPrefix' => 'cbv'])
                    <label for="cash-book-void-reason" class="form-label">Why is it being voided? <span class="text-danger">*</span></label>
                    <textarea name="reason" id="cash-book-void-reason" class="form-control" rows="2" required minlength="3" maxlength="255"
                        placeholder="e.g. entered twice, wrong amount, wrong drawer"></textarea>
                    <div class="form-text">The original entry stays in the books, marked voided, with your reason on it.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="cash-book-void-submit">Void entry</button>
                </div>
            </form>
        </div>
    </div>
</div>

@include('tenant.finance.voided-entries.partials.purge-modal')
@include('tenant.catering.partials.submit-guard')
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('cashBookVoidModal');
    var form = document.getElementById('cash-book-void-form');
    if (!modalEl || !form || !window.bootstrap) return;
    var modal = new bootstrap.Modal(modalEl);

    // Har qism ka void kya karega — asaan alfaaz me, taake dabane se pehle maloom ho.
    function effect(d) {
        var amt = d.amount, drawer = d.drawer;
        switch (d.kind) {
            case 'supplier_payment':
                return amt + ' comes back into ' + drawer + ', the supplier owes it again, and any bill it paid opens again.';
            case 'catering_receipt':
                return amt + ' goes back out of ' + drawer + ' and the customer owes it again. Not possible once the booking\'s final invoice is issued — use Refund then.';
            case 'expense':
                return amt + ' comes back into ' + drawer + ' and the expense leaves the books.';
            case 'manual_journal':
                return 'The whole journal ' + d.ref + ' is reversed — all its lines, not only this one.';
        }
        return '';
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.cash-book-void');
        if (!btn) return;
        var d = btn.dataset;
        form.setAttribute('action', d.action);
        document.getElementById('cbv-date').textContent = d.date || '';
        document.getElementById('cbv-type').textContent = d.type || '';
        document.getElementById('cbv-ref').textContent = d.ref || '—';
        document.getElementById('cbv-party').textContent = d.party || '—';
        document.getElementById('cbv-amount').textContent = (d.direction === 'in' ? 'In ' : 'Out ') + d.amount + ' · ' + d.drawer;
        document.getElementById('cbv-effect').textContent = effect(d);
        document.getElementById('cash-book-void-reason').value = '';
        // Ask afresh for every row, with that row's own date.
        form.querySelectorAll('[name=void_date_mode]').forEach(function (r) { r.checked = false; });
        form.querySelectorAll('[data-vd-original-text]').forEach(function (el) { el.textContent = d.date || 'its own date'; });
        modal.show();
    });

    // Expense ka darwaza wajah `void_reason` naam se leta hai, baqi `reason` se — dono bhar do.
    form.addEventListener('submit', function () {
        document.getElementById('cash-book-void-reason-copy').value = document.getElementById('cash-book-void-reason').value;
    });
});
</script>
@endpush
