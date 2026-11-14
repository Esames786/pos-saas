@extends('layouts.app')

@section('title', 'Supplier Ledger: ' . $supplier->name)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Supplier Ledger</h1>
        <p class="fw-medium">
            {{ $supplier->name }} &mdash; Current Balance:
            <strong>{{ \App\Services\Purchasing\SupplierRunningAccountService::balanceLabel((float) $supplier->current_balance) }}</strong>
        </p>
    </div>
    <div class="action-toolbar">
        {{-- Payment ka seedha raasta. Purchase bill ki koi zaroorat nahi — supplier ko uski khuli balance par paisa diya ja sakta hai. --}}
        <a href="{{ url('/supplier-payments/create') }}?supplier_id={{ $supplier->id }}&from=ledger"
           class="btn btn-primary">Record Payment</a>
        <a href="{{ url('/suppliers/' . $supplier->id) }}" class="btn btn-light">Back</a>
    </div>
</div>

@php
    $clock = app(\App\Support\TenantClock::class);
    $ledgerUrl = url('/suppliers/' . $supplier->id . '/ledger');
    $today = $clock->now()->toDateString();
    $yesterday = \Illuminate\Support\Carbon::parse($today)->subDay()->toDateString();
    $isDay = fn (string $d) => $dateFrom === $d && $dateTo === $d;
@endphp

{{-- SUPPLIER-LEDGER-RANGE-1: From / To + Today / Yesterday. The dates are the Date column's (the day it was typed). --}}
<form method="GET" action="{{ $ledgerUrl }}" class="card mb-3" id="supplier-ledger-range">
    <div class="card-body d-flex flex-wrap align-items-end gap-2">
        <div>
            <label for="ledger-date-from" class="form-label mb-1">From</label>
            <input type="date" id="ledger-date-from" name="date_from" value="{{ $dateFrom }}" class="form-control">
        </div>
        <div>
            <label for="ledger-date-to" class="form-label mb-1">To</label>
            <input type="date" id="ledger-date-to" name="date_to" value="{{ $dateTo }}" class="form-control">
        </div>
        <button type="submit" class="btn btn-dark">Show</button>
        <button type="submit" name="range" value="today" class="btn {{ $isDay($today) ? 'btn-primary' : 'btn-outline-secondary' }}">Today</button>
        <button type="submit" name="range" value="yesterday" class="btn {{ $isDay($yesterday) ? 'btn-primary' : 'btn-outline-secondary' }}">Yesterday</button>
        <a href="{{ $ledgerUrl }}" class="btn btn-light">All dates</a>
    </div>
</form>

<div class="card">
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Supplier ledger entries</caption>
            <thead>
            <tr>
                <th scope="col">Date</th>
                <th scope="col">Type</th>
                <th scope="col">Reference</th>
                <th scope="col">Description</th>
                <th scope="col">Debit</th>
                <th scope="col">Credit</th>
                <th scope="col">Balance</th>
                <th scope="col">User</th>
            </tr>
            </thead>
            <tbody>
            @forelse($ledgers as $ledger)
                <tr>
                    {{-- Stored in UTC; shown in the shop's time so Today / Yesterday and this column agree. --}}
                    <td>{{ $clock->format($ledger->created_at, 'Y-m-d H:i') }}</td>
                    <td>{{ str_replace('_', ' ', ucfirst($ledger->entry_type)) }}</td>
                    <td>{{ $ledger->reference_no ?: '—' }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($ledger->notes ?: '—', 60) }}</td>
                    {{-- SUPPLIER-LEDGER-DRCR-LABELS-1 — supplier_ledgers.direction payable ka AAINA hai:
                         'debit' = payable barha = supplier ka khaata CREDIT (bill, opening);
                         'credit' = payable ghata = supplier DEBIT (payment, return). Stored value nahi badla. --}}
                    <td>{{ $ledger->direction === 'credit' ? number_format($ledger->amount, 2) : '—' }}</td>
                    <td>{{ $ledger->direction === 'debit'  ? number_format($ledger->amount, 2) : '—' }}</td>
                    <td>{{ \App\Services\Purchasing\SupplierRunningAccountService::balanceLabel((float) $ledger->balance_after) }}</td>
                    <td>{{ $ledger->createdBy?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">{{ $dateFrom || $dateTo ? 'No ledger entries between these dates.' : 'No ledger entries found.' }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="p-3">{{ $ledgers->links() }}</div>
    </div>
</div>
@endsection
