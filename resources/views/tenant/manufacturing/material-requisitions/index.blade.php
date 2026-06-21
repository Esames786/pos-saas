@extends('layouts.app')

@section('title', 'Material Requisitions')

@section('content')
@php
    $statusColors = \App\Models\Tenant\MaterialRequisition::STATUS_COLORS;
    $priorityColors = \App\Models\Tenant\MaterialRequisition::PRIORITY_COLORS;
@endphp

        <div class="page-header">
            <div class="page-title">
                <h4>Material Requisitions</h4>
                <h6>Request raw materials/components for production runs — planning only, no stock issue yet</h6>
            </div>
            @can('tenant.manufacturing.material-requisitions.create')
            <div class="page-btn">
                <a href="{{ url('/manufacturing/material-requisitions/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>Create MRC
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
                <form method="GET" action="{{ url('/manufacturing/material-requisitions') }}" class="row g-2 align-items-end">
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label mb-1">Search</label>
                        <input type="text" name="q" class="form-control" placeholder="MRC no, order no, customer…"
                               value="{{ $filters['q'] ?? '' }}">
                    </div>
                    <div class="col-sm-4 col-md-2">
                        <label class="form-label mb-1">Status</label>
                        <select name="status" class="select form-select">
                            <option value="">All status</option>
                            @foreach($statuses as $s)
                                <option value="{{ $s }}" {{ ($filters['status'] ?? '') === $s ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-4 col-md-2">
                        <label class="form-label mb-1">Branch</label>
                        <select name="branch_id" class="select form-select">
                            <option value="">All branches</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ ($filters['branch_id'] ?? '') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-4 col-md-2">
                        <label class="form-label mb-1">From</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="col-sm-4 col-md-2">
                        <label class="form-label mb-1">To</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    <div class="col-sm-4 col-md-1 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100">Go</button>
                        @if(!empty(array_filter($filters)))
                            <a href="{{ url('/manufacturing/material-requisitions') }}" class="btn btn-light" title="Clear"><i class="ti ti-x"></i></a>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">Material requisitions list</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">MRC No</th>
                                <th scope="col">Request Date</th>
                                <th scope="col">Production Order</th>
                                <th scope="col">Customer</th>
                                <th scope="col">Branch</th>
                                <th scope="col" class="text-center">Lines</th>
                                <th scope="col">Required Date</th>
                                <th scope="col">Status</th>
                                <th scope="col">Priority</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($requisitions as $mrc)
                            <tr>
                                <td><a href="{{ url('/manufacturing/material-requisitions/' . $mrc->id) }}" class="fw-semibold">{{ $mrc->mrc_no }}</a></td>
                                <td>{{ $mrc->request_date?->format('d M Y') }}</td>
                                <td>{{ $mrc->productionOrder?->order_no ?? '—' }}</td>
                                <td>{{ $mrc->manufacturingCustomer?->name ?? '—' }}</td>
                                <td>{{ $mrc->branch?->name ?? '—' }}</td>
                                <td class="text-center"><span class="badge bg-light text-dark border">{{ $mrc->lines_count }}</span></td>
                                <td>{{ $mrc->required_date?->format('d M Y') ?? '—' }}</td>
                                <td><span class="badge bg-{{ $statusColors[$mrc->status] ?? 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $mrc->status)) }}</span></td>
                                <td>
                                    @if($mrc->priority)
                                        <span class="badge bg-{{ $priorityColors[$mrc->priority] ?? 'secondary' }}">{{ ucfirst($mrc->priority) }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @can('tenant.manufacturing.material-requisitions.show')
                                        <a href="{{ url('/manufacturing/material-requisitions/' . $mrc->id) }}" class="btn btn-sm btn-outline-secondary me-1" title="View"><i class="ti ti-eye"></i></a>
                                    @endcan
                                    @can('tenant.manufacturing.material-requisitions.edit')
                                        @if(!$mrc->isClosed())
                                            <a href="{{ url('/manufacturing/material-requisitions/' . $mrc->id . '/edit') }}" class="btn btn-sm btn-outline-secondary me-1" title="Edit"><i class="ti ti-pencil"></i></a>
                                        @endif
                                    @endcan
                                    @can('tenant.manufacturing.material-requisitions.destroy')
                                        @if(!$mrc->isClosed())
                                        <form method="POST" action="{{ url('/manufacturing/material-requisitions/' . $mrc->id) }}"
                                              class="d-inline" onsubmit="return confirm('Cancel this requisition?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancel"><i class="ti ti-ban"></i></button>
                                        </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    No material requisitions found.
                                    @can('tenant.manufacturing.material-requisitions.create')
                                        <a href="{{ url('/manufacturing/material-requisitions/create') }}">Create the first one.</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($requisitions->hasPages())
                    <div class="mt-3">{{ $requisitions->links() }}</div>
                @endif
            </div>
        </div>
@endsection
