@extends('layouts.app')

@section('title', 'Supplier Ledger: ' . $supplier->name)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Supplier Ledger</h1>
        <p class="fw-medium">
            {{ $supplier->name }} &mdash; Current Balance:
            <strong>{{ number_format($supplier->current_balance, 2) }}</strong>
        </p>
    </div>
    <div class="action-toolbar">
        {{-- Payment ka seedha raasta. Purchase bill ki koi zaroorat nahi — supplier ko uski khuli balance par paisa diya ja sakta hai. --}}
        <a href="{{ url('/supplier-payments/create') }}?supplier_id={{ $supplier->id }}&from=ledger"
           class="btn btn-primary">Record Payment</a>
        <a href="{{ url('/suppliers/' . $supplier->id) }}" class="btn btn-light">Back</a>
    </div>
</div>

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
                    <td>{{ $ledger->created_at->format('Y-m-d H:i') }}</td>
                    <td>{{ str_replace('_', ' ', ucfirst($ledger->entry_type)) }}</td>
                    <td>{{ $ledger->reference_no ?: '—' }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($ledger->notes ?: '—', 60) }}</td>
                    <td>{{ $ledger->direction === 'debit'  ? number_format($ledger->amount, 2) : '—' }}</td>
                    <td>{{ $ledger->direction === 'credit' ? number_format($ledger->amount, 2) : '—' }}</td>
                    <td>{{ number_format($ledger->balance_after, 2) }}</td>
                    <td>{{ $ledger->createdBy?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">No ledger entries found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="p-3">{{ $ledgers->links() }}</div>
    </div>
</div>
@endsection
