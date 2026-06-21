@extends('layouts.app')

@section('title', $record->consumption_no . ' — Consumption')

@section('content')
@php
    $statusColors   = \App\Models\Tenant\ManufacturingConsumptionRecord::STATUS_COLORS;
    $varianceColors = \App\Models\Tenant\ManufacturingConsumptionRecord::VARIANCE_COLORS;
    $srcLabels = ['wip' => 'WIP', 'material_requisition' => 'MRC', 'manual' => 'Manual'];
    $vStatus = $record->varianceStatus();
@endphp

<div class="page-header">
    <div class="page-title">
        <h4>{{ $record->consumption_no }}</h4>
        <h6>
            <span class="badge bg-{{ $statusColors[$record->status] ?? 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $record->status)) }}</span>
            <span class="badge bg-light text-dark border">{{ ucfirst(str_replace('_', ' ', $record->consumption_type)) }}</span>
            <span class="badge bg-{{ $varianceColors[$vStatus] ?? 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $vStatus)) }}</span>
        </h6>
    </div>
    <div class="page-btn d-flex gap-2">
        @can('tenant.manufacturing.consumption.edit')
            @if(!$record->isClosed())
                <a href="{{ url('/manufacturing/consumption/' . $record->id . '/edit') }}" class="btn btn-primary">
                    <i class="ti ti-pencil me-1"></i>Edit
                </a>
            @endif
        @endcan
        <a href="{{ url('/manufacturing/consumption') }}" class="btn btn-light">
            <i class="ti ti-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="alert alert-info">
    <i class="ti ti-info-circle me-1"></i>
    This Consumption record is <strong>tracking-only</strong> in this phase. It does <strong>not</strong> deduct stock, update WIP/MRC issued quantities, post material consumption, WIP variance, COGS or GL entries yet.
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Consumption Summary</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted">Consumption No</dt>
                    <dd class="col-sm-7"><code>{{ $record->consumption_no }}</code></dd>

                    <dt class="col-sm-5 text-muted">Source Type</dt>
                    <dd class="col-sm-7">{{ $srcLabels[$record->source_type] ?? ($record->source_type ? ucfirst($record->source_type) : '— Manual') }}</dd>

                    <dt class="col-sm-5 text-muted">WIP Job</dt>
                    <dd class="col-sm-7">@if($record->wipJob)<a href="{{ url('/manufacturing/wip/' . $record->wip_job_id) }}">{{ $record->wipJob->wip_no }}</a>@else <span class="text-muted">—</span> @endif</dd>

                    <dt class="col-sm-5 text-muted">Material Requisition</dt>
                    <dd class="col-sm-7">@if($record->materialRequisition)<a href="{{ url('/manufacturing/material-requisitions/' . $record->material_requisition_id) }}">{{ $record->materialRequisition->mrc_no }}</a>@else <span class="text-muted">—</span> @endif</dd>

                    <dt class="col-sm-5 text-muted">Production Order</dt>
                    <dd class="col-sm-7">@if($record->productionOrder)<a href="{{ url('/manufacturing/production-orders/' . $record->production_order_id) }}">{{ $record->productionOrder->order_no }}</a>@else <span class="text-muted">—</span> @endif</dd>

                    <dt class="col-sm-5 text-muted">Customer</dt>
                    <dd class="col-sm-7">{{ $record->manufacturingCustomer?->name ?? '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Branch</dt>
                    <dd class="col-sm-7">{{ $record->branch?->name ?? '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Consumption Date</dt>
                    <dd class="col-sm-7">{{ $record->consumption_date?->format('d M Y') }}</dd>

                    <dt class="col-sm-5 text-muted">Issue Reference</dt>
                    <dd class="col-sm-7">{{ $record->issue_reference ?: '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Created By</dt>
                    <dd class="col-sm-7">{{ $record->createdBy?->name ?? '—' }}</dd>
                </dl>
                @if($record->notes)
                    <hr>
                    <p class="text-muted small mb-0">{{ $record->notes }}</p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0">Quantities</h6></div>
            <div class="card-body">
                <div class="row text-center g-2">
                    <div class="col"><div class="border rounded py-2"><div class="text-muted small">Planned</div><div class="fw-bold">{{ number_format($record->total_planned_quantity, 2) }}</div></div></div>
                    <div class="col"><div class="border rounded py-2 bg-info bg-opacity-10"><div class="text-muted small">Consumed</div><div class="fw-bold">{{ number_format($record->total_consumed_quantity, 2) }}</div></div></div>
                    <div class="col"><div class="border rounded py-2"><div class="text-muted small">Wastage</div><div class="fw-bold text-warning">{{ number_format($record->total_wastage_quantity, 2) }}</div></div></div>
                    <div class="col"><div class="border rounded py-2 bg-{{ $varianceColors[$vStatus] ?? 'secondary' }} bg-opacity-10"><div class="text-muted small">Variance</div><div class="fw-bold">{{ number_format($record->total_variance_quantity, 2) }}</div></div></div>
                </div>
                <p class="text-muted small mb-0 mt-2">
                    Consumption: <strong>{{ number_format($record->consumptionPercent(), 2) }}%</strong>
                    · Wastage: <strong>{{ number_format($record->wastagePercent(), 2) }}%</strong>
                    · Variance status: <strong>{{ ucfirst(str_replace('_', ' ', $vStatus)) }}</strong>
                    @if($record->estimated_consumption_value !== null) · Est. value: <strong>{{ number_format($record->estimated_consumption_value, 2) }}</strong> @endif
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">Consumed Components <span class="badge bg-light text-dark border ms-1">{{ $record->lines->count() }}</span></h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Component</th>
                                <th>Unit</th>
                                <th class="text-end">Planned</th>
                                <th class="text-end">Consumed</th>
                                <th class="text-end">Wastage</th>
                                <th class="text-end">Variance</th>
                                <th class="text-end">Est. Total</th>
                                <th>Batch / Lot</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($record->lines as $line)
                            <tr>
                                <td>{{ $line->componentProduct?->name }}<small class="d-block text-muted">{{ $line->componentProduct?->sku }}</small></td>
                                <td>{{ $line->unit?->code ?? '—' }}</td>
                                <td class="text-end">{{ number_format($line->planned_quantity, 4) }}</td>
                                <td class="text-end">{{ number_format($line->consumed_quantity, 4) }}</td>
                                <td class="text-end">{{ number_format($line->wastage_quantity, 4) }}</td>
                                <td class="text-end {{ (float)$line->variance_quantity > 0 ? 'text-danger' : ((float)$line->variance_quantity < 0 ? 'text-warning' : '') }}">{{ number_format($line->variance_quantity, 4) }}</td>
                                <td class="text-end">{{ $line->estimated_total_value !== null ? number_format($line->estimated_total_value, 2) : '—' }}</td>
                                <td>{{ $line->batch_no ?: '—' }}@if($line->lot_no)<small class="d-block text-muted">Lot: {{ $line->lot_no }}</small>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-3">No consumption line items.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@can('tenant.manufacturing.consumption.destroy')
    @if(!$record->isClosed())
    <div class="mt-4 border-top pt-3">
        <form method="POST" action="{{ url('/manufacturing/consumption/' . $record->id) }}"
              onsubmit="return confirm('Cancel this consumption record?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-danger btn-sm">Cancel Consumption Record</button>
        </form>
    </div>
    @endif
@endcan
@endsection
