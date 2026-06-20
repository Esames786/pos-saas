@extends('layouts.app')

@section('title', 'Bill of Materials')

@section('content')
@php
    $statusColors = \App\Models\Tenant\ManufacturingBom::STATUS_COLORS;
@endphp

        <div class="page-header">
            <div class="page-title">
                <h4>Bill of Materials</h4>
                <h6>Define component requirements per finished product — configuration only, no inventory posting</h6>
            </div>
            @can('tenant.manufacturing.bom.create')
            <div class="page-btn">
                <a href="{{ url('/manufacturing/bom/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>Create BOM
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
                <form method="GET" action="{{ url('/manufacturing/bom') }}" class="row g-2 align-items-end">
                    <div class="col-sm-6 col-md-4">
                        <label class="form-label mb-1">Search</label>
                        <input type="text" name="q" class="form-control" placeholder="BOM no, product name, SKU…"
                               value="{{ $filters['q'] ?? '' }}">
                    </div>
                    <div class="col-sm-4 col-md-2">
                        <label class="form-label mb-1">Status</label>
                        <select name="status" class="select form-select">
                            <option value="">All status</option>
                            @foreach($statuses as $s)
                                <option value="{{ $s }}" {{ ($filters['status'] ?? '') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <label class="form-label mb-1">Finished Product</label>
                        <select name="product_id" class="select form-select">
                            <option value="">All products</option>
                            @foreach($products as $p)
                                <option value="{{ $p->id }}" {{ ($filters['product_id'] ?? '') == $p->id ? 'selected' : '' }}>
                                    {{ $p->sku }} — {{ $p->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-4 col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        @if(!empty(array_filter($filters)))
                            <a href="{{ url('/manufacturing/bom') }}" class="btn btn-light" title="Clear"><i class="ti ti-x"></i></a>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">Bill of Materials list</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">BOM No</th>
                                <th scope="col">Finished Product</th>
                                <th scope="col">Name / Version</th>
                                <th scope="col" class="text-end">Output Qty</th>
                                <th scope="col" class="text-center">Lines</th>
                                <th scope="col">Status</th>
                                <th scope="col">Effective From</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($boms as $bom)
                            <tr>
                                <td><a href="{{ url('/manufacturing/bom/' . $bom->id) }}" class="fw-semibold">{{ $bom->bom_no }}</a></td>
                                <td>
                                    {{ $bom->finishedProduct?->name }}
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
                                        <a href="{{ url('/manufacturing/bom/' . $bom->id) }}" class="btn btn-sm btn-outline-secondary me-1" title="View"><i class="ti ti-eye"></i></a>
                                    @endcan
                                    @can('tenant.manufacturing.bom.edit')
                                        @if($bom->status !== 'archived')
                                            <a href="{{ url('/manufacturing/bom/' . $bom->id . '/edit') }}" class="btn btn-sm btn-outline-secondary me-1" title="Edit"><i class="ti ti-pencil"></i></a>
                                        @endif
                                    @endcan
                                    @can('tenant.manufacturing.bom.destroy')
                                        @if($bom->status !== 'archived')
                                        <form method="POST" action="{{ url('/manufacturing/bom/' . $bom->id) }}"
                                              class="d-inline" onsubmit="return confirm('Archive this BOM?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Archive"><i class="ti ti-archive"></i></button>
                                        </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
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
                    <div class="mt-3">{{ $boms->links() }}</div>
                @endif
            </div>
        </div>
@endsection
