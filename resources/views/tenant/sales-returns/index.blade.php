@extends('layouts.app')

@section('title', 'Sales Returns')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Sales Returns</h1>
        <p class="fw-medium">Returns posted against paid sales.</p>
    </div>
    @can('tenant.sales-returns.create')
        <a href="{{ url('/sales-returns/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>New Return
        </a>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Sales return list</caption>
            <thead>
            <tr>
                <th scope="col">Return No</th>
                <th scope="col">Sale No</th>
                <th scope="col">Branch</th>
                <th scope="col">Return Date</th>
                <th scope="col">Grand Total</th>
                <th scope="col">Refund Method</th>
                <th scope="col">Status</th>
                <th scope="col" class="text-end">Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse($returns as $return)
                <tr>
                    <td><code>{{ $return->return_no }}</code></td>
                    <td>
                        @can('tenant.sales-orders.show')
                            <a href="{{ url('/sales-orders/' . $return->order?->id) }}">
                                <code>{{ $return->order?->sale_no }}</code>
                            </a>
                        @else
                            <code>{{ $return->order?->sale_no }}</code>
                        @endcan
                    </td>
                    <td>{{ $return->branch?->name }}</td>
                    <td>{{ $return->return_date?->format('Y-m-d H:i') }}</td>
                    <td>{{ number_format($return->grand_total, 2) }}</td>
                    <td>{{ $return->refund_method ? str_replace('_', ' ', ucfirst($return->refund_method)) : '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $return->status === 'posted' ? 'success' : 'secondary' }}">
                            {{ ucfirst($return->status) }}
                        </span>
                    </td>
                    <td class="text-end">
                        @can('tenant.sales-returns.show')
                            <a href="{{ url('/sales-returns/' . $return->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">No sales returns found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $returns->links() }}</div>
    </div>
</div>
@endsection
