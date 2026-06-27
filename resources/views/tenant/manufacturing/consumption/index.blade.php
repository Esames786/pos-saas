@extends('layouts.app')

@section('title', 'Consumption')

@section('content')
@php
    $statusColors = \App\Models\Tenant\ManufacturingConsumptionRecord::STATUS_COLORS;
    $srcLabels = ['wip' => 'WIP', 'material_requisition' => 'MRC', 'manual' => 'Manual'];
@endphp

        <div class="page-header">
            <div class="page-title">
                <h4>Consumption</h4>
                <h6>Track planned vs consumed material — tracking only, no stock deduction or accounting posting</h6>
            </div>
            @can('tenant.manufacturing.consumption.create')
            <div class="page-btn">
                <a href="{{ url('/manufacturing/consumption/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>Record Consumption
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
                <form method="GET" action="{{ url('/manufacturing/consumption') }}" class="row g-2 align-items-end">
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label mb-1">Search</label>
                        <input type="text" name="q" class="form-control" placeholder="Cons/WIP/MRC/order/customer/product…"
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
                        <label class="form-label mb-1">Source</label>
                        <select name="source_type" class="select form-select">
                            <option value="">All sources</option>
                            @foreach($sourceTypes as $st)
                                <option value="{{ $st }}" {{ ($filters['source_type'] ?? '') === $st ? 'selected' : '' }}>{{ $srcLabels[$st] ?? ucfirst($st) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-4 col-md-2">
                        <label class="form-label mb-1">Type</label>
                        <select name="consumption_type" class="select form-select">
                            <option value="">All types</option>
                            @foreach($consumptionTypes as $ct)
                                <option value="{{ $ct }}" {{ ($filters['consumption_type'] ?? '') === $ct ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $ct)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-4 col-md-3">
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
                    <div class="col-sm-4 col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        @if(!empty(array_filter($filters)))
                            <a href="{{ url('/manufacturing/consumption') }}" class="btn btn-light" title="Clear"><i class="ti ti-x"></i> Reset</a>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">Consumption records list</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">Consumption No</th>
                                <th scope="col">Date</th>
                                <th scope="col">Source</th>
                                <th scope="col">WIP Job</th>
                                <th scope="col">MRC</th>
                                <th scope="col">Production Order</th>
                                <th scope="col">Branch</th>
                                <th scope="col">Type</th>
                                <th scope="col" class="text-end">Planned</th>
                                <th scope="col" class="text-end">Consumed</th>
                                <th scope="col" class="text-end">Wastage</th>
                                <th scope="col" class="text-end">Variance</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($records as $rec)
                            <tr>
                                <td><a href="{{ url('/manufacturing/consumption/' . $rec->id) }}" class="fw-semibold">{{ $rec->consumption_no }}</a></td>
                                <td>{{ $rec->consumption_date?->format('d M Y') }}</td>
                                <td>{{ $srcLabels[$rec->source_type] ?? ($rec->source_type ? ucfirst($rec->source_type) : '—') }}</td>
                                <td>{{ $rec->wipJob?->wip_no ?? '—' }}</td>
                                <td>{{ $rec->materialRequisition?->mrc_no ?? '—' }}</td>
                                <td>{{ $rec->productionOrder?->order_no ?? '—' }}</td>
                                <td>{{ $rec->branch?->name ?? '—' }}</td>
                                <td>{{ ucfirst(str_replace('_', ' ', $rec->consumption_type)) }}</td>
                                <td class="text-end">{{ number_format($rec->total_planned_quantity, 2) }}</td>
                                <td class="text-end">{{ number_format($rec->total_consumed_quantity, 2) }}</td>
                                <td class="text-end">{{ number_format($rec->total_wastage_quantity, 2) }}</td>
                                <td class="text-end {{ (float)$rec->total_variance_quantity > 0 ? 'text-danger' : ((float)$rec->total_variance_quantity < 0 ? 'text-warning' : 'text-success') }}">{{ number_format($rec->total_variance_quantity, 2) }}</td>
                                <td>
                                    <span class="badge bg-{{ $statusColors[$rec->status] ?? 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $rec->status)) }}</span>
                                    @unless($rec->isUnposted())
                                        <span class="badge bg-{{ $rec->postingStatusBadgeClass() }}" title="Posting status">{{ $rec->postingStatusLabel() }}</span>
                                    @endunless
                                </td>
                                <td class="text-end">
                                    @can('tenant.manufacturing.consumption.show')
                                        <a href="{{ url('/manufacturing/consumption/' . $rec->id) }}" class="btn btn-sm btn-outline-secondary me-1" title="View"><i class="ti ti-eye"></i></a>
                                    @endcan
                                    @can('tenant.manufacturing.consumption.edit')
                                        @if(!$rec->isClosed())
                                            <a href="{{ url('/manufacturing/consumption/' . $rec->id . '/edit') }}" class="btn btn-sm btn-outline-secondary me-1" title="Edit"><i class="ti ti-pencil"></i></a>
                                        @endif
                                    @endcan
                                    @can('tenant.manufacturing.consumption.destroy')
                                        @if(!$rec->isClosed())
                                        <form method="POST" action="{{ url('/manufacturing/consumption/' . $rec->id) }}"
                                              class="d-inline" onsubmit="return confirm('Cancel this consumption record?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancel"><i class="ti ti-ban"></i></button>
                                        </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="14" class="text-center text-muted py-4">
                                    No consumption records found.
                                    @can('tenant.manufacturing.consumption.create')
                                        <a href="{{ url('/manufacturing/consumption/create') }}">Record the first one.</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($records->hasPages())
                    <div class="mt-3">{{ $records->links() }}</div>
                @endif
            </div>
        </div>
@endsection
