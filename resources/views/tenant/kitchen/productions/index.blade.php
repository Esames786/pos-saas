@extends('layouts.app')

@section('title', 'Kitchen Productions')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">Kitchen Productions</h1>
    @can('tenant.kitchen.productions.create')
        <a href="{{ url('/kitchen/productions/create') }}" class="btn btn-primary">New Production</a>
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
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    @foreach(['planned','in_progress','completed','cancelled'] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucwords(str_replace('_',' ',$s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-light">Filter</button>
                <a href="{{ url('/kitchen/productions') }}" class="btn btn-light">Clear</a>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Production No</th>
                    <th>Branch</th>
                    <th>Recipe</th>
                    <th>Product</th>
                    <th>Qty</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($productions as $prod)
                <tr>
                    <td><a href="{{ url('/kitchen/productions/' . $prod->id) }}">{{ $prod->production_no }}</a></td>
                    <td>{{ $prod->branch?->name }}</td>
                    <td>{{ $prod->recipe?->name }}</td>
                    <td>{{ $prod->recipe?->product?->name }}</td>
                    <td>{{ $prod->quantity_produced }}</td>
                    <td>{{ $prod->production_date?->format('d M Y') }}</td>
                    <td>
                        @php $colors = ['planned'=>'secondary','in_progress'=>'warning','completed'=>'success','cancelled'=>'danger']; @endphp
                        <span class="badge bg-{{ $colors[$prod->status] ?? 'secondary' }}">
                            {{ ucwords(str_replace('_',' ',$prod->status)) }}
                        </span>
                    </td>
                    <td class="text-end">
                        <a href="{{ url('/kitchen/productions/' . $prod->id) }}" class="btn btn-sm btn-light">View</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No productions found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
{{ $productions->links() }}
@endsection
