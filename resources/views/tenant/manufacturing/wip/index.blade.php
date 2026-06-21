@extends('layouts.app')

@section('title', 'Work in Process')

@section('content')
@php
    $statusColors = \App\Models\Tenant\WipJob::STATUS_COLORS;
@endphp

        <div class="page-header">
            <div class="page-title">
                <h4>Work in Process</h4>
                <h6>Track in-process production jobs — tracking/planning only, no stock or accounting posting</h6>
            </div>
            @can('tenant.manufacturing.wip.create')
            <div class="page-btn">
                <a href="{{ url('/manufacturing/wip/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>Create WIP Job
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
                <form method="GET" action="{{ url('/manufacturing/wip') }}" class="row g-2 align-items-end">
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label mb-1">Search</label>
                        <input type="text" name="q" class="form-control" placeholder="WIP no, order no, customer, product…"
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
                            <a href="{{ url('/manufacturing/wip') }}" class="btn btn-light" title="Clear"><i class="ti ti-x"></i></a>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">WIP jobs list</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">WIP No</th>
                                <th scope="col">Start Date</th>
                                <th scope="col">Production Order</th>
                                <th scope="col">Finished Product</th>
                                <th scope="col">Customer</th>
                                <th scope="col">Branch</th>
                                <th scope="col" class="text-end">Planned</th>
                                <th scope="col" class="text-end">Completed</th>
                                <th scope="col" style="min-width:120px;">Progress</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($jobs as $job)
                            <tr>
                                <td><a href="{{ url('/manufacturing/wip/' . $job->id) }}" class="fw-semibold">{{ $job->wip_no }}</a></td>
                                <td>{{ $job->start_date?->format('d M Y') }}</td>
                                <td>{{ $job->productionOrder?->order_no ?? '—' }}</td>
                                <td>
                                    {{ $job->finishedProduct?->name }}
                                    <small class="d-block text-muted">{{ $job->finishedProduct?->sku }}</small>
                                </td>
                                <td>{{ $job->manufacturingCustomer?->name ?? '—' }}</td>
                                <td>{{ $job->branch?->name ?? '—' }}</td>
                                <td class="text-end">{{ number_format($job->planned_quantity, 2) }}</td>
                                <td class="text-end">{{ number_format($job->completed_quantity, 2) }}</td>
                                <td>
                                    @php $pct = (float) $job->progress_percent; @endphp
                                    <div class="progress" style="height:8px;" title="{{ number_format($pct, 2) }}%">
                                        <div class="progress-bar bg-{{ $pct >= 100 ? 'success' : ($pct > 0 ? 'warning' : 'secondary') }}"
                                             style="width:{{ min(100, $pct) }}%" role="progressbar"
                                             aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <small class="text-muted">{{ number_format($pct, 0) }}%</small>
                                </td>
                                <td><span class="badge bg-{{ $statusColors[$job->status] ?? 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $job->status)) }}</span></td>
                                <td class="text-end">
                                    @can('tenant.manufacturing.wip.show')
                                        <a href="{{ url('/manufacturing/wip/' . $job->id) }}" class="btn btn-sm btn-outline-secondary me-1" title="View"><i class="ti ti-eye"></i></a>
                                    @endcan
                                    @can('tenant.manufacturing.wip.edit')
                                        @if(!$job->isClosed())
                                            <a href="{{ url('/manufacturing/wip/' . $job->id . '/edit') }}" class="btn btn-sm btn-outline-secondary me-1" title="Edit"><i class="ti ti-pencil"></i></a>
                                        @endif
                                    @endcan
                                    @can('tenant.manufacturing.wip.destroy')
                                        @if(!$job->isClosed())
                                        <form method="POST" action="{{ url('/manufacturing/wip/' . $job->id) }}"
                                              class="d-inline" onsubmit="return confirm('Cancel this WIP job?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancel"><i class="ti ti-ban"></i></button>
                                        </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">
                                    No WIP jobs found.
                                    @can('tenant.manufacturing.wip.create')
                                        <a href="{{ url('/manufacturing/wip/create') }}">Create the first one.</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($jobs->hasPages())
                    <div class="mt-3">{{ $jobs->links() }}</div>
                @endif
            </div>
        </div>
@endsection
