@extends('layouts.app')

@section('title', 'Department Stock Documents')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Department Stock Documents</h1>
        <p class="fw-medium text-muted mb-0">Issue, return, and department-to-department custody movements.</p>
    </div>
    <div class="d-flex gap-2">
        @can('tenant.department-stock.index')
            <a href="{{ url('/department-stock') }}" class="btn btn-light"><i class="ti ti-building-warehouse me-1"></i>Balances</a>
        @endcan
        @can('tenant.department-stock.transfers.create')
            <a href="{{ url('/department-stock/transfers/create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>New Document</a>
        @endcan
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/department-stock/transfers') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="branch_id" class="form-label">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="transfer_type" class="form-label">Type</label>
                <select id="transfer_type" name="transfer_type" class="form-select">
                    <option value="">All types</option>
                    <option value="issue"    @selected(request('transfer_type') === 'issue')>Issue to Department</option>
                    <option value="return"   @selected(request('transfer_type') === 'return')>Return from Department</option>
                    <option value="transfer" @selected(request('transfer_type') === 'transfer')>Department to Department</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All statuses</option>
                    @foreach(['draft', 'posted', 'cancelled'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-dark">Filter</button>
                <a href="{{ url('/department-stock/transfers') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Department stock documents</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col">Document</th>
                    <th scope="col">Date</th>
                    <th scope="col">Type</th>
                    <th scope="col">Branch</th>
                    <th scope="col">From</th>
                    <th scope="col">To</th>
                    <th scope="col" class="text-end">Lines</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($transfers as $transfer)
                <tr>
                    <td><a href="{{ url('/department-stock/transfers/' . $transfer->id) }}" class="fw-semibold">{{ $transfer->transfer_no }}</a></td>
                    <td>{{ $transfer->transfer_date?->format('Y-m-d') }}</td>
                    <td>
                        @if($transfer->transfer_type === 'issue')
                            <span class="badge bg-success-subtle text-success-emphasis">Issue</span>
                        @elseif($transfer->transfer_type === 'return')
                            <span class="badge bg-warning-subtle text-warning-emphasis">Return</span>
                        @else
                            <span class="badge bg-info-subtle text-info-emphasis">Dept → Dept</span>
                        @endif
                    </td>
                    <td>{{ $transfer->branch?->name }}</td>
                    <td>{{ $transfer->fromDepartment?->name ?? 'Branch Pool' }}</td>
                    <td>{{ $transfer->toDepartment?->name ?? 'Branch Pool' }}</td>
                    <td class="text-end">{{ $transfer->lines_count }}</td>
                    <td>
                        @if($transfer->status === 'posted')
                            <span class="badge bg-success">Posted</span>
                        @elseif($transfer->status === 'cancelled')
                            <span class="badge bg-secondary">Cancelled</span>
                        @else
                            <span class="badge bg-warning text-dark">Draft</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ url('/department-stock/transfers/' . $transfer->id) }}" class="btn btn-sm btn-light">View</a>
                        @if($transfer->status === 'draft')
                            @can('tenant.department-stock.transfers.edit')
                                <a href="{{ url('/department-stock/transfers/' . $transfer->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No documents yet. Create an Issue to hand stock into a department's custody.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $transfers->links() }}</div>
    </div>
</div>
@endsection
