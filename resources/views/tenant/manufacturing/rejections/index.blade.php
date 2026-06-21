@extends('layouts.app')

@section('title', 'Rejections')

@section('content')
@php
    $statusColors   = \App\Models\Tenant\ManufacturingRejectionRecord::STATUS_COLORS;
    $severityColors = \App\Models\Tenant\ManufacturingRejectionRecord::SEVERITY_COLORS;
    $srcLabels = ['wip' => 'WIP', 'finished_goods' => 'Finished Goods', 'manual' => 'Manual'];
@endphp

        <div class="page-header">
            <div class="page-title">
                <h4>Rejections</h4>
                <h6>Record QC rejections, defects and disposition — tracking only, no stock or accounting posting</h6>
            </div>
            @can('tenant.manufacturing.rejections.create')
            <div class="page-btn">
                <a href="{{ url('/manufacturing/rejections/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>Record Rejection
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
                <form method="GET" action="{{ url('/manufacturing/rejections') }}" class="row g-2 align-items-end">
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label mb-1">Search</label>
                        <input type="text" name="q" class="form-control" placeholder="Rej/WIP/FG/order/customer/product…"
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
                        <select name="rejection_type" class="select form-select">
                            <option value="">All types</option>
                            @foreach($rejectionTypes as $rt)
                                <option value="{{ $rt }}" {{ ($filters['rejection_type'] ?? '') === $rt ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $rt)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-4 col-md-2">
                        <label class="form-label mb-1">Severity</label>
                        <select name="severity" class="select form-select">
                            <option value="">All</option>
                            @foreach($severities as $sv)
                                <option value="{{ $sv }}" {{ ($filters['severity'] ?? '') === $sv ? 'selected' : '' }}>{{ ucfirst($sv) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-4 col-md-2">
                        <label class="form-label mb-1">Disposition</label>
                        <select name="disposition" class="select form-select">
                            <option value="">All</option>
                            @foreach($dispositions as $d)
                                <option value="{{ $d }}" {{ ($filters['disposition'] ?? '') === $d ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $d)) }}</option>
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
                    <div class="col-sm-4 col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        @if(!empty(array_filter($filters)))
                            <a href="{{ url('/manufacturing/rejections') }}" class="btn btn-light" title="Clear"><i class="ti ti-x"></i> Reset</a>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">Rejection records list</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">Rejection No</th>
                                <th scope="col">Date</th>
                                <th scope="col">Source</th>
                                <th scope="col">Production Order</th>
                                <th scope="col">Customer</th>
                                <th scope="col">Branch</th>
                                <th scope="col">Type</th>
                                <th scope="col">Severity</th>
                                <th scope="col">Disposition</th>
                                <th scope="col" class="text-end">Total</th>
                                <th scope="col" class="text-end">Rework</th>
                                <th scope="col" class="text-end">Scrap</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($records as $rec)
                            <tr>
                                <td><a href="{{ url('/manufacturing/rejections/' . $rec->id) }}" class="fw-semibold">{{ $rec->rejection_no }}</a></td>
                                <td>{{ $rec->rejection_date?->format('d M Y') }}</td>
                                <td>{{ $srcLabels[$rec->source_type] ?? ($rec->source_type ? ucfirst($rec->source_type) : '—') }}</td>
                                <td>{{ $rec->productionOrder?->order_no ?? '—' }}</td>
                                <td>{{ $rec->manufacturingCustomer?->name ?? '—' }}</td>
                                <td>{{ $rec->branch?->name ?? '—' }}</td>
                                <td>{{ ucfirst(str_replace('_', ' ', $rec->rejection_type)) }}</td>
                                <td>
                                    @if($rec->severity)
                                        <span class="badge bg-{{ $severityColors[$rec->severity] ?? 'secondary' }}">{{ ucfirst($rec->severity) }}</span>
                                    @else <span class="text-muted">—</span> @endif
                                </td>
                                <td>{{ $rec->disposition ? ucfirst(str_replace('_', ' ', $rec->disposition)) : '—' }}</td>
                                <td class="text-end">{{ number_format($rec->total_quantity, 2) }}</td>
                                <td class="text-end">{{ number_format($rec->rework_quantity, 2) }}</td>
                                <td class="text-end">{{ number_format($rec->scrap_quantity, 2) }}</td>
                                <td><span class="badge bg-{{ $statusColors[$rec->status] ?? 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $rec->status)) }}</span></td>
                                <td class="text-end">
                                    @can('tenant.manufacturing.rejections.show')
                                        <a href="{{ url('/manufacturing/rejections/' . $rec->id) }}" class="btn btn-sm btn-outline-secondary me-1" title="View"><i class="ti ti-eye"></i></a>
                                    @endcan
                                    @can('tenant.manufacturing.rejections.edit')
                                        @if(!$rec->isClosed())
                                            <a href="{{ url('/manufacturing/rejections/' . $rec->id . '/edit') }}" class="btn btn-sm btn-outline-secondary me-1" title="Edit"><i class="ti ti-pencil"></i></a>
                                        @endif
                                    @endcan
                                    @can('tenant.manufacturing.rejections.destroy')
                                        @if(!$rec->isClosed())
                                        <form method="POST" action="{{ url('/manufacturing/rejections/' . $rec->id) }}"
                                              class="d-inline" onsubmit="return confirm('Cancel this rejection record?')">
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
                                    No rejection records found.
                                    @can('tenant.manufacturing.rejections.create')
                                        <a href="{{ url('/manufacturing/rejections/create') }}">Record the first one.</a>
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
