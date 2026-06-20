@extends('layouts.app')

@section('title', 'Bill of Materials')

@section('content')
@php
    $statusColors = \App\Models\Tenant\ManufacturingBom::STATUS_COLORS;
@endphp

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Bill of Materials</h1>
        <p class="fw-medium text-muted">Define component requirements for each finished product. <span class="badge bg-info text-dark">Config only — no inventory posting.</span></p>
    </div>
    @can('tenant.manufacturing.bom.create')
        <a href="{{ url('/manufacturing/bom/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1"></i>Create BOM
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
        <form method="GET" action="{{ url('/manufacturing/bom') }}" class="row g-2 align-items-end">
            <div class="col-sm-6 col-md-4">
                <input type="text" name="q" class="form-control"
                       placeholder="BOM no, product name, SKU…"
                       value="{{ $filters['q'] ?? '' }}">
            </div>
            <div class="col-sm-4 col-md-2">
                <select name="status" class="form-select">
                    <option value="">All status</option>
                    @foreach($statuses as $s)
                        <option value="{{ $s }}" {{ ($filters['status'] ?? '') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-md-3">
                <select name="product_id" class="form-select">
                    <option value="">All products</option>
                    @foreach($products as $p)
                        <option value="{{ $p->id }}" {{ ($filters['product_id'] ?? '') == $p->id ? 'selected' : '' }}>
                            {{ $p->sku }} — {{ $p->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-4 col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary flex-grow-1">Filter</button>
                @if(!empty(array_filter($filters)))
                    <a href="{{ url('/manufacturing/bom') }}" class="btn btn-outline-secondary" title="Clear"><i class="ti ti-x"></i></a>
                @endif
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Bill of Materials list</caption>
            <thead class="thead-light">
                <tr>
                    <th>BOM No</th>
                    <th>Finished Product</th>
                    <th>Name / Version</th>
                    <th class="text-end">Output Qty</th>
                    <th class="text-center">Lines</th>
                    <th>Status</th>
                    <th>Effective From</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($boms as $bom)
                <tr>
                    <td><code>{{ $bom->bom_no }}</code></td>
                    <td>
                        <strong>{{ $bom->finishedProduct?->name }}</strong>
                        <small class="d-block text-muted">{{ $bom->finishedProduct?->sku }}</small>
                    </td>
                    <td>
                        {{ $bom->name ?: '—' }}
                        <small class="d-block text-muted">v{{ $bom->version }}</small>
                    </td>
                    <td class="text-end">{{ number_format($bom->output_quantity, 2) }}</td>
                    <td class="text-center">
                        <span class="badge bg-light text-dark border">{{ $bom->lines_count }}</span>
                    </td>
                    <td>
                        <span class="badge bg-{{ $statusColors[$bom->status] ?? 'secondary' }}">{{ ucfirst($bom->status) }}</span>
                    </td>
                    <td>{{ $bom->effective_from?->format('d M Y') ?? '—' }}</td>
                    <td class="text-end">
                        @can('tenant.manufacturing.bom.show')
                            <a href="{{ url('/manufacturing/bom/' . $bom->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                        @can('tenant.manufacturing.bom.edit')
                            @if($bom->status !== 'archived')
                                <a href="{{ url('/manufacturing/bom/' . $bom->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                            @endif
                        @endcan
                        @can('tenant.manufacturing.bom.destroy')
                            @if(!in_array($bom->status, ['archived']))
                            <form method="POST" action="{{ url('/manufacturing/bom/' . $bom->id) }}"
                                  class="d-inline" onsubmit="return confirm('Archive this BOM?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Archive</button>
                            </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-5">
                        No BOMs found.
                        @can('tenant.manufacturing.bom.create')
                            <a href="{{ url('/manufacturing/bom/create') }}">Create the first one.</a>
                        @endcan
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($boms->hasPages())
        <div class="card-footer">{{ $boms->links() }}</div>
    @endif
</div>
@endsection
