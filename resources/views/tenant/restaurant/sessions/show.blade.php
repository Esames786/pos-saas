@extends('layouts.app')
@section('title', 'Table Session')
@section('content')
<div class="content-wrapper">
    <div class="content">
        <div class="container-fluid">
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col">
                        <h3 class="page-title">Session — Table {{ $restaurantTableSession->table->table_no }}</h3>
                    </div>
                    <div class="col-auto">
                        <a href="{{ url('/restaurant/board') }}" class="btn btn-outline-secondary">Table Board</a>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header"><strong>Session Info</strong></div>
                        <div class="card-body">
                            <table class="table table-sm mb-0">
                                <tr><th>Floor</th><td>{{ $restaurantTableSession->table->floor->name ?? '-' }}</td></tr>
                                <tr><th>Table</th><td>{{ $restaurantTableSession->table->table_no }}</td></tr>
                                <tr><th>Waiter</th><td>{{ $restaurantTableSession->waiter->name ?? 'None' }}</td></tr>
                                <tr><th>Guests</th><td>{{ $restaurantTableSession->guest_count }}</td></tr>
                                <tr><th>Status</th><td><span class="badge bg-{{ $restaurantTableSession->status === 'open' ? 'success' : 'secondary' }}">{{ ucfirst($restaurantTableSession->status) }}</span></td></tr>
                                <tr><th>Opened By</th><td>{{ $restaurantTableSession->openedBy->name ?? '-' }}</td></tr>
                                <tr><th>Opened At</th><td>{{ $restaurantTableSession->opened_at?->format('d M Y H:i') }}</td></tr>
                                @if($restaurantTableSession->closed_at)
                                <tr><th>Closed At</th><td>{{ $restaurantTableSession->closed_at->format('d M Y H:i') }}</td></tr>
                                @endif
                                @if($restaurantTableSession->notes)
                                <tr><th>Notes</th><td>{{ $restaurantTableSession->notes }}</td></tr>
                                @endif
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header"><strong>Orders</strong></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Sale No</th>
                                            <th>Status</th>
                                            <th>Items</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($restaurantTableSession->salesOrders as $order)
                                        <tr>
                                            <td>{{ $order->sale_no }}</td>
                                            <td><span class="badge bg-secondary">{{ ucfirst($order->status) }}</span></td>
                                            <td>{{ $order->lines->count() }}</td>
                                            <td>{{ number_format($order->grand_total, 2) }}</td>
                                        </tr>
                                        @empty
                                        <tr><td colspan="4" class="text-center text-muted py-3">No orders yet.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
