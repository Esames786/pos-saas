@extends('layouts.app')

@section('title', 'Finished Goods')

@section('content')
@php
    $statusColors  = \App\Models\Tenant\FinishedGoodReceipt::STATUS_COLORS;
    $qualityColors = \App\Models\Tenant\FinishedGoodReceipt::QUALITY_COLORS;
@endphp

        <div class="page-header">
            <div class="page-title">
                <h4>Finished Goods</h4>
                <h6>Record production output from WIP jobs — tracking only, no inventory or accounting posting</h6>
            </div>
            @can('tenant.manufacturing.finished-goods.create')
            <div class="page-btn">
                <a href="{{ url('/manufacturing/finished-goods/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>Record Finished Goods
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
                <form method="GET" action="{{ url('/manufacturing/finished-goods') }}" class="row g-2 align-items-end">
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label mb-1">Search</label>
                        <input type="text" name="q" class="form-control" placeholder="FG no, WIP no, order, customer, product…"
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
                        <label class="form-label mb-1">Quality</label>
                        <select name="quality_status" class="select form-select">
                            <option value="">All</option>
                            @foreach($qualityStatuses as $qs)
                                <option value="{{ $qs }}" {{ ($filters['quality_status'] ?? '') === $qs ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $qs)) }}</option>
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
                    <div class="col-sm-4 col-md-1">
                        <label class="form-label mb-1">To</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    <div class="col-sm-4 col-md-12 d-flex gap-2 mt-2">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        @if(!empty(array_filter($filters)))
                            <a href="{{ url('/manufacturing/finished-goods') }}" class="btn btn-light" title="Clear"><i class="ti ti-x"></i> Reset</a>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">Finished goods receipts list</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">FG No</th>
                                <th scope="col">Receipt Date</th>
                                <th scope="col">WIP Job</th>
                                <th scope="col">Production Order</th>
                                <th scope="col">Finished Product</th>
                                <th scope="col">Customer</th>
                                <th scope="col">Branch</th>
                                <th scope="col" class="text-end">Received</th>
                                <th scope="col" class="text-end">Accepted</th>
                                <th scope="col">Quality</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($receipts as $fg)
                            <tr>
                                <td><a href="{{ url('/manufacturing/finished-goods/' . $fg->id) }}" class="fw-semibold">{{ $fg->fg_no }}</a></td>
                                <td>{{ $fg->receipt_date?->format('d M Y') }}</td>
                                <td>{{ $fg->wipJob?->wip_no ?? '—' }}</td>
                                <td>{{ $fg->productionOrder?->order_no ?? '—' }}</td>
                                <td>
                                    {{ $fg->finishedProduct?->name }}
                                    <small class="d-block text-muted">{{ $fg->finishedProduct?->sku }}</small>
                                </td>
                                <td>{{ $fg->manufacturingCustomer?->name ?? '—' }}</td>
                                <td>{{ $fg->branch?->name ?? '—' }}</td>
                                <td class="text-end">{{ number_format($fg->received_quantity, 2) }}</td>
                                <td class="text-end">{{ number_format($fg->accepted_quantity, 2) }}</td>
                                <td>
                                    @if($fg->quality_status)
                                        <span class="badge bg-{{ $qualityColors[$fg->quality_status] ?? 'secondary' }} {{ ($fg->quality_status === 'not_required') ? 'text-dark' : '' }}">{{ ucfirst(str_replace('_', ' ', $fg->quality_status)) }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td><span class="badge bg-{{ $statusColors[$fg->status] ?? 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $fg->status)) }}</span></td>
                                <td class="text-end">
                                    @can('tenant.manufacturing.finished-goods.show')
                                        <a href="{{ url('/manufacturing/finished-goods/' . $fg->id) }}" class="btn btn-sm btn-outline-secondary me-1" title="View"><i class="ti ti-eye"></i></a>
                                    @endcan
                                    @can('tenant.manufacturing.finished-goods.edit')
                                        @if(!$fg->isClosed())
                                            <a href="{{ url('/manufacturing/finished-goods/' . $fg->id . '/edit') }}" class="btn btn-sm btn-outline-secondary me-1" title="Edit"><i class="ti ti-pencil"></i></a>
                                        @endif
                                    @endcan
                                    @can('tenant.manufacturing.finished-goods.destroy')
                                        @if(!$fg->isClosed())
                                        <form method="POST" action="{{ url('/manufacturing/finished-goods/' . $fg->id) }}"
                                              class="d-inline" onsubmit="return confirm('Cancel this finished goods receipt?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancel"><i class="ti ti-ban"></i></button>
                                        </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="text-center text-muted py-4">
                                    No finished goods records found.
                                    @can('tenant.manufacturing.finished-goods.create')
                                        <a href="{{ url('/manufacturing/finished-goods/create') }}">Record the first one.</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($receipts->hasPages())
                    <div class="mt-3">{{ $receipts->links() }}</div>
                @endif
            </div>
        </div>
@endsection
