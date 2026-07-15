@extends('layouts.app')

@section('title', $receipt->fg_no . ' — Finished Goods')

@section('content')
@php
    $statusColors  = \App\Models\Tenant\FinishedGoodReceipt::STATUS_COLORS;
    $qualityColors = \App\Models\Tenant\FinishedGoodReceipt::QUALITY_COLORS;
    $priorityColors = \App\Models\Tenant\FinishedGoodReceipt::PRIORITY_COLORS;
    $acc = $receipt->acceptancePercent();
@endphp

<div class="page-header">
    <div class="page-title">
        <h4>{{ $receipt->fg_no }}</h4>
        <h6>
            <span class="badge bg-{{ $statusColors[$receipt->status] ?? 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $receipt->status)) }}</span>
            @if($receipt->quality_status)
                <span class="badge bg-{{ $qualityColors[$receipt->quality_status] ?? 'secondary' }} {{ $receipt->quality_status === 'not_required' ? 'text-dark' : '' }}">QC: {{ ucfirst(str_replace('_', ' ', $receipt->quality_status)) }}</span>
            @endif
            @if($receipt->priority)
                <span class="badge bg-{{ $priorityColors[$receipt->priority] ?? 'secondary' }}">{{ ucfirst($receipt->priority) }} priority</span>
            @endif
        </h6>
    </div>
    <div class="page-btn d-flex gap-2">
        @can('tenant.manufacturing.scrap.create')
            @if(!$receipt->isClosed())
                <a href="{{ url('/manufacturing/scrap/create?finished_good_receipt_id=' . $receipt->id) }}" class="btn btn-light">
                    <i class="ti ti-trash me-1"></i>Record Scrap / Hard Waste
                </a>
            @endif
        @endcan
        @can('tenant.manufacturing.rejections.create')
            @if(!$receipt->isClosed())
                <a href="{{ url('/manufacturing/rejections/create?finished_good_receipt_id=' . $receipt->id) }}" class="btn btn-light">
                    <i class="ti ti-ban me-1"></i>Record Rejection
                </a>
            @endif
        @endcan
        @can('tenant.manufacturing.finished-goods.edit')
            @if(!$receipt->isClosed() && $receipt->isUnposted())
                <a href="{{ url('/manufacturing/finished-goods/' . $receipt->id . '/edit') }}" class="btn btn-primary">
                    <i class="ti ti-pencil me-1"></i>Edit
                </a>
            @endif
        @endcan
        <a href="{{ url('/manufacturing/finished-goods') }}" class="btn btn-light">
            <i class="ti ti-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

@include('tenant.manufacturing.partials.posting-status', ['document' => $receipt])

@if(session('status'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

{{-- MFG-FIN-E: FG Receipt posting panel (Dr FG Inventory / Cr WIP) --}}
@php
    $postingReady  = $postingReady  ?? false;
    $postingReason = $postingReason ?? null;
    $wipAccumCost  = $wipAccumCost  ?? 0;
    $fgUnitCost    = $fgUnitCost    ?? 0;
@endphp
<div class="card mb-3 border-0 shadow-sm">
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <strong class="me-1">FG Inventory Posting</strong>
            <span class="badge bg-{{ $receipt->postingStatusBadgeClass() }}">{{ $receipt->postingStatusLabel() }}</span>
            <div class="small text-muted mt-1">
                Post to Inventory increases Finished Goods stock and posts Dr FG Inventory / Cr WIP. Reverse Posting reverses the stock movement and journal.
            </div>
            @if($receipt->isPosted())
                <div class="small text-muted mt-1">
                    Dr {{ $fgAccountName ?? 'Finished Goods Inventory' }} /
                    Cr {{ $wipAccountName ?? 'WIP Inventory' }} ·
                    Unit cost <strong>{{ number_format($fgUnitCost, 4) }}</strong> ·
                    Total <strong>{{ number_format($receipt->total_cost, 2) }}</strong>
                    @if($receipt->journal_entry_id) · Journal #{{ $receipt->journal_entry_id }}@endif
                </div>
            @elseif($receipt->isUnposted() && !$postingReady && $postingReason)
                <div class="small text-warning mt-1"><i class="ti ti-alert-triangle me-1"></i>{{ $postingReason }}</div>
            @elseif($receipt->isUnposted() && $postingReady)
                <div class="small text-muted mt-1">
                    WIP accumulated cost: <strong>{{ number_format($wipAccumCost, 2) }}</strong> ·
                    Derived unit cost: <strong>{{ number_format($fgUnitCost, 4) }}</strong> ·
                    Accepted qty: <strong>{{ number_format($receipt->accepted_quantity, 2) }}</strong>
                </div>
            @endif
        </div>
        <div class="d-flex gap-2">
            @if($receipt->isUnposted() && $receipt->status !== 'cancelled')
                @can('tenant.manufacturing.finished-goods.post')
                    @if($postingReady)
                        <form method="POST" action="{{ url('/manufacturing/finished-goods/' . $receipt->id . '/post') }}"
                              onsubmit="return confirm('Post FG receipt? Accepted quantity will be added to inventory and a Dr FG / Cr WIP journal will be posted.');">
                            @csrf
                            <button class="btn btn-success"><i class="ti ti-arrow-bar-to-down me-1"></i>Post to Inventory</button>
                        </form>
                    @else
                        <button class="btn btn-success" disabled title="{{ $postingReason }}">
                            <i class="ti ti-arrow-bar-to-down me-1"></i>Post to Inventory
                        </button>
                    @endif
                @endcan
            @elseif($receipt->isPosted())
                @can('tenant.manufacturing.finished-goods.reverse')
                    <form method="POST" action="{{ url('/manufacturing/finished-goods/' . $receipt->id . '/reverse') }}"
                          onsubmit="return confirm('Reverse FG posting? Inventory stock will be removed and the journal reversed. Only possible if the FG stock has not been sold.');">
                        @csrf
                        <button class="btn btn-outline-danger"><i class="ti ti-arrow-back-up me-1"></i>Reverse Posting</button>
                    </form>
                @endcan
            @endif
        </div>
    </div>
    @error('posting')<div class="card-footer text-danger small"><i class="ti ti-alert-circle me-1"></i>{{ $message }}</div>@enderror
</div>

{{-- WIP link info --}}
@if($receipt->wipJob)
<div class="alert alert-info d-flex justify-content-between align-items-center py-2">
    <span>
        <i class="ti ti-tools me-1"></i>
        WIP job <strong>{{ $receipt->wipJob->wip_no }}</strong> —
        Accumulated cost: <strong>Rs {{ number_format($wipAccumCost, 2) }}</strong> ·
        Completed: {{ number_format($receipt->wipJob->completed_quantity, 2) }} /
        {{ number_format($receipt->wipJob->planned_quantity, 2) }}
    </span>
    <a href="{{ url('/manufacturing/wip/' . $receipt->wip_job_id) }}" class="btn btn-sm btn-outline-secondary">
        View WIP Job
    </a>
</div>
@endif

{{-- Acceptance --}}
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="fw-semibold">Acceptance</span>
            <span class="text-muted small">{{ number_format($receipt->accepted_quantity, 2) }} accepted / {{ number_format($receipt->received_quantity, 2) }} received ({{ number_format($acc, 2) }}%)</span>
        </div>
        <div class="progress" style="height:12px;">
            <div class="progress-bar bg-{{ $acc >= 100 ? 'success' : ($acc > 0 ? 'warning' : 'secondary') }}"
                 style="width:{{ min(100, $acc) }}%" role="progressbar"
                 aria-valuenow="{{ $acc }}" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Receipt Summary</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted">FG No</dt>
                    <dd class="col-sm-7"><code>{{ $receipt->fg_no }}</code></dd>

                    <dt class="col-sm-5 text-muted">WIP Job</dt>
                    <dd class="col-sm-7">
                        @if($receipt->wipJob)
                            <a href="{{ url('/manufacturing/wip/' . $receipt->wip_job_id) }}">{{ $receipt->wipJob->wip_no }}</a>
                        @else — @endif
                    </dd>

                    <dt class="col-sm-5 text-muted">Production Order</dt>
                    <dd class="col-sm-7">
                        @if($receipt->productionOrder)
                            <a href="{{ url('/manufacturing/production-orders/' . $receipt->production_order_id) }}">{{ $receipt->productionOrder->order_no }}</a>
                        @else — @endif
                    </dd>

                    <dt class="col-sm-5 text-muted">Finished Product</dt>
                    <dd class="col-sm-7">
                        <strong>{{ $receipt->finishedProduct?->name }}</strong>
                        <small class="d-block text-muted">{{ $receipt->finishedProduct?->sku }}</small>
                    </dd>

                    <dt class="col-sm-5 text-muted">Customer</dt>
                    <dd class="col-sm-7">{{ $receipt->manufacturingCustomer?->name ?? '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Branch</dt>
                    <dd class="col-sm-7">{{ $receipt->branch?->name ?? '—' }}</dd>

                    <dt class="col-sm-5 text-muted">Receipt Date</dt>
                    <dd class="col-sm-7">{{ $receipt->receipt_date?->format('d M Y') }}</dd>

                    <dt class="col-sm-5 text-muted">Created By</dt>
                    <dd class="col-sm-7">{{ $receipt->createdBy?->name ?? '—' }}</dd>
                </dl>
                @if($receipt->notes)
                    <hr>
                    <p class="text-muted small mb-0">{{ $receipt->notes }}</p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0">Quantities</h6></div>
            <div class="card-body">
                <div class="row text-center g-2">
                    <div class="col"><div class="border rounded py-2"><div class="text-muted small">Planned</div><div class="fw-bold">{{ number_format($receipt->planned_quantity, 2) }}</div></div></div>
                    <div class="col"><div class="border rounded py-2"><div class="text-muted small">Received</div><div class="fw-bold">{{ number_format($receipt->received_quantity, 2) }}</div></div></div>
                    <div class="col"><div class="border rounded py-2 bg-success bg-opacity-10"><div class="text-muted small">Accepted</div><div class="fw-bold text-success">{{ number_format($receipt->accepted_quantity, 2) }}</div></div></div>
                    <div class="col"><div class="border rounded py-2"><div class="text-muted small">Rejected</div><div class="fw-bold text-danger">{{ number_format($receipt->rejected_quantity, 2) }}</div></div></div>
                    <div class="col"><div class="border rounded py-2"><div class="text-muted small">Scrap</div><div class="fw-bold text-warning">{{ number_format($receipt->scrap_quantity, 2) }}</div></div></div>
                </div>
                <p class="text-muted small mb-0 mt-2">Remaining to disposition: <strong>{{ number_format($receipt->remainingToAccept(), 4) }}</strong></p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">Output Batches / Lots <span class="badge bg-light text-dark border ms-1">{{ $receipt->lines->count() }}</span></h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Batch / Lot</th>
                                <th>Product</th>
                                <th>Unit</th>
                                <th class="text-end">Received</th>
                                <th class="text-end">Accepted</th>
                                <th class="text-end">Rejected</th>
                                <th class="text-end">Scrap</th>
                                <th>Expiry</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($receipt->lines as $line)
                            <tr>
                                <td>
                                    {{ $line->batch_no ?: '—' }}
                                    @if($line->lot_no)<small class="d-block text-muted">Lot: {{ $line->lot_no }}</small>@endif
                                </td>
                                <td>
                                    {{ $line->finishedProduct?->name }}
                                    <small class="d-block text-muted">{{ $line->finishedProduct?->sku }}</small>
                                </td>
                                <td>{{ $line->unit?->code ?? '—' }}</td>
                                <td class="text-end">{{ number_format($line->received_quantity, 4) }}</td>
                                <td class="text-end">{{ number_format($line->accepted_quantity, 4) }}</td>
                                <td class="text-end">{{ number_format($line->rejected_quantity, 4) }}</td>
                                <td class="text-end">{{ number_format($line->scrap_quantity, 4) }}</td>
                                <td>{{ $line->expiry_date?->format('d M Y') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-3">No output batch lines.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@can('tenant.manufacturing.finished-goods.destroy')
    @if(!$receipt->isClosed() && $receipt->isUnposted())
    <div class="mt-4 border-top pt-3">
        <form method="POST" action="{{ url('/manufacturing/finished-goods/' . $receipt->id) }}"
              onsubmit="return confirm('Cancel this finished goods receipt?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-danger btn-sm">Cancel Receipt</button>
        </form>
    </div>
    @endif
@endcan
@endsection
