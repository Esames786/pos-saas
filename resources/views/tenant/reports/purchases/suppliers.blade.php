@extends('layouts.app')
@section('title', 'Supplier Purchases')
@section('content')
<div class="page-header">
    <div class="page-title">
        <h4>Supplier Purchases</h4>
        <h6>Purchase summary per supplier</h6>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <caption class="visually-hidden">Supplier purchase summary</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col">Supplier</th>
                    <th scope="col">Phone</th>
                    <th scope="col" class="text-end">Bills</th>
                    <th scope="col" class="text-end">Total Purchases</th>
                    <th scope="col" class="text-end">Total Paid</th>
                    <th scope="col">Last Purchase</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                <tr>
                    <td>{{ $r->supplier_name }}</td>
                    <td class="text-muted small">{{ $r->phone ?? '—' }}</td>
                    <td class="text-end">{{ number_format($r->bill_count) }}</td>
                    <td class="text-end fw-semibold">{{ number_format($r->total_purchases, 2) }}</td>
                    <td class="text-end">{{ number_format($r->total_paid, 2) }}</td>
                    <td class="text-muted small">{{ $r->last_purchase_date ? \Carbon\Carbon::parse($r->last_purchase_date)->format('d/m/Y') : '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No supplier data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
