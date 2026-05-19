@extends('layouts.app')

@section('title', 'Sales Orders')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Sales Orders</h1>
        <p class="fw-medium">Manual and POS sales history.</p>
    </div>
    @can('tenant.sales-orders.create')
        <a href="{{ url('/sales-orders/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>New Sale
        </a>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/sales-orders') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="branch-filter" class="form-label">Branch</label>
                <select id="branch-filter" name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="status-filter" class="form-label">Status</label>
                <select id="status-filter" name="status" class="form-select">
                    <option value="">All</option>
                    <option value="draft"              @selected(request('status') === 'draft')>Draft</option>
                    <option value="paid"               @selected(request('status') === 'paid')>Paid</option>
                    <option value="cancelled"          @selected(request('status') === 'cancelled')>Cancelled</option>
                    <option value="partially_returned" @selected(request('status') === 'partially_returned')>Partially Returned</option>
                    <option value="returned"           @selected(request('status') === 'returned')>Returned</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="type-filter" class="form-label">Type</label>
                <select id="type-filter" name="order_type" class="form-select">
                    <option value="">All Types</option>
                    <option value="quick_sale" @selected(request('order_type') === 'quick_sale')>Quick Sale</option>
                    <option value="takeaway"   @selected(request('order_type') === 'takeaway')>Takeaway</option>
                    <option value="dine_in"    @selected(request('order_type') === 'dine_in')>Dine In</option>
                    <option value="delivery"   @selected(request('order_type') === 'delivery')>Delivery</option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-dark" type="submit">Filter</button>
                <a href="{{ url('/sales-orders') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Sales order list</caption>
            <thead>
            <tr>
                <th scope="col">Sale No</th>
                <th scope="col">Branch</th>
                <th scope="col">Customer</th>
                <th scope="col">Date</th>
                <th scope="col">Type</th>
                <th scope="col">Grand Total</th>
                <th scope="col">Status</th>
                <th scope="col" class="text-end">Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse($orders as $order)
                <tr>
                    <td><code>{{ $order->sale_no }}</code></td>
                    <td>{{ $order->branch?->name }}</td>
                    <td>{{ $order->customer?->name ?? $order->customer_name ?? '—' }}</td>
                    <td>{{ $order->sale_date?->format('Y-m-d H:i') }}</td>
                    <td>{{ str_replace('_', ' ', ucfirst($order->order_type)) }}</td>
                    <td><strong>{{ number_format($order->grand_total, 2) }}</strong></td>
                    <td>
                        <span class="badge bg-{{ match($order->status) {
                            'paid' => 'success',
                            'draft', 'held' => 'secondary',
                            'cancelled' => 'danger',
                            'partially_returned' => 'warning',
                            'returned' => 'info',
                            default => 'secondary'
                        } }} {{ $order->status === 'partially_returned' ? 'text-dark' : '' }}">
                            {{ str_replace('_', ' ', ucfirst($order->status)) }}
                        </span>
                    </td>
                    <td class="text-end">
                        @can('tenant.sales-orders.show')
                            <a href="{{ url('/sales-orders/' . $order->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">No sales orders found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $orders->links() }}</div>
    </div>
</div>
@endsection
