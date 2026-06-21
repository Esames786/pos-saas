@extends('layouts.app')

@section('title', 'Scrap / Hard Waste')

@section('content')
@php
    $statusColors = \App\Models\Tenant\ManufacturingScrapRecord::STATUS_COLORS;
    $srcLabels = ['wip' => 'WIP', 'finished_goods' => 'Finished Goods', 'manual' => 'Manual'];
@endphp

        <div class="page-header">
            <div class="page-title">
                <h4>Scrap / Hard Waste</h4>
                <h6>Record wasted / damaged / lost quantity — tracking only, no stock or accounting posting</h6>
            </div>
            @can('tenant.manufacturing.scrap.create')
            <div class="page-btn">
                <a href="{{ url('/manufacturing/scrap/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>Record Scrap
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
                <form method="GET" action="{{ url('/manufacturing/scrap') }}" class="row g-2 align-items-end">
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label mb-1">Search</label>
                        <input type="text" name="q" class="form-control" placeholder="Scrap/WIP/FG/order/customer/product…"
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
                        <select name="scrap_type" class="select form-select">
                            <option value="">All types</option>
                            @foreach($scrapTypes as $st)
                                <option value="{{ $st }}" {{ ($filters['scrap_type'] ?? '') === $st ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $st)) }}</option>
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
                        <label class="form-label mb-1">Reason</label>
                        <select name="reason_code" class="select form-select">
                            <option value="">All reasons</option>
                            @foreach($reasonCodes as $rc)
                                <option value="{{ $rc }}" {{ ($filters['reason_code'] ?? '') === $rc ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $rc)) }}</option>
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
                            <a href="{{ url('/manufacturing/scrap') }}" class="btn btn-light" title="Clear"><i class="ti ti-x"></i> Reset</a>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">Scrap records list</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">Scrap No</th>
                                <th scope="col">Date</th>
                                <th scope="col">Source</th>
                                <th scope="col">Production Order</th>
                                <th scope="col">Customer</th>
                                <th scope="col">Branch</th>
                                <th scope="col">Type</th>
                                <th scope="col">Reason</th>
                                <th scope="col" class="text-end">Total</th>
                                <th scope="col" class="text-end">Recoverable</th>
                                <th scope="col" class="text-end">Disposed</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($records as $rec)
                            <tr>
                                <td><a href="{{ url('/manufacturing/scrap/' . $rec->id) }}" class="fw-semibold">{{ $rec->scrap_no }}</a></td>
                                <td>{{ $rec->scrap_date?->format('d M Y') }}</td>
                                <td>{{ $srcLabels[$rec->source_type] ?? ($rec->source_type ? ucfirst($rec->source_type) : '—') }}</td>
                                <td>{{ $rec->productionOrder?->order_no ?? '—' }}</td>
                                <td>{{ $rec->manufacturingCustomer?->name ?? '—' }}</td>
                                <td>{{ $rec->branch?->name ?? '—' }}</td>
                                <td>{{ ucfirst(str_replace('_', ' ', $rec->scrap_type)) }}</td>
                                <td>{{ $rec->reason_code ? ucfirst(str_replace('_', ' ', $rec->reason_code)) : '—' }}</td>
                                <td class="text-end">{{ number_format($rec->total_quantity, 2) }}</td>
                                <td class="text-end">{{ number_format($rec->recoverable_quantity, 2) }}</td>
                                <td class="text-end">{{ number_format($rec->disposed_quantity, 2) }}</td>
                                <td><span class="badge bg-{{ $statusColors[$rec->status] ?? 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $rec->status)) }}</span></td>
                                <td class="text-end">
                                    @can('tenant.manufacturing.scrap.show')
                                        <a href="{{ url('/manufacturing/scrap/' . $rec->id) }}" class="btn btn-sm btn-outline-secondary me-1" title="View"><i class="ti ti-eye"></i></a>
                                    @endcan
                                    @can('tenant.manufacturing.scrap.edit')
                                        @if(!$rec->isClosed())
                                            <a href="{{ url('/manufacturing/scrap/' . $rec->id . '/edit') }}" class="btn btn-sm btn-outline-secondary me-1" title="Edit"><i class="ti ti-pencil"></i></a>
                                        @endif
                                    @endcan
                                    @can('tenant.manufacturing.scrap.destroy')
                                        @if(!$rec->isClosed())
                                        <form method="POST" action="{{ url('/manufacturing/scrap/' . $rec->id) }}"
                                              class="d-inline" onsubmit="return confirm('Cancel this scrap record?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancel"><i class="ti ti-ban"></i></button>
                                        </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="13" class="text-center text-muted py-4">
                                    No scrap records found.
                                    @can('tenant.manufacturing.scrap.create')
                                        <a href="{{ url('/manufacturing/scrap/create') }}">Record the first one.</a>
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
