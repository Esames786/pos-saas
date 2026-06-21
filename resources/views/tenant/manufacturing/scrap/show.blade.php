@extends('layouts.app')

@section('title', $record->scrap_no . ' — Scrap / Hard Waste')

@section('content')
@php
    $statusColors  = \App\Models\Tenant\ManufacturingScrapRecord::STATUS_COLORS;
    $qualityColors = \App\Models\Tenant\ManufacturingScrapRecord::QUALITY_COLORS;
    $srcLabels = ['wip' => 'WIP', 'finished_goods' => 'Finished Goods', 'manual' => 'Manual'];
    $rec = $record->recoverablePercent();
@endphp

<div class="page-header">
    <div class="page-title">
        <h4>{{ $record->scrap_no }}</h4>
        <h6>
            <span class="badge bg-{{ $statusColors[$record->status] ?? 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $record->status)) }}</span>
            <span class="badge bg-light text-dark border">{{ ucfirst(str_replace('_', ' ', $record->scrap_type)) }}</span>
            @if($record->quality_status)
                <span class="badge bg-{{ $qualityColors[$record->quality_status] ?? 'secondary' }} {{ $record->quality_status === 'not_required' ? 'text-dark' : '' }}">{{ ucfirst(str_replace('_', ' ', $record->quality_status)) }}</span>
            @endif
        </h6>
    </div>
    <div class="page-btn d-flex gap-2">
        @can('tenant.manufacturing.scrap.edit')
            @if(!$record->isClosed())
                <a href="{{ url('/manufacturing/scrap/' . $record->id . '/edit') }}" class="btn btn-primary">
                    <i class="ti ti-pencil me-1"></i>Edit
                </a>
            @endif
        @endcan
        <a href="{{ url('/manufacturing/scrap') }}" class="btn btn-light">
            <i class="ti ti-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="alert alert-info">
    <i class="ti ti-info-circle me-1"></i>
    This Scrap / Hard Waste record is <strong>tracking-only</strong> in this phase. It does <strong>not</strong> deduct stock, post scrap expense, post WIP variance, create COGS or GL entries yet.
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Scrap Summary</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted">Scrap No</dt>
                    <dd class="col-sm-7"><code>{{ $record->scrap_no }}</code></dd>

                    <dt class="col-sm-5 text-muted">Source Type</dt>
                    <dd class="col-sm-7">{{ $srcLabels[$record->source_type] ?? ($record->source_type ? ucfirst($record->source_type) : '— Manual') }}</dd>

                    <dt class="col-sm-5 text-muted">WIP Job</dt>
                    <dd class="col-sm-7">
                        @if($record->wipJob)<a href="{{ url('/manufacturing/wip/' . $record->wip_job_id) }}">{{ $record->wipJob->wip_no }}</a>@else <span class="text-muted">—</span> @endif
                    </dd>

                    <dt class="col-sm-5 text-muted">Finished Goods</dt>
                    <dd class="col-sm-7">
                        @if($record->finishedGoodReceipt)<a href="{{ url('/manufacturing/finished-goods/' . $record->finished_good_receipt_id) }}">{{ $record->finishedGoodReceipt->fg_no }}</a>@else <span class="text-muted">—</span> @endif
                    </dd>

                    <dt class="col-sm-5 text-muted">Production Order</dt>
                    <dd class="col-sm-7">
                        @if($record->productionOrder)<a href="{{ url('/manufacturing/production-orders/' . $record->production_order_id) }}">{{ $record->productionOrder->order_no }}</a>@else <span class="text-muted">—</span> @endif
                    </dd>

                    <dt class="col-sm-5 text-muted">Customer</dt>
                    <dd class="col-sm-7">{{ $record->manufacturingCustomer?->name ?? '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Branch</dt>
                    <dd class="col-sm-7">{{ $record->branch?->name ?? '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Scrap Date</dt>
                    <dd class="col-sm-7">{{ $record->scrap_date?->format('d M Y') }}</dd>

                    <dt class="col-sm-5 text-muted">Reason Code</dt>
                    <dd class="col-sm-7">{{ $record->reason_code ? ucfirst(str_replace('_', ' ', $record->reason_code)) : '—' }}</dd>

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
                    <div class="col"><div class="border rounded py-2"><div class="text-muted small">Total</div><div class="fw-bold">{{ number_format($record->total_quantity, 2) }}</div></div></div>
                    <div class="col"><div class="border rounded py-2 bg-success bg-opacity-10"><div class="text-muted small">Recoverable</div><div class="fw-bold text-success">{{ number_format($record->recoverable_quantity, 2) }}</div></div></div>
                    <div class="col"><div class="border rounded py-2"><div class="text-muted small">Disposed</div><div class="fw-bold text-danger">{{ number_format($record->disposed_quantity, 2) }}</div></div></div>
                    <div class="col"><div class="border rounded py-2"><div class="text-muted small">Est. Loss</div><div class="fw-bold">{{ $record->estimated_loss_value !== null ? number_format($record->estimated_loss_value, 2) : '—' }}</div></div></div>
                </div>
                <p class="text-muted small mb-0 mt-2">Remaining to dispose: <strong>{{ number_format($record->remainingToDispose(), 4) }}</strong> · Recoverable: <strong>{{ number_format($rec, 2) }}%</strong></p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">Scrapped Items <span class="badge bg-light text-dark border ms-1">{{ $record->lines->count() }}</span></h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Product</th>
                                <th>Unit</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Recoverable</th>
                                <th class="text-end">Disposed</th>
                                <th class="text-end">Est. Loss</th>
                                <th>Batch / Lot</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($record->lines as $line)
                            <tr>
                                <td>
                                    {{ $line->product?->name }}
                                    <small class="d-block text-muted">{{ $line->product?->sku }}</small>
                                </td>
                                <td>{{ $line->unit?->code ?? '—' }}</td>
                                <td class="text-end">{{ number_format($line->quantity, 4) }}</td>
                                <td class="text-end">{{ number_format($line->recoverable_quantity, 4) }}</td>
                                <td class="text-end">{{ number_format($line->disposed_quantity, 4) }}</td>
                                <td class="text-end">{{ $line->estimated_loss_value !== null ? number_format($line->estimated_loss_value, 2) : '—' }}</td>
                                <td>
                                    {{ $line->batch_no ?: '—' }}
                                    @if($line->lot_no)<small class="d-block text-muted">Lot: {{ $line->lot_no }}</small>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-3">No scrapped item lines.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@can('tenant.manufacturing.scrap.destroy')
    @if(!$record->isClosed())
    <div class="mt-4 border-top pt-3">
        <form method="POST" action="{{ url('/manufacturing/scrap/' . $record->id) }}"
              onsubmit="return confirm('Cancel this scrap record?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-danger btn-sm">Cancel Scrap Record</button>
        </form>
    </div>
    @endif
@endcan
@endsection
