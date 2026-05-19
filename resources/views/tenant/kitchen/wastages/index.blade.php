@extends('layouts.app')

@section('title', 'Kitchen Wastages')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">Kitchen Wastages</h1>
    @can('tenant.kitchen.wastages.create')
        <a href="{{ url('/kitchen/wastages/create') }}" class="btn btn-primary">Record Wastage</a>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<div class="card">
    <div class="card-body pb-0">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-3">
                <select name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <select name="product_id" class="form-select">
                    <option value="">All Products</option>
                    @foreach($products as $p)
                        <option value="{{ $p->id }}" @selected(request('product_id') == $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-light">Filter</button>
                <a href="{{ url('/kitchen/wastages') }}" class="btn btn-light">Clear</a>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Wastage No</th>
                    <th>Branch</th>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Reason</th>
                    <th>Date</th>
                    <th>Recorded By</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($wastages as $w)
                <tr>
                    <td><a href="{{ url('/kitchen/wastages/' . $w->id) }}">{{ $w->wastage_no }}</a></td>
                    <td>{{ $w->branch?->name }}</td>
                    <td>
                        {{ $w->product?->name }}
                        @if($w->variant) <small class="text-muted">({{ $w->variant->name }})</small> @endif
                    </td>
                    <td>{{ $w->quantity }} {{ $w->unit?->code }}</td>
                    <td>{{ $w->reason ?? '—' }}</td>
                    <td>{{ $w->wastage_date?->format('d M Y') }}</td>
                    <td>{{ $w->recordedBy?->name }}</td>
                    <td class="text-end">
                        <a href="{{ url('/kitchen/wastages/' . $w->id) }}" class="btn btn-sm btn-light">View</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No wastages recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
{{ $wastages->links() }}
@endsection
