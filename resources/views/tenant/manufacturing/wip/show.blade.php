@extends('layouts.app')

@section('title', $job->wip_no . ' — WIP Job')

@section('content')
@php
    $statusColors   = \App\Models\Tenant\WipJob::STATUS_COLORS;
    $priorityColors = \App\Models\Tenant\WipJob::PRIORITY_COLORS;
    $pct = (float) $job->progress_percent;
@endphp

<div class="page-header">
    <div class="page-title">
        <h4>{{ $job->wip_no }}</h4>
        <h6>
            <span class="badge bg-{{ $statusColors[$job->status] ?? 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $job->status)) }}</span>
            @if($job->priority)
                <span class="badge bg-{{ $priorityColors[$job->priority] ?? 'secondary' }}">{{ ucfirst($job->priority) }} priority</span>
            @endif
        </h6>
    </div>
    <div class="page-btn d-flex gap-2">
        @can('tenant.manufacturing.finished-goods.create')
            @if(!$job->isClosed())
                <a href="{{ url('/manufacturing/finished-goods/create?wip_job_id=' . $job->id) }}" class="btn btn-added">
                    <i class="ti ti-package me-1"></i>Record Finished Goods
                </a>
            @endif
        @endcan
        @can('tenant.manufacturing.scrap.create')
            @if(!$job->isClosed())
                <a href="{{ url('/manufacturing/scrap/create?wip_job_id=' . $job->id) }}" class="btn btn-light">
                    <i class="ti ti-trash me-1"></i>Record Scrap / Hard Waste
                </a>
            @endif
        @endcan
        @can('tenant.manufacturing.rejections.create')
            @if(!$job->isClosed())
                <a href="{{ url('/manufacturing/rejections/create?wip_job_id=' . $job->id) }}" class="btn btn-light">
                    <i class="ti ti-ban me-1"></i>Record Rejection
                </a>
            @endif
        @endcan
        @can('tenant.manufacturing.wip.edit')
            @if(!$job->isClosed())
                <a href="{{ url('/manufacturing/wip/' . $job->id . '/edit') }}" class="btn btn-primary">
                    <i class="ti ti-pencil me-1"></i>Edit
                </a>
            @endif
        @endcan
        <a href="{{ url('/manufacturing/wip') }}" class="btn btn-light">
            <i class="ti ti-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="alert alert-info">
    <i class="ti ti-info-circle me-1"></i>
    This WIP Job is <strong>tracking/planning-only</strong> in this phase. It does <strong>not</strong> deduct stock, post WIP accounting, create finished goods, COGS or GL entries yet.
</div>

{{-- Progress --}}
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="fw-semibold">Production Progress</span>
            <span class="text-muted small">{{ number_format($job->completed_quantity, 2) }} / {{ number_format($job->planned_quantity, 2) }} ({{ number_format($pct, 2) }}%)</span>
        </div>
        <div class="progress" style="height:12px;">
            <div class="progress-bar bg-{{ $pct >= 100 ? 'success' : ($pct > 0 ? 'warning' : 'secondary') }}"
                 style="width:{{ min(100, $pct) }}%" role="progressbar"
                 aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Job Summary</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted">WIP No</dt>
                    <dd class="col-sm-7"><code>{{ $job->wip_no }}</code></dd>

                    <dt class="col-sm-5 text-muted">Production Order</dt>
                    <dd class="col-sm-7">
                        @if($job->productionOrder)
                            <a href="{{ url('/manufacturing/production-orders/' . $job->production_order_id) }}">{{ $job->productionOrder->order_no }}</a>
                        @else — @endif
                    </dd>

                    <dt class="col-sm-5 text-muted">Material Requisition</dt>
                    <dd class="col-sm-7">
                        @if($job->materialRequisition)
                            <a href="{{ url('/manufacturing/material-requisitions/' . $job->material_requisition_id) }}">{{ $job->materialRequisition->mrc_no }}</a>
                        @else <span class="text-muted">—</span> @endif
                    </dd>

                    <dt class="col-sm-5 text-muted">Finished Product</dt>
                    <dd class="col-sm-7">
                        <strong>{{ $job->finishedProduct?->name }}</strong>
                        <small class="d-block text-muted">{{ $job->finishedProduct?->sku }}</small>
                    </dd>

                    <dt class="col-sm-5 text-muted">Customer</dt>
                    <dd class="col-sm-7">{{ $job->manufacturingCustomer?->name ?? '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Branch</dt>
                    <dd class="col-sm-7">{{ $job->branch?->name ?? '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Start / Target</dt>
                    <dd class="col-sm-7">{{ $job->start_date?->format('d M Y') }} → {{ $job->target_date?->format('d M Y') ?? '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Planned</dt>
                    <dd class="col-sm-7">{{ number_format($job->planned_quantity, 4) }}</dd>

                    <dt class="col-sm-5 text-muted">Started</dt>
                    <dd class="col-sm-7">{{ number_format($job->started_quantity, 4) }}</dd>

                    <dt class="col-sm-5 text-muted">Completed</dt>
                    <dd class="col-sm-7">{{ number_format($job->completed_quantity, 4) }}</dd>

                    <dt class="col-sm-5 text-muted">Remaining</dt>
                    <dd class="col-sm-7">{{ number_format($job->remainingQuantity(), 4) }}</dd>

                    <dt class="col-sm-5 text-muted">Created By</dt>
                    <dd class="col-sm-7">{{ $job->createdBy?->name ?? '—' }}</dd>
                </dl>
                @if($job->notes)
                    <hr>
                    <p class="text-muted small mb-0">{{ $job->notes }}</p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0">Material Tracking <span class="badge bg-light text-dark border ms-1">{{ $job->lines->count() }}</span></h6>
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
                                <th class="text-end">Consumed</th>
                                <th class="text-end">Remaining</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($job->lines as $i => $line)
                            <tr>
                                <td class="text-muted">{{ $i + 1 }}</td>
                                <td>
                                    <strong>{{ $line->componentProduct?->name }}</strong>
                                    <small class="d-block text-muted">{{ $line->componentProduct?->sku }}</small>
                                </td>
                                <td>{{ $line->unit?->code ?? '—' }}</td>
                                <td class="text-end">{{ number_format($line->required_quantity, 4) }}</td>
                                <td class="text-end">{{ number_format($line->issued_quantity, 4) }}</td>
                                <td class="text-end">{{ number_format($line->consumed_quantity, 4) }}</td>
                                <td class="text-end {{ $line->remaining_quantity > 0 ? 'fw-semibold' : 'text-success' }}">{{ number_format($line->remaining_quantity, 4) }}</td>
                            </tr>
                            @if($line->notes)
                            <tr><td></td><td colspan="6" class="text-muted small py-0 pb-2">{{ $line->notes }}</td></tr>
                            @endif
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-3">No material lines tracked.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@can('tenant.manufacturing.wip.destroy')
    @if(!$job->isClosed())
    <div class="mt-4 border-top pt-3">
        <form method="POST" action="{{ url('/manufacturing/wip/' . $job->id) }}"
              onsubmit="return confirm('Cancel this WIP job?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-danger btn-sm">Cancel WIP Job</button>
        </form>
    </div>
    @endif
@endcan
@endsection
