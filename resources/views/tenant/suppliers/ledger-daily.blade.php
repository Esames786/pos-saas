@extends('layouts.app')

@section('title', 'Supplier Ledger: ' . ($supplier?->name ?? 'All suppliers'))

@section('content')
{{-- SUPPLIER-LEDGER-DAILY-1 — every row on the date of its own document (invoice / payment / return / journal), the
     balance worked out in that order, a "day by day" view and "All suppliers". Column labels follow
     SUPPLIER-LEDGER-DRCR-LABELS-1: a row that raised what we owe shows under Credit, one that lowered it under Debit. --}}
@php
    $base = url('/suppliers/ledger');
    $ledgerUrl = $supplier ? url('/suppliers/' . $supplier->id . '/ledger') : $base;
    $label = fn ($v) => \App\Services\Purchasing\SupplierRunningAccountService::balanceLabel((float) $v);
    $clock = app(\App\Support\TenantClock::class);
    $pageUrl = fn (array $q) => $ledgerUrl . '?' . http_build_query(array_merge(['from' => $from, 'to' => $to, 'view' => $view], $q));
@endphp

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">Supplier Ledger</h1>
        <p class="fw-medium mb-0">
            @if($supplier)
                {{ $supplier->name }} &mdash; Balance today: <strong>{{ $label($supplier->current_balance) }}</strong>
            @else
                All suppliers &mdash; Total today: <strong>{{ $label($suppliers->isEmpty() ? 0 : \App\Models\Tenant\Supplier::sum('current_balance')) }}</strong>
            @endif
        </p>
    </div>
    <div class="action-toolbar">
        @if($supplier)
            <a href="{{ url('/supplier-payments/create') }}?supplier_id={{ $supplier->id }}&from=ledger" class="btn btn-primary">Record Payment</a>
            <a href="{{ url('/suppliers/' . $supplier->id) }}" class="btn btn-light">Back</a>
        @else
            <a href="{{ url('/suppliers') }}" class="btn btn-light">Back</a>
        @endif
    </div>
</div>

<form method="GET" action="{{ $ledgerUrl }}" class="card mb-3" id="ledger-filter">
    <div class="card-body d-flex flex-wrap align-items-end gap-3">
        <div>
            <label class="form-label mb-1" for="ledger-supplier">Supplier</label>
            <select id="ledger-supplier" class="form-select" data-base="{{ $base }}" data-one="{{ url('/suppliers') }}">
                @can('tenant.suppliers.ledger-all')
                    <option value="all" @selected(! $supplier)>All suppliers</option>
                @endcan
                @foreach($suppliers as $s)
                    <option value="{{ $s->id }}" @selected($supplier && $supplier->id === $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label mb-1" for="ledger-from">From</label>
            <input type="date" id="ledger-from" name="from" value="{{ $from }}" class="form-control">
        </div>
        <div>
            <label class="form-label mb-1" for="ledger-to">To</label>
            <input type="date" id="ledger-to" name="to" value="{{ $to }}" class="form-control">
        </div>
        <div class="btn-group" role="group" aria-label="View">
            <input type="radio" class="btn-check" name="view" id="view-days" value="days" @checked($view === 'days')>
            <label class="btn btn-outline-primary" for="view-days">Day by day</label>
            <input type="radio" class="btn-check" name="view" id="view-entries" value="entries" @checked($view === 'entries')>
            <label class="btn btn-outline-primary" for="view-entries">Every entry</label>
        </div>
        <button type="submit" class="btn btn-primary">Show</button>
    </div>
    @if($errors->any())
        <div class="alert alert-danger m-3 mt-0">{{ $errors->first() }}</div>
    @endif
</form>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2">
        <div class="text-muted small">Opening ({{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }})</div>
        <div class="fs-5 fw-semibold" data-ledger-opening>{{ $label($ledger['opening']) }}</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2">
        <div class="text-muted small">Credit — invoices added</div>
        <div class="fs-5 fw-semibold">{{ number_format($ledger['credit'], 2) }}</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2">
        <div class="text-muted small">Debit — paid / returned</div>
        <div class="fs-5 fw-semibold">{{ number_format($ledger['debit'], 2) }}</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2">
        <div class="text-muted small">Closing ({{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }})</div>
        <div class="fs-5 fw-semibold">{{ $label($ledger['closing']) }}</div>
    </div></div></div>
</div>

<div class="card">
    <div class="card-body table-responsive p-0">
        @if($view === 'days')
            <table class="table table-nowrap align-middle mb-0" data-ledger-view="days">
                <caption class="visually-hidden">Supplier ledger, one row per day</caption>
                <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col" class="text-end">Opening</th>
                    <th scope="col" class="text-end">Credit (invoices)</th>
                    <th scope="col" class="text-end">Debit (paid / returned)</th>
                    <th scope="col" class="text-end">Closing</th>
                    <th scope="col"></th>
                </tr>
                </thead>
                <tbody>
                @forelse($ledger['days'] as $d)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($d['day'])->format('D, d M Y') }}</td>
                        <td class="text-end">{{ $label($d['opening']) }}</td>
                        <td class="text-end">{{ $d['credit'] > 0 ? number_format($d['credit'], 2) : '—' }}</td>
                        <td class="text-end">{{ $d['debit'] > 0 ? number_format($d['debit'], 2) : '—' }}</td>
                        <td class="text-end fw-semibold">{{ $label($d['closing']) }}</td>
                        <td><a href="{{ $pageUrl(['view' => 'entries', 'from' => $d['day'], 'to' => $d['day']]) }}">{{ $d['rows'] }} {{ $d['rows'] === 1 ? 'entry' : 'entries' }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">Nothing between these dates.</td></tr>
                @endforelse
                </tbody>
            </table>
        @else
            <table class="table table-nowrap align-middle mb-0" data-ledger-view="entries">
                <caption class="visually-hidden">Supplier ledger entries</caption>
                <thead>
                <tr>
                    <th scope="col">Date</th>
                    @unless($supplier)<th scope="col">Supplier</th>@endunless
                    <th scope="col">Type</th>
                    <th scope="col">Reference</th>
                    <th scope="col">Description</th>
                    <th scope="col" class="text-end">Debit</th>
                    <th scope="col" class="text-end">Credit</th>
                    <th scope="col" class="text-end">Balance</th>
                    <th scope="col">User</th>
                </tr>
                </thead>
                <tbody>
                @forelse($ledger['rows'] as $row)
                    <tr>
                        <td>
                            {{ \Illuminate\Support\Carbon::parse($row->day)->format('d M Y') }}
                            <div class="small text-muted">typed {{ $clock->format($row->created_at, 'd M H:i') }}</div>
                        </td>
                        @unless($supplier)<td>{{ $row->supplier_name ?? '—' }}</td>@endunless
                        <td>{{ $row->kind }}</td>
                        <td>
                            @if($row->url)
                                <a href="{{ $row->url }}">{{ $row->reference_no ?: '—' }}</a>
                            @else
                                {{ $row->reference_no ?: '—' }}
                            @endif
                        </td>
                        <td>{{ \Illuminate\Support\Str::limit($row->notes ?: '—', 60) }}</td>
                        <td class="text-end">{{ $row->direction === 'credit' ? number_format((float) $row->amount, 2) : '—' }}</td>
                        <td class="text-end">{{ $row->direction === 'debit' ? number_format((float) $row->amount, 2) : '—' }}</td>
                        <td class="text-end">{{ $label($row->running) }}</td>
                        <td>{{ $row->user_name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $supplier ? 8 : 9 }}" class="text-center text-muted py-4">Nothing between these dates.</td></tr>
                @endforelse
                </tbody>
            </table>
        @endif
    </div>
</div>

<script>
    // The supplier box changes which ledger the page shows; the dates and the view stay as chosen.
    document.getElementById('ledger-supplier').addEventListener('change', function () {
        var form = document.getElementById('ledger-filter');
        form.action = this.value === 'all' ? this.dataset.base : this.dataset.one + '/' + this.value + '/ledger';
        form.submit();
    });
</script>
@endsection
