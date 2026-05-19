@extends('layouts.app')

@section('title', 'Purchase Orders')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Purchase Orders</h1>
        <p class="fw-medium">Create and approve supplier purchase orders. Purchase orders do not affect stock.</p>
    </div>
    @can('tenant.purchase-orders.create')
        <a href="{{ url('/purchase-orders/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>Create PO
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
        <form method="GET" action="{{ url('/purchase-orders') }}" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="po-supplier" class="form-label">Supplier</label>
                <select id="po-supplier" name="supplier_id" class="form-select">
                    <option value="">All Suppliers</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(request('supplier_id') == $supplier->id)>
                            {{ $supplier->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="po-status" class="form-label">Status</label>
                <select id="po-status" name="status" class="form-select">
                    <option value="">All</option>
                    @foreach(['draft', 'approved', 'received', 'cancelled'] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-dark" type="submit">Filter</button>
                <a href="{{ url('/purchase-orders') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Purchase order list</caption>
            <thead>
            <tr>
                <th scope="col">PO No</th>
                <th scope="col">Supplier</th>
                <th scope="col">Branch</th>
                <th scope="col">Date</th>
                <th scope="col">Total</th>
                <th scope="col">Status</th>
                <th scope="col" class="text-end">Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse($orders as $order)
                <tr>
                    <td><code>{{ $order->po_no }}</code></td>
                    <td>{{ $order->supplier?->name }}</td>
                    <td>{{ $order->branch?->name }}</td>
                    <td>{{ $order->order_date?->format('Y-m-d') }}</td>
                    <td>{{ number_format($order->total_amount, 2) }}</td>
                    <td>
                        <span class="badge bg-{{ match($order->status) {
                            'approved' => 'success',
                            'cancelled' => 'danger',
                            'received' => 'primary',
                            default => 'secondary'
                        } }}">{{ ucfirst($order->status) }}</span>
                    </td>
                    <td class="text-end">
                        @can('tenant.purchase-orders.show')
                            <a href="{{ url('/purchase-orders/' . $order->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No purchase orders found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $orders->links() }}</div>
    </div>
</div>
@endsection
