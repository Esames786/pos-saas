@extends('layouts.app')

@section('title', 'Table Bill Preview')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Table Bill Preview</h1>
        <p class="fw-medium">
            {{ $session->session_no }}
            · Table {{ $session->table?->table_no }}
            · {{ $session->waiter?->name ?? 'No waiter' }}
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ url('/pos?table_session_id=' . $session->id . '&mode=dine_in&branch_id=' . $session->branch_id) }}" class="btn btn-primary">
            Continue / Add Order
        </a>
        <a href="{{ url('/restaurant/board?branch_id=' . $session->branch_id) }}" class="btn btn-light">
            Back To Board
        </a>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

@if(session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

@php
    $heldSales = $session->salesOrders->where('status', 'held');
    $paidSales = $session->salesOrders->where('status', 'paid');
    $heldTotal = $heldSales->sum('grand_total');
    $paidTotal = $paidSales->sum('grand_total');
@endphp

<div class="row g-3">
    <div class="col-lg-8">
        {{-- Session info --}}
        <div class="card mb-3">
            <div class="card-body row g-3">
                <div class="col-md-3">
                    <small class="text-muted d-block">Branch</small>
                    <strong>{{ $session->branch?->name }}</strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Floor</small>
                    <strong>{{ $session->table?->floor?->name }}</strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Table</small>
                    <strong>{{ $session->table?->table_no }}</strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Guests</small>
                    <strong>{{ $session->guest_count }}</strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Status</small>
                    <strong>{{ str_replace('_', ' ', ucfirst($session->status)) }}</strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Held Total</small>
                    <strong>{{ number_format($heldTotal, 2) }}</strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Paid Total</small>
                    <strong>{{ number_format($paidTotal, 2) }}</strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Opened</small>
                    <strong>{{ $session->opened_at?->format('d M H:i') }}</strong>
                </div>
            </div>
        </div>

        {{-- Held orders --}}
        <div class="card mb-3">
            <div class="card-body table-responsive">
                <h2 class="h5 mb-3">Held / Unpaid Orders</h2>
                <table class="table table-nowrap align-middle">
                    <thead>
                    <tr>
                        <th>Sale No</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($heldSales as $sale)
                        <tr>
                            <td><code>{{ $sale->sale_no }}</code></td>
                            <td>{{ $sale->lines->count() }}</td>
                            <td>
                                <strong>{{ number_format($sale->grand_total, 2) }}</strong>
                                <div class="small text-muted">
                                    Sub {{ number_format($sale->subtotal, 2) }}
                                    @if((float) $sale->discount_amount > 0)
                                        · Disc -{{ number_format($sale->discount_amount, 2) }}
                                    @endif
                                    @if((float) $sale->tax_amount > 0)
                                        · Tax {{ number_format($sale->tax_amount, 2) }}
                                    @endif
                                    @if((float) $sale->service_charge_amount > 0)
                                        · Svc {{ number_format($sale->service_charge_amount, 2) }}
                                    @endif
                                    @if((float) $sale->tip_amount > 0)
                                        · Tip {{ number_format($sale->tip_amount, 2) }}
                                    @endif
                                </div>
                            </td>
                            <td class="text-end">
                                @can('tenant.pos.index')
                                    <a href="{{ url('/pos?held_sale_id=' . $sale->id . '&table_session_id=' . $session->id . '&mode=dine_in&branch_id=' . $session->branch_id) }}" class="btn btn-sm btn-primary">
                                        Recall / Pay
                                    </a>
                                @endcan
                                @can('tenant.sales-orders.split-bill')
                                    <a href="{{ url('/sales-orders/' . $sale->id . '/split-bill') }}" class="btn btn-sm btn-warning">
                                        Split
                                    </a>
                                @endcan
                            </td>
                        </tr>
                        <tr>
                            <td colspan="4" class="p-0">
                                <table class="table table-sm mb-0 bg-light">
                                    <thead class="table-secondary">
                                    <tr>
                                        <th>Product</th>
                                        <th>Qty</th>
                                        <th>Price</th>
                                        <th>Total</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($sale->lines as $line)
                                        <tr>
                                            <td>{{ $line->product_name }}</td>
                                            <td>{{ number_format($line->quantity, 3) }}</td>
                                            <td>{{ number_format($line->unit_price, 2) }}</td>
                                            <td>{{ number_format($line->line_total, 2) }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No held orders for this table.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Paid orders --}}
        <div class="card">
            <div class="card-body table-responsive">
                <h2 class="h5 mb-3">Paid Orders</h2>
                <table class="table table-nowrap align-middle">
                    <thead>
                    <tr>
                        <th>Sale No</th>
                        <th>Date</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($paidSales as $sale)
                        <tr>
                            <td><code>{{ $sale->sale_no }}</code></td>
                            <td>{{ $sale->sale_date?->format('d M H:i') }}</td>
                            <td>{{ number_format($sale->grand_total, 2) }}</td>
                            <td>{{ number_format($sale->paid_amount, 2) }}</td>
                            <td class="text-end">
                                @can('tenant.sales-orders.show')
                                    <a href="{{ url('/sales-orders/' . $sale->id) }}" class="btn btn-sm btn-light">View</a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No paid orders yet.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Sidebar: Move / Merge / Bill Request --}}
    <div class="col-lg-4">
        @can('tenant.restaurant.table-sessions.move')
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 mb-3">Move Table</h2>
                <form method="POST" action="{{ url('/restaurant/table-sessions/' . $session->id . '/move') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="target_table_id" class="form-label">Target Available Table</label>
                        <select id="target_table_id" name="target_table_id" class="form-select" required>
                            <option value="">Select Table</option>
                            @foreach($session->table?->floor?->tables?->where('status', 'available') ?? [] as $t)
                                @if($t->id !== $session->restaurant_table_id)
                                    <option value="{{ $t->id }}">{{ $t->table_no }}@if($t->name) — {{ $t->name }}@endif</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <button class="btn btn-primary" type="submit" onclick="return confirm('Move this table session?')">Move Table</button>
                </form>
            </div>
        </div>
        @endcan

        @can('tenant.restaurant.table-sessions.merge')
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 mb-3">Merge Into Another Table</h2>
                <form method="POST" action="{{ url('/restaurant/table-sessions/' . $session->id . '/merge') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="target_session_id" class="form-label">Target Session</label>
                        <select id="target_session_id" name="target_session_id" class="form-select" required>
                            <option value="">Select Session</option>
                            @foreach(\App\Models\Tenant\RestaurantTableSession::with('table')->where('branch_id', $session->branch_id)->whereIn('status', ['open', 'bill_requested'])->where('id', '!=', $session->id)->get() as $ts)
                                <option value="{{ $ts->id }}">{{ $ts->session_no }} — Table {{ $ts->table?->table_no }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn btn-warning" type="submit" onclick="return confirm('Merge this table into the selected session? This cannot be undone.')">Merge Table</button>
                </form>
            </div>
        </div>
        @endcan

        @can('tenant.restaurant.table-sessions.bill-requested')
            @if($session->status === 'open')
            <div class="card mb-3">
                <div class="card-body">
                    <h2 class="h5 mb-3">Bill Request</h2>
                    <form method="POST" action="{{ url('/restaurant/table-sessions/' . $session->id . '/bill-requested') }}">
                        @csrf
                        <button class="btn btn-info" type="submit">Mark Bill Requested</button>
                    </form>
                </div>
            </div>
            @endif
        @endcan
    </div>
</div>
@endsection
