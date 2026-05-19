@extends('layouts.app')

@section('title', 'Purchase Bills')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Purchase Bills</h1>
        <p class="fw-medium">Purchase bills create supplier payable ledger entries.</p>
    </div>
    @can('tenant.purchase-bills.create')
        <a href="{{ url('/purchase-bills/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Bill
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
        <form method="GET" action="{{ url('/purchase-bills') }}" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="bill-supplier" class="form-label">Supplier</label>
                <select id="bill-supplier" name="supplier_id" class="form-select">
                    <option value="">All Suppliers</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(request('supplier_id') == $supplier->id)>
                            {{ $supplier->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="bill-status" class="form-label">Status</label>
                <select id="bill-status" name="status" class="form-select">
                    <option value="">All</option>
                    @foreach(['draft', 'posted', 'partial', 'paid'] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-dark" type="submit">Filter</button>
                <a href="{{ url('/purchase-bills') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Purchase bill list</caption>
            <thead>
            <tr>
                <th scope="col">Bill No</th>
                <th scope="col">Supplier</th>
                <th scope="col">GRN</th>
                <th scope="col">Date</th>
                <th scope="col">Total</th>
                <th scope="col">Paid</th>
                <th scope="col">Balance</th>
                <th scope="col">Status</th>
                <th scope="col" class="text-end">Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse($bills as $bill)
                <tr>
                    <td><code>{{ $bill->bill_no }}</code></td>
                    <td>{{ $bill->supplier?->name }}</td>
                    <td>{{ $bill->goodsReceipt?->grn_no ?? '—' }}</td>
                    <td>{{ $bill->bill_date?->format('Y-m-d') }}</td>
                    <td>{{ number_format($bill->grand_total, 2) }}</td>
                    <td>{{ number_format($bill->amount_paid, 2) }}</td>
                    <td>{{ number_format($bill->balance_due, 2) }}</td>
                    <td>
                        <span class="badge bg-{{ match($bill->status) {
                            'paid' => 'success', 'partial' => 'warning', 'draft' => 'secondary', default => 'primary'
                        } }} {{ $bill->status === 'partial' ? 'text-dark' : '' }}">
                            {{ ucfirst($bill->status) }}
                        </span>
                    </td>
                    <td class="text-end">
                        @can('tenant.purchase-bills.show')
                            <a href="{{ url('/purchase-bills/' . $bill->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                        @if(in_array($bill->status, ['posted', 'partial']))
                            @can('tenant.supplier-payments.create')
                                <a href="{{ url('/supplier-payments/create?purchase_bill_id=' . $bill->id) }}"
                                   class="btn btn-sm btn-primary">Pay</a>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">No purchase bills found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $bills->links() }}</div>
    </div>
</div>
@endsection
