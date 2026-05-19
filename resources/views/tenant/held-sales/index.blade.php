@extends('layouts.app')
@section('title', 'Held Sales')
@section('content')
<div class="content-wrapper">
    <div class="content">
        <div class="container-fluid">
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col"><h3 class="page-title">Held Sales</h3></div>
                    @can('tenant.held-sales.create')
                    <div class="col-auto">
                        <a href="{{ url('/held-sales/create') }}" class="btn btn-primary">
                            <i class="ti ti-plus me-1"></i> New Held Sale
                        </a>
                    </div>
                    @endcan
                </div>
            </div>

            @if(session('status'))
                <div class="alert alert-success alert-dismissible">
                    {{ session('status') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Sale No</th>
                                    <th>Branch</th>
                                    <th>Type</th>
                                    <th>Customer</th>
                                    <th>Table</th>
                                    <th>Total</th>
                                    <th>Held At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($heldSales as $sale)
                                <tr>
                                    <td class="fw-medium">{{ $sale->sale_no }}</td>
                                    <td>{{ $sale->branch->name ?? '-' }}</td>
                                    <td>{{ ucfirst(str_replace('_', ' ', $sale->order_type)) }}</td>
                                    <td>{{ $sale->customer_name ?: ($sale->customer->name ?? 'Walk-in') }}</td>
                                    <td>{{ $sale->restaurantTable->table_no ?? '-' }}</td>
                                    <td>{{ number_format($sale->grand_total, 2) }}</td>
                                    <td>{{ $sale->updated_at->format('d M H:i') }}</td>
                                    <td>
                                        @can('tenant.pos.index')
                                        <a href="{{ url('/pos?held_sale_id=' . $sale->id) }}"
                                           class="btn btn-sm btn-success">Recall</a>
                                        @endcan
                                        @can('tenant.sales-orders.split-bill')
                                        <a href="{{ url('/sales-orders/' . $sale->id . '/split-bill') }}"
                                           class="btn btn-sm btn-warning">Split</a>
                                        @endcan
                                        @if($sale->restaurant_table_session_id)
                                            @can('tenant.restaurant.table-sessions.bill-preview')
                                            <a href="{{ url('/restaurant/table-sessions/' . $sale->restaurant_table_session_id . '/bill-preview') }}"
                                               class="btn btn-sm btn-dark">Bill</a>
                                            @endcan
                                        @endif
                                        @can('tenant.held-sales.cancel')
                                        <form method="POST" action="{{ url('/held-sales/' . $sale->id . '/cancel') }}"
                                              class="d-inline"
                                              onsubmit="return confirm('Cancel held sale {{ addslashes($sale->sale_no) }}?')">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-danger">Cancel</button>
                                        </form>
                                        @endcan
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="8" class="text-center py-4 text-muted">No held sales.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $heldSales->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
