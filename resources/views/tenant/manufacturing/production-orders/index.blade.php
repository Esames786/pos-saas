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

        <div class="page-header">
            <div class="page-title">
                <h4>Production Orders</h4>
                <h6>Plan and track production runs — planning only, no stock or GL posting yet</h6>
            </div>
            @can('tenant.manufacturing.production-orders.create')
            <div class="page-btn">
                <a href="{{ url('/manufacturing/production-orders/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>New Production Order
                </a>
            </div>
            @endcan
        </div>

        @if(session('status'))
            <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger alert-dismissible fade show">{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif

        <div class="card">
            <div class="card-body">
                <form method="GET" action="{{ url('/manufacturing/production-orders') }}" class="row g-2 align-items-end">
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label mb-1">Search</label>
                        <input type="text" name="q" class="form-control" placeholder="Order no, product, customer…"
                               value="{{ $filters['q'] ?? '' }}">
                    </div>
                    <div class="col-sm-4 col-md-2">
                        <label class="form-label mb-1">Status</label>
                        <select name="status" class="select form-select">
                            <option value="">All status</option>
                            @foreach($statuses as $s)
                                <option value="{{ $s }}" {{ ($filters['status'] ?? '') === $s ? 'selected' : '' }}>
                                    {{ $statusLabels[$s] ?? ucfirst($s) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-4 col-md-2">
                        <label class="form-label mb-1">Branch</label>
                        <select name="branch_id" class="select form-select">
                            <option value="">All branches</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ ($filters['branch_id'] ?? '') == $b->id ? 'selected' : '' }}>
                                    {{ $b->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-4 col-md-2">
                        <label class="form-label mb-1">From</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="col-sm-4 col-md-2">
                        <label class="form-label mb-1">To</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    <div class="col-sm-4 col-md-1 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100">Go</button>
                        @if(!empty(array_filter($filters)))
                            <a href="{{ url('/manufacturing/production-orders') }}" class="btn btn-light" title="Clear"><i class="ti ti-x"></i></a>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">Production orders list</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">Order No</th>
                                <th scope="col">Date</th>
                                <th scope="col">Customer</th>
                                <th scope="col">Branch</th>
                                <th scope="col">Finished Product</th>
                                <th scope="col" class="text-end">Planned Qty</th>
                                <th scope="col" class="text-end">Produced Qty</th>
                                <th scope="col">Due Date</th>
                                <th scope="col">Status</th>
                                <th scope="col">Priority</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($orders as $order)
                            <tr>
                                <td><a href="{{ url('/manufacturing/production-orders/' . $order->id) }}" class="fw-semibold">{{ $order->order_no }}</a></td>
                                <td>{{ $order->order_date->format('d M Y') }}</td>
                                <td>{{ $order->manufacturingCustomer?->name ?? '—' }}</td>
                                <td>{{ $order->branch?->name ?? '—' }}</td>
                                <td>
                                    {{ $order->product?->name }}
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
                                        <a href="{{ url('/manufacturing/production-orders/' . $order->id) }}" class="btn btn-sm btn-outline-secondary me-1" title="View"><i class="ti ti-eye"></i></a>
                                    @endcan
                                    @can('tenant.manufacturing.production-orders.edit')
                                        @if(!$order->isClosed())
                                            <a href="{{ url('/manufacturing/production-orders/' . $order->id . '/edit') }}" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="ti ti-pencil"></i></a>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">
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
                    <div class="mt-3">{{ $orders->links() }}</div>
                @endif
            </div>
        </div>
@endsection
