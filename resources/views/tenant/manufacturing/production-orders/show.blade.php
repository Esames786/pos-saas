@extends('layouts.app')

@section('title', $order->order_no . ' — Production Order')

@section('content')
@php
    $statusColors   = \App\Models\Tenant\ProductionOrder::STATUS_COLORS;
    $priorityColors = \App\Models\Tenant\ProductionOrder::PRIORITY_COLORS;
    $statusLabels   = [
        'draft' => 'Draft', 'planned' => 'Planned', 'released' => 'Released',
        'in_progress' => 'In Progress', 'on_hold' => 'On Hold',
        'completed' => 'Completed', 'cancelled' => 'Cancelled',
    ];
    $progress = $order->planned_quantity > 0
        ? min(100, round(($order->produced_quantity / $order->planned_quantity) * 100))
        : 0;
@endphp

<div class="page-header">
    <div class="page-title">
        <h4>{{ $order->order_no }}</h4>
        <h6>
            <span class="badge bg-{{ $statusColors[$order->status] ?? 'secondary' }} me-1">
                {{ $statusLabels[$order->status] ?? ucfirst($order->status) }}
            </span>
            @if($order->priority)
                <span class="badge bg-{{ $priorityColors[$order->priority] ?? 'secondary' }}">
                    {{ ucfirst($order->priority) }} priority
                </span>
            @endif
        </h6>
    </div>
    <div class="page-btn d-flex gap-2">
        @can('tenant.manufacturing.material-requisitions.create')
            @if(!$order->isClosed())
                <a href="{{ url('/manufacturing/material-requisitions/create?production_order_id=' . $order->id) }}" class="btn btn-added">
                    <i class="ti ti-clipboard-list me-1"></i>Generate Material Requisition
                </a>
            @endif
        @endcan
        @can('tenant.manufacturing.wip.create')
            @if(!$order->isClosed())
                <a href="{{ url('/manufacturing/wip/create?production_order_id=' . $order->id) }}" class="btn btn-added">
                    <i class="ti ti-progress me-1"></i>Create WIP Job
                </a>
            @endif
        @endcan
        @can('tenant.manufacturing.production-orders.edit')
            @if(!$order->isClosed())
                <a href="{{ url('/manufacturing/production-orders/' . $order->id . '/edit') }}" class="btn btn-primary">
                    <i class="ti ti-pencil me-1"></i>Edit
                </a>
            @endif
        @endcan
        <a href="{{ url('/manufacturing/production-orders') }}" class="btn btn-light">
            <i class="ti ti-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="alert alert-info">
    <i class="ti ti-info-circle me-1"></i>
    <strong>Planning-only production order.</strong>
    This order does not post inventory, WIP, finished goods, COGS or GL entries yet. BOM, MRC, WIP and Finished Goods posting are planned as upcoming modules.
</div>

<div class="row g-3">
    {{-- Progress card --}}
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-semibold">Production Progress</span>
                    <span class="text-muted small">{{ number_format($order->produced_quantity, 2) }} / {{ number_format($order->planned_quantity, 2) }} ({{ $progress }}%)</span>
                </div>
                <div class="progress" style="height:10px;">
                    <div class="progress-bar bg-{{ $progress >= 100 ? 'success' : ($progress > 0 ? 'warning' : 'secondary') }}"
                         style="width:{{ $progress }}%" role="progressbar"
                         aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Order details --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Order Details</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted">Order No</dt>
                    <dd class="col-sm-7"><code>{{ $order->order_no }}</code></dd>

                    <dt class="col-sm-5 text-muted">Finished Product</dt>
                    <dd class="col-sm-7">
                        <strong>{{ $order->product?->name }}</strong>
                        <small class="d-block text-muted">{{ $order->product?->sku }}</small>
                    </dd>

                    <dt class="col-sm-5 text-muted">Planned Quantity</dt>
                    <dd class="col-sm-7">{{ number_format($order->planned_quantity, 2) }}</dd>

                    <dt class="col-sm-5 text-muted">Produced Quantity</dt>
                    <dd class="col-sm-7">{{ number_format($order->produced_quantity, 2) }}</dd>

                    <dt class="col-sm-5 text-muted">Order Date</dt>
                    <dd class="col-sm-7">{{ $order->order_date->format('d M Y') }}</dd>

                    <dt class="col-sm-5 text-muted">Due Date</dt>
                    <dd class="col-sm-7">
                        @if($order->due_date)
                            @php $overdue = !$order->isClosed() && $order->due_date->isPast(); @endphp
                            <span class="{{ $overdue ? 'text-danger fw-semibold' : '' }}">
                                {{ $order->due_date->format('d M Y') }}
                            </span>
                            @if($overdue) <span class="badge bg-danger ms-1">Overdue</span> @endif
                        @else
                            —
                        @endif
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    {{-- Assignment --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Assignment</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted">Customer</dt>
                    <dd class="col-sm-7">
                        @if($order->manufacturingCustomer)
                            {{ $order->manufacturingCustomer->name }}
                            <small class="d-block text-muted">
                                <code>{{ $order->manufacturingCustomer->code }}</code>
                                — Manufacturing Customer
                            </small>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </dd>

                    <dt class="col-sm-5 text-muted">Branch</dt>
                    <dd class="col-sm-7">{{ $order->branch?->name ?? '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Status</dt>
                    <dd class="col-sm-7">
                        <span class="badge bg-{{ $statusColors[$order->status] ?? 'secondary' }}">
                            {{ $statusLabels[$order->status] ?? ucfirst($order->status) }}
                        </span>
                    </dd>

                    <dt class="col-sm-5 text-muted">Priority</dt>
                    <dd class="col-sm-7">
                        @if($order->priority)
                            <span class="badge bg-{{ $priorityColors[$order->priority] ?? 'secondary' }}">
                                {{ ucfirst($order->priority) }}
                            </span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </dd>

                    <dt class="col-sm-5 text-muted">Created By</dt>
                    <dd class="col-sm-7">{{ $order->createdBy?->name ?? '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Created At</dt>
                    <dd class="col-sm-7">{{ $order->created_at->format('d M Y H:i') }}</dd>
                </dl>
            </div>
        </div>
    </div>

    @if($order->notes)
    <div class="col-12">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Notes</h6></div>
            <div class="card-body"><p class="mb-0 text-muted">{{ $order->notes }}</p></div>
        </div>
    </div>
    @endif
</div>

@can('tenant.manufacturing.production-orders.destroy')
    @if(!$order->isClosed())
    <div class="mt-4 border-top pt-3">
        <form method="POST" action="{{ url('/manufacturing/production-orders/' . $order->id) }}"
              onsubmit="return confirm('Cancel this production order?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-danger btn-sm">Cancel Order</button>
        </form>
    </div>
    @endif
@endcan
@endsection
