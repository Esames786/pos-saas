@extends('layouts.app')

@section('title', 'Purchase Returns')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Purchase Returns</h1>
        <p class="fw-medium text-muted mb-0">Return goods to suppliers — reduces branch stock and the supplier payable.</p>
    </div>
    @can('tenant.purchase-returns.create')
        <a href="{{ url('/purchase-returns/create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>New Return</a>
    @endcan
</div>

<div class="card border-warning-subtle mb-3">
    <div class="card-body py-2 small">
        <i class="ti ti-info-circle me-1"></i>
        Flow: PO → GRN → Bill → Payment → <strong>Return</strong>.
        Posting a return <strong>reduces official branch stock</strong> (FEFO) and <strong>reduces the supplier payable</strong>
        (a fully paid supplier goes into credit). Posted returns are immutable.
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
        <form method="GET" action="{{ url('/purchase-returns') }}" class="row g-3 align-items-end">
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
                <label for="supplier_id" class="form-label">Supplier</label>
                <select id="supplier_id" name="supplier_id" class="form-select">
                    <option value="">All suppliers</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(request('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All</option>
                    @foreach(['draft', 'posted', 'cancelled'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-dark">Filter</button>
                <a href="{{ url('/purchase-returns') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Purchase returns list</caption>
            <thead class="table-light">
                <tr>
                    <th>Return No</th><th>Date</th><th>Branch</th><th>Supplier</th><th>Source GRN</th>
                    <th class="text-end">Lines</th><th class="text-end">Total</th><th>Status</th><th>Posted</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($returns as $return)
                <tr>
                    <td><a href="{{ url('/purchase-returns/' . $return->id) }}" class="fw-semibold">{{ $return->return_no }}</a></td>
                    <td>{{ $return->return_date?->format('Y-m-d') }}</td>
                    <td>{{ $return->branch?->name }}</td>
                    <td>{{ $return->supplier?->name }}</td>
                    <td>{{ $return->goodsReceipt?->grn_no ?? '—' }}</td>
                    <td class="text-end">{{ $return->lines_count }}</td>
                    <td class="text-end fw-semibold">{{ number_format($return->grand_total, 2) }}</td>
                    <td>
                        @if($return->status === 'posted')
                            <span class="badge bg-success">Posted</span>
                        @elseif($return->status === 'cancelled')
                            <span class="badge bg-secondary">Cancelled</span>
                        @else
                            <span class="badge bg-warning text-dark">Draft</span>
                        @endif
                    </td>
                    <td class="small">{{ $return->postedBy?->name }}{{ $return->posted_at ? ' · ' . $return->posted_at->format('m-d H:i') : '' }}</td>
                    <td class="text-end">
                        <a href="{{ url('/purchase-returns/' . $return->id) }}" class="btn btn-sm btn-light">View</a>
                        @if($return->isDraft())
                            @can('tenant.purchase-returns.edit')
                                <a href="{{ url('/purchase-returns/' . $return->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="text-center text-muted py-4">No purchase returns yet. Create one to return goods to a supplier.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $returns->links() }}</div>
    </div>
</div>
@endsection
