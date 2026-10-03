@extends('layouts.app')

@section('title', 'Goods Receipts')

@section('content')

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Goods Receipts</h1>
        <p class="fw-medium">Post received goods. GRN updates the inventory stock ledger immediately.</p>
    </div>
    @can('tenant.goods-receipts.create')
        <a href="{{ url('/goods-receipts/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>Create GRN
        </a>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/goods-receipts') }}" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="grn-supplier" class="form-label">Supplier</label>
                <select id="grn-supplier" name="supplier_id" class="form-select">
                    <option value="">All Suppliers</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(request('supplier_id') == $supplier->id)>
                            {{ $supplier->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-dark" type="submit">Filter</button>
                <a href="{{ url('/goods-receipts') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Goods receipt list</caption>
            <thead>
            <tr>
                <th scope="col">GRN No</th>
                <th scope="col">Supplier</th>
                <th scope="col">Branch</th>
                <th scope="col">PO</th>
                <th scope="col">Receipt Date</th>
                <th scope="col">Bill</th>
                <th scope="col">Status</th>
                <th scope="col" class="text-end">Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse($receipts as $receipt)
                <tr>
                    <td><code>{{ $receipt->grn_no }}</code></td>
                    <td>{{ $receipt->supplier?->name }}</td>
                    <td>{{ $receipt->branch?->name }}</td>
                    <td>{{ $receipt->purchaseOrder?->po_no ?? '—' }}</td>
                    <td>{{ $receipt->receipt_date?->format('Y-m-d') }}</td>
                    <td>
                        @if($receipt->bill)
                            <span class="badge bg-success">Created</span>
                        @else
                            <span class="badge bg-warning text-dark">Pending</span>
                        @endif
                    </td>
                    <td>
                        {{-- GRN-VOID-1: a voided receipt must not read like a live one. --}}
                        @if($receipt->status === 'voided')
                            <span class="badge bg-danger">Voided</span>
                        @else
                            <span class="badge bg-success">{{ ucfirst($receipt->status) }}</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1">
                            @can('tenant.goods-receipts.show')
                                <a href="{{ url('/goods-receipts/' . $receipt->id) }}" class="btn btn-sm btn-light">View</a>
                            @endcan
                            {{-- GRN-VOID-LIST-1: same three conditions as the receipt's own screen —
                                 not billed, still posted, and the user may void. Offering it here saves
                                 opening each receipt to find out it cannot be voided anyway. --}}
                            @if(!$receipt->bill && $receipt->status === 'posted')
                                @can('tenant.goods-receipts.void')
                                    <form method="POST" action="{{ url('/goods-receipts/' . $receipt->id . '/void') }}"
                                          onsubmit="return confirm('Void {{ $receipt->grn_no }}?

The stock it brought in will be taken back out. The receipt stays on record as voided. This cannot be undone.');">
                                        @csrf
                                        <input type="hidden" name="void_reason" value="Voided from GRN list">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Void</button>
                                    </form>
                                @endcan
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">No goods receipts found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $receipts->links() }}</div>
    </div>
</div>
@endsection
