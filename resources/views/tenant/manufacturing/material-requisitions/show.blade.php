@extends('layouts.app')

@section('title', $requisition->mrc_no . ' — Material Requisition')

@section('content')
@php
    $statusColors   = \App\Models\Tenant\MaterialRequisition::STATUS_COLORS;
    $priorityColors = \App\Models\Tenant\MaterialRequisition::PRIORITY_COLORS;
@endphp

<div class="page-header">
    <div class="page-title">
        <h4>{{ $requisition->mrc_no }}</h4>
        <h6>
            <span class="badge bg-{{ $statusColors[$requisition->status] ?? 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $requisition->status)) }}</span>
            @if($requisition->priority)
                <span class="badge bg-{{ $priorityColors[$requisition->priority] ?? 'secondary' }}">{{ ucfirst($requisition->priority) }} priority</span>
            @endif
        </h6>
    </div>
    <div class="page-btn d-flex gap-2">
        @can('tenant.manufacturing.wip.create')
            @if(!$requisition->isClosed())
                <a href="{{ url('/manufacturing/wip/create?material_requisition_id=' . $requisition->id) }}" class="btn btn-added">
                    <i class="ti ti-progress me-1"></i>Create WIP Job
                </a>
            @endif
        @endcan
        @can('tenant.manufacturing.consumption.create')
            @if(!$requisition->isClosed())
                <a href="{{ url('/manufacturing/consumption/create?material_requisition_id=' . $requisition->id) }}" class="btn btn-light">
                    <i class="ti ti-flask me-1"></i>Record Consumption
                </a>
            @endif
        @endcan
        @can('tenant.manufacturing.material-requisitions.edit')
            @if(!$requisition->isClosed())
                <a href="{{ url('/manufacturing/material-requisitions/' . $requisition->id . '/edit') }}" class="btn btn-primary">
                    <i class="ti ti-pencil me-1"></i>Edit
                </a>
            @endif
        @endcan
        <a href="{{ url('/manufacturing/material-requisitions') }}" class="btn btn-light">
            <i class="ti ti-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="alert alert-info">
    <i class="ti ti-info-circle me-1"></i>
    This Material Requisition is <strong>planning/request-only</strong> in this phase. It does <strong>not</strong> deduct stock, post WIP, create finished goods, COGS or GL entries yet.
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Requisition Summary</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted">MRC No</dt>
                    <dd class="col-sm-7"><code>{{ $requisition->mrc_no }}</code></dd>

                    <dt class="col-sm-5 text-muted">Production Order</dt>
                    <dd class="col-sm-7">
                        @if($requisition->productionOrder)
                            <a href="{{ url('/manufacturing/production-orders/' . $requisition->production_order_id) }}">{{ $requisition->productionOrder->order_no }}</a>
                            <small class="d-block text-muted">{{ $requisition->productionOrder->product?->name }}</small>
                        @else
                            <span class="text-muted">— (manual)</span>
                        @endif
                    </dd>

                    <dt class="col-sm-5 text-muted">Customer</dt>
                    <dd class="col-sm-7">{{ $requisition->manufacturingCustomer?->name ?? '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Branch</dt>
                    <dd class="col-sm-7">{{ $requisition->branch?->name ?? '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Request Date</dt>
                    <dd class="col-sm-7">{{ $requisition->request_date?->format('d M Y') }}</dd>

                    <dt class="col-sm-5 text-muted">Required Date</dt>
                    <dd class="col-sm-7">{{ $requisition->required_date?->format('d M Y') ?? '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Status</dt>
                    <dd class="col-sm-7"><span class="badge bg-{{ $statusColors[$requisition->status] ?? 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $requisition->status)) }}</span></dd>

                    <dt class="col-sm-5 text-muted">Created By</dt>
                    <dd class="col-sm-7">{{ $requisition->createdBy?->name ?? '—' }}</dd>
                </dl>
                @if($requisition->notes)
                    <hr>
                    <p class="text-muted small mb-0">{{ $requisition->notes }}</p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0">Required Components <span class="badge bg-light text-dark border ms-1">{{ $requisition->lines->count() }}</span></h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>Component</th>
                                <th>Unit</th>
                                <th class="text-end">Required</th>
                                <th class="text-end">Issued</th>
                                <th class="text-end">Remaining</th>
                                <th class="text-end">Wastage %</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($requisition->lines as $i => $line)
                            <tr>
                                <td class="text-muted">{{ $i + 1 }}</td>
                                <td>
                                    <strong>{{ $line->componentProduct?->name }}</strong>
                                    <small class="d-block text-muted">{{ $line->componentProduct?->sku }}</small>
                                </td>
                                <td>{{ $line->unit?->code ?? '—' }}</td>
                                <td class="text-end">{{ number_format($line->required_quantity, 4) }}</td>
                                <td class="text-end">{{ number_format($line->issued_quantity, 4) }}</td>
                                <td class="text-end {{ $line->remainingQuantity() > 0 ? 'fw-semibold' : 'text-success' }}">{{ number_format($line->remainingQuantity(), 4) }}</td>
                                <td class="text-end">{{ $line->wastage_percent > 0 ? number_format($line->wastage_percent, 2) . '%' : '—' }}</td>
                            </tr>
                            @if($line->notes)
                            <tr><td></td><td colspan="6" class="text-muted small py-0 pb-2">{{ $line->notes }}</td></tr>
                            @endif
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-3">No component lines.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@can('tenant.manufacturing.material-requisitions.destroy')
    @if(!$requisition->isClosed())
    <div class="mt-4 border-top pt-3">
        <form method="POST" action="{{ url('/manufacturing/material-requisitions/' . $requisition->id) }}"
              onsubmit="return confirm('Cancel this requisition?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-danger btn-sm">Cancel Requisition</button>
        </form>
    </div>
    @endif
@endcan
@endsection
