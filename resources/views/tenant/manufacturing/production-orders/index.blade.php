@extends('layouts.app')

@section('title', 'Production Orders')

@section('content')
@php
    $statusColors = \App\Models\Tenant\ProductionOrder::STATUS_COLORS;
    $priorityColors = \App\Models\Tenant\ProductionOrder::PRIORITY_COLORS;
    $statusLabels = [
        'draft' => 'Draft', 'planned' => 'Planned', 'released' => 'Released',
        'in_progress' => 'In Progress', 'on_hold' => 'On Hold',
        'completed' => 'Completed', 'cancelled' => 'Cancelled',
    ];
@endphp

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Production Orders</h1>
        <p class="fw-medium text-muted">Plan and track production runs. <span class="badge bg-warning text-dark">Planning only — no stock or GL posting yet.</span></p>
    </div>
    @can('tenant.manufacturing.production-orders.create')
        <a href="{{ url('/manufacturing/production-orders/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1"></i>New Production Order
        </a>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

{{-- Filters --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ url('/manufacturing/production-orders') }}" class="row g-2 align-items-end">
            <div class="col-sm-6 col-md-3">
                <input type="text" name="q" class="form-control"
                       placeholder="Order no, product, customer…"
                       value="{{ $filters['q'] ?? '' }}">
            </div>
            <div class="col-sm-4 col-md-2">
                <select name="status" class="form-select">
                    <option value="">All status</option>
                    @foreach($statuses as $s)
                        <option value="{{ $s }}" {{ ($filters['status'] ?? '') === $s ? 'selected' : '' }}>
                            {{ $statusLabels[$s] ?? ucfirst($s) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-4 col-md-2">
                <select name="branch_id" class="form-select">
                    <option value="">All branches</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ ($filters['branch_id'] ?? '') == $b->id ? 'selected' : '' }}>
                            {{ $b->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-4 col-md-2">
                <input type="date" name="date_from" class="form-control"
                       title="Date from" value="{{ $filters['date_from'] ?? '' }}">
            </div>
            <div class="col-sm-4 col-md-2">
                <input type="date" name="date_to" class="form-control"
                       title="Date to" value="{{ $filters['date_to'] ?? '' }}">
            </div>
            <div class="col-sm-4 col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-primary flex-grow-1">Go</button>
                @if(!empty(array_filter($filters)))
                    <a href="{{ url('/manufacturing/production-orders') }}" class="btn btn-outline-secondary" title="Clear"><i class="ti ti-x"></i></a>
                @endif
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Production orders list</caption>
            <thead class="thead-light">
                <tr>
                    <th>Order No</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Branch</th>
                    <th>Finished Product</th>
                    <th class="text-end">Planned Qty</th>
                    <th class="text-end">Produced Qty</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($orders as $order)
                <tr>
                    <td><code>{{ $order->order_no }}</code></td>
                    <td>{{ $order->order_date->format('d M Y') }}</td>
                    <td>{{ $order->manufacturingCustomer?->name ?? '—' }}</td>
                    <td>{{ $order->branch?->name ?? '—' }}</td>
                    <td>
                        <strong>{{ $order->product?->name }}</strong>
                        <small class="d-block text-muted">{{ $order->product?->sku }}</small>
                    </td>
                    <td class="text-end">{{ number_format($order->planned_quantity, 2) }}</td>
                    <td class="text-end">
                        @if($order->produced_quantity > 0)
                            <span class="{{ $order->produced_quantity >= $order->planned_quantity ? 'text-success fw-semibold' : 'text-warning' }}">
                                {{ number_format($order->produced_quantity, 2) }}
                            </span>
                        @else
                            <span class="text-muted">0.00</span>
                        @endif
                    </td>
                    <td>
                        @if($order->due_date)
                            @php $overdue = !$order->isClosed() && $order->due_date->isPast(); @endphp
                            <span class="{{ $overdue ? 'text-danger fw-semibold' : '' }}">
                                {{ $order->due_date->format('d M Y') }}
                            </span>
                            @if($overdue) <span class="badge bg-danger ms-1">Overdue</span> @endif
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge bg-{{ $statusColors[$order->status] ?? 'secondary' }}">
                            {{ $statusLabels[$order->status] ?? ucfirst($order->status) }}
                        </span>
                    </td>
                    <td>
                        @if($order->priority)
                            <span class="badge bg-{{ $priorityColors[$order->priority] ?? 'secondary' }}">
                                {{ ucfirst($order->priority) }}
                            </span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-end">
                        @can('tenant.manufacturing.production-orders.show')
                            <a href="{{ url('/manufacturing/production-orders/' . $order->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                        @can('tenant.manufacturing.production-orders.edit')
                            @if(!$order->isClosed())
                                <a href="{{ url('/manufacturing/production-orders/' . $order->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="text-center text-muted py-5">
                        No production orders found.
                        @can('tenant.manufacturing.production-orders.create')
                            <a href="{{ url('/manufacturing/production-orders/create') }}">Create the first one.</a>
                        @endcan
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($orders->hasPages())
        <div class="card-footer">{{ $orders->links() }}</div>
    @endif
</div>
@endsection
