@extends('layouts.app')

@section('title', 'Department Stock')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Department Stock</h1>
        <p class="fw-medium text-muted mb-0">Custody balances — which department is holding how much, inside each branch.</p>
    </div>
    <div class="d-flex gap-2">
        @can('tenant.department-stock.transfers.index')
            <a href="{{ url('/department-stock/transfers') }}" class="btn btn-light"><i class="ti ti-file-invoice me-1"></i>Documents</a>
        @endcan
        @can('tenant.department-stock.transfers.create')
            <a href="{{ url('/department-stock/transfers/create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>New Issue / Return / Transfer</a>
        @endcan
    </div>
</div>

<div class="card border-primary-subtle mb-3">
    <div class="card-body d-flex flex-wrap align-items-start gap-3 py-2">
        <span class="badge bg-primary-subtle text-primary-emphasis mt-1"><i class="ti ti-building-warehouse me-1"></i>Custody only</span>
        <div class="small">
            Department stock is an internal <strong>custody sub-ledger</strong> inside a branch.
            It does <strong>not</strong> change official branch stock or accounting.
            Use <strong>Issue</strong> to give stock to a department, <strong>Return</strong> to bring it back, and <strong>Transfer</strong> to move it between departments.
        </div>
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
        <form method="GET" action="{{ url('/department-stock') }}" class="row g-3 align-items-end">
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
                <label for="department_id" class="form-label">Department</label>
                <select id="department_id" name="department_id" class="form-select">
                    <option value="">All departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}" @selected(request('department_id') == $dept->id)>{{ $dept->name }} ({{ $dept->branch?->name }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="search" class="form-label">Product</label>
                <input id="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="Name or SKU">
            </div>
            <div class="col-md-2">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="nonzero" value="1" id="nonzero" @checked(request()->boolean('nonzero', true))>
                    <label class="form-check-label" for="nonzero">Only non-zero</label>
                </div>
            </div>
            <div class="col-md-1">
                <button class="btn btn-dark w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Department custody stock balances</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col">Branch</th>
                    <th scope="col">Department</th>
                    <th scope="col">Product</th>
                    <th scope="col">SKU</th>
                    <th scope="col">Variant</th>
                    <th scope="col" class="text-end">Custody Qty</th>
                    <th scope="col" class="text-end">Avg Cost</th>
                    <th scope="col" class="text-end">Stock Value</th>
                </tr>
            </thead>
            <tbody>
            @forelse($balances as $balance)
                <tr>
                    <td>{{ $balance->branch?->name }}</td>
                    <td><span class="fw-semibold">{{ $balance->department?->name }}</span></td>
                    <td>{{ $balance->product?->name }}</td>
                    <td><code>{{ $balance->product?->sku }}</code></td>
                    <td>{{ $balance->variant?->name ?? 'Default' }}</td>
                    <td class="text-end fw-semibold">{{ number_format($balance->quantity_on_hand, 3) }} {{ $balance->product?->unit?->code }}</td>
                    <td class="text-end">{{ number_format($balance->average_cost, 4) }}</td>
                    <td class="text-end">{{ number_format((float) $balance->quantity_on_hand * (float) $balance->average_cost, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        No department custody stock yet.<br>
                        <span class="small">Use <strong>Issue to Department</strong> to hand branch stock into a department's custody.</span>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $balances->links() }}</div>
    </div>
</div>
@endsection
