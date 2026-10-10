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
                    <thead class="table-light"><tr><th>Date</th><th>Type</th><th>Ref</th><th>From / to</th><th class="text-end">In</th><th class="text-end">Out</th><th class="text-end">Balance</th></tr></thead>
                    <tbody>
                        <tr class="table-light"><td colspan="6" class="text-muted small">Balance brought forward</td><td class="text-end fw-semibold">{{ $money($broughtForward) }}</td></tr>
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
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light fw-bold"><tr><td colspan="4">Period total · {{ $summary[$id]['account']->name }}</td><td class="text-end">{{ $money($summary[$id]['in']) }}</td><td class="text-end">{{ $money($summary[$id]['out']) }}</td><td class="text-end">{{ $money($summary[$id]['closing']) }}</td></tr></tfoot>
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
@endsection
