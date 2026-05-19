@extends('layouts.app')

@section('title', 'Supplier Payments')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Supplier Payments</h1>
        <p class="fw-medium">Record cash, bank transfer, cheque, card, or other supplier payments.</p>
    </div>
    @can('tenant.supplier-payments.create')
        <a href="{{ url('/supplier-payments/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Payment
        </a>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/supplier-payments') }}" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="pay-supplier" class="form-label">Supplier</label>
                <select id="pay-supplier" name="supplier_id" class="form-select">
                    <option value="">All Suppliers</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(request('supplier_id') == $supplier->id)>
                            {{ $supplier->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-dark" type="submit">Filter</button>
                <a href="{{ url('/supplier-payments') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Supplier payment list</caption>
            <thead>
            <tr>
                <th scope="col">Payment No</th>
                <th scope="col">Supplier</th>
                <th scope="col">Bill</th>
                <th scope="col">Date</th>
                <th scope="col">Method</th>
                <th scope="col">Amount</th>
                <th scope="col" class="text-end">Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse($payments as $payment)
                <tr>
                    <td><code>{{ $payment->payment_no }}</code></td>
                    <td>{{ $payment->supplier?->name }}</td>
                    <td>{{ $payment->bill?->bill_no ?? '—' }}</td>
                    <td>{{ $payment->payment_date?->format('Y-m-d') }}</td>
                    <td>{{ str_replace('_', ' ', ucfirst($payment->payment_method)) }}</td>
                    <td>{{ number_format($payment->amount, 2) }}</td>
                    <td class="text-end">
                        @can('tenant.supplier-payments.show')
                            <a href="{{ url('/supplier-payments/' . $payment->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No supplier payments found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $payments->links() }}</div>
    </div>
</div>
@endsection
