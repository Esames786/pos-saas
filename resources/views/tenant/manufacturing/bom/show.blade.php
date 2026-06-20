@extends('layouts.app')

@section('title', $bom->bom_no . ' — Bill of Materials')

@section('content')
@php
    $statusColors = \App\Models\Tenant\ManufacturingBom::STATUS_COLORS;
@endphp

<div class="page-header">
    <div class="page-title">
        <h4>{{ $bom->bom_no }}</h4>
        <h6>
            <span class="badge bg-{{ $statusColors[$bom->status] ?? 'secondary' }}">{{ ucfirst($bom->status) }}</span>
            &nbsp;v{{ $bom->version }}
            @if($bom->name) &nbsp;·&nbsp; {{ $bom->name }} @endif
        </h6>
    </div>
    <div class="page-btn d-flex gap-2">
        @can('tenant.manufacturing.bom.edit')
            @if($bom->status !== 'archived')
                <a href="{{ url('/manufacturing/bom/' . $bom->id . '/edit') }}" class="btn btn-primary">
                    <i class="ti ti-pencil me-1"></i>Edit
                </a>
            @endif
        @endcan
        <a href="{{ url('/manufacturing/bom') }}" class="btn btn-light">
            <i class="ti ti-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="alert alert-info">
    <i class="ti ti-info-circle me-1"></i>
    <strong>Configuration-only BOM.</strong>
    This BOM does not consume inventory, post WIP, create finished goods or create GL entries. Material Requisition and production posting are planned as upcoming modules.
</div>

<div class="row g-3">

    {{-- Header summary --}}
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">BOM Summary</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted">BOM No</dt>
                    <dd class="col-sm-7"><code>{{ $bom->bom_no }}</code></dd>

                    <dt class="col-sm-5 text-muted">Finished Product</dt>
                    <dd class="col-sm-7">
                        <strong>{{ $bom->finishedProduct?->name }}</strong>
                        <small class="d-block text-muted">{{ $bom->finishedProduct?->sku }}</small>
                    </dd>

                    <dt class="col-sm-5 text-muted">Output Qty</dt>
                    <dd class="col-sm-7">{{ number_format($bom->output_quantity, 4) }} units per batch</dd>

                    <dt class="col-sm-5 text-muted">Version</dt>
                    <dd class="col-sm-7">{{ $bom->version }}</dd>

                    <dt class="col-sm-5 text-muted">Status</dt>
                    <dd class="col-sm-7">
                        <span class="badge bg-{{ $statusColors[$bom->status] ?? 'secondary' }}">{{ ucfirst($bom->status) }}</span>
                    </dd>

                    <dt class="col-sm-5 text-muted">Effective From</dt>
                    <dd class="col-sm-7">{{ $bom->effective_from?->format('d M Y') ?? '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Created By</dt>
                    <dd class="col-sm-7">{{ $bom->createdBy?->name ?? '—' }}</dd>
                </dl>
                @if($bom->notes)
                    <hr>
                    <p class="text-muted small mb-0">{{ $bom->notes }}</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Component lines --}}
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0">Component Lines
                    <span class="badge bg-light text-dark border ms-1">{{ $bom->lines->count() }}</span>
                </h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>Component</th>
                                <th>Unit</th>
                                <th class="text-end">Qty / batch</th>
                                <th class="text-end">Wastage %</th>
                                <th class="text-end">Est. qty for 1 output</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($bom->lines as $i => $line)
                            <tr>
                                <td class="text-muted">{{ $i + 1 }}</td>
                                <td>
                                    <strong>{{ $line->componentProduct?->name }}</strong>
                                    <small class="d-block text-muted">{{ $line->componentProduct?->sku }}</small>
                                </td>
                                <td>{{ $line->unit?->code ?? '—' }}</td>
                                <td class="text-end">{{ number_format($line->quantity, 4) }}</td>
                                <td class="text-end">
                                    @if($line->wastage_percent > 0)
                                        <span class="text-warning">{{ number_format($line->wastage_percent, 2) }}%</span>
                                    @else
                                        <span class="text-muted">0%</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    {{ number_format($line->estimatedComponentQuantity(1), 4) }}
                                    <small class="d-block text-muted">per unit output</small>
                                </td>
                            </tr>
                            @if($line->notes)
                            <tr>
                                <td></td>
                                <td colspan="5" class="text-muted small py-0 pb-2">{{ $line->notes }}</td>
                            </tr>
                            @endif
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-3">No component lines.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

@can('tenant.manufacturing.bom.destroy')
    @if($bom->status !== 'archived')
    <div class="mt-4 border-top pt-3">
        <form method="POST" action="{{ url('/manufacturing/bom/' . $bom->id) }}"
              onsubmit="return confirm('Archive this BOM?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-secondary btn-sm">Archive BOM</button>
        </form>
    </div>
    @endif
@endcan
@endsection
