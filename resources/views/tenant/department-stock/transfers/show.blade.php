@extends('layouts.app')

@section('title', 'Custody Document ' . $transfer->transfer_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">
            {{ $transfer->transfer_no }}
            @if($transfer->status === 'posted')
                <span class="badge bg-success align-middle">Posted</span>
            @elseif($transfer->status === 'cancelled')
                <span class="badge bg-secondary align-middle">Cancelled</span>
            @else
                <span class="badge bg-warning text-dark align-middle">Draft</span>
            @endif
        </h1>
        <p class="fw-medium text-muted mb-0">
            {{ $transfer->typeLabel() }} · {{ $transfer->branch?->name }} · {{ $transfer->transfer_date?->format('Y-m-d') }}
        </p>
    </div>
    <div class="d-flex gap-2">
        @if($transfer->status === 'draft')
            @can('tenant.department-stock.transfers.edit')
                <a href="{{ url('/department-stock/transfers/' . $transfer->id . '/edit') }}" class="btn btn-primary">Edit Draft</a>
            @endcan
            @can('tenant.department-stock.transfers.post')
                <form method="POST" action="{{ url('/department-stock/transfers/' . $transfer->id . '/post') }}" class="d-inline"
                      onsubmit="return confirm('Post this custody document? Department stock will move. Official branch stock will NOT change.')">
                    @csrf
                    <button class="btn btn-success"><i class="ti ti-check me-1"></i>Post Document</button>
                </form>
            @endcan
            @can('tenant.department-stock.transfers.cancel')
                <form method="POST" action="{{ url('/department-stock/transfers/' . $transfer->id . '/cancel') }}" class="d-inline"
                      onsubmit="return confirm('Cancel this draft?')">
                    @csrf
                    <button class="btn btn-outline-danger">Cancel Draft</button>
                </form>
            @endcan
        @endif
        <a href="{{ url('/department-stock/transfers') }}" class="btn btn-light">Back</a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="row g-3 mb-3">
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-body row g-3 small">
                <div class="col-md-3"><div class="text-muted">Type</div><div class="fw-semibold">{{ $transfer->typeLabel() }}</div></div>
                <div class="col-md-3"><div class="text-muted">From</div><div class="fw-semibold">{{ $transfer->fromDepartment?->name ?? 'Branch Pool' }}</div></div>
                <div class="col-md-3"><div class="text-muted">To</div><div class="fw-semibold">{{ $transfer->toDepartment?->name ?? 'Branch Pool' }}</div></div>
                <div class="col-md-3"><div class="text-muted">Created By</div><div class="fw-semibold">{{ $transfer->createdBy?->name ?? '—' }}</div></div>
                @if($transfer->status === 'posted')
                    <div class="col-md-3"><div class="text-muted">Posted By</div><div class="fw-semibold">{{ $transfer->postedBy?->name ?? '—' }}</div></div>
                    <div class="col-md-3"><div class="text-muted">Posted At</div><div class="fw-semibold">{{ $transfer->posted_at?->format('Y-m-d H:i') }}</div></div>
                @endif
                @if($transfer->notes)
                    <div class="col-12"><div class="text-muted">Notes</div><div>{{ $transfer->notes }}</div></div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 border-primary-subtle">
            <div class="card-body small">
                <span class="badge bg-primary-subtle text-primary-emphasis mb-2"><i class="ti ti-building-warehouse me-1"></i>Custody only</span>
                <div>This document moves <strong>department custody</strong> only. Official branch stock, purchase costs, and accounting are not affected.</div>
                @if($transfer->status === 'posted')
                    <div class="text-muted mt-2">Posted custody movements cannot be cancelled in this phase — create an opposite document (return / reverse transfer) to correct a mistake.</div>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Lines</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th scope="col">Product</th>
                    <th scope="col">Variant</th>
                    <th scope="col" class="text-end">Qty</th>
                    <th scope="col" class="text-end">Unit Cost</th>
                    <th scope="col" class="text-end">Line Total</th>
                    @if($transfer->status === 'draft')
                        <th scope="col" class="text-end">Available to Issue</th>
                        <th scope="col" class="text-end">Source Dept Stock</th>
                    @endif
                    <th scope="col">Notes</th>
                </tr>
            </thead>
            <tbody>
            @foreach($transfer->lines as $line)
                <tr>
                    <td>{{ $line->product?->sku ? $line->product->sku . ' — ' : '' }}{{ $line->product?->name }}</td>
                    <td>{{ $line->variant?->name ?? 'Default' }}</td>
                    <td class="text-end fw-semibold">{{ number_format($line->quantity, 3) }} {{ $line->product?->unit?->code }}</td>
                    <td class="text-end">{{ number_format($line->unit_cost, 4) }}</td>
                    <td class="text-end">{{ number_format((float) $line->quantity * (float) $line->unit_cost, 2) }}</td>
                    @if($transfer->status === 'draft')
                        @php $avail = $availability[$line->id] ?? null; @endphp
                        <td class="text-end">
                            @if($avail)
                                <span class="badge {{ ($avail['available_to_issue'] ?? 0) >= (float) $line->quantity ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}">
                                    {{ number_format($avail['available_to_issue'], 3) }}
                                </span>
                            @else — @endif
                        </td>
                        <td class="text-end">
                            @if($avail && $avail['source_on_hand'] !== null)
                                <span class="badge {{ $avail['source_on_hand'] >= (float) $line->quantity ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}">
                                    {{ number_format($avail['source_on_hand'], 3) }}
                                </span>
                            @else — @endif
                        </td>
                    @endif
                    <td class="small text-muted">{{ $line->notes ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="table-light fw-semibold">
                <tr>
                    <td colspan="4" class="text-end">Document Total</td>
                    <td class="text-end">{{ number_format($transfer->lines->sum(fn ($l) => (float) $l->quantity * (float) $l->unit_cost), 2) }}</td>
                    <td colspan="{{ $transfer->status === 'draft' ? 3 : 1 }}"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@endsection
