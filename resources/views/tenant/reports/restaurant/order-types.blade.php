@extends('layouts.app')
@section('title', 'Order Type Report')
@section('content')
<div class="page-header">
    <div class="page-title">
        <h4>Sales by Order Type</h4>
        <h6>Breakdown by quick sale, takeaway, dine-in, delivery</h6>
    </div>
</div>

@include('tenant.reports.partials.filters', ['showTerminal' => false, 'showOrderType' => false, 'showCsvExport' => false])

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <caption class="visually-hidden">Order type sales breakdown</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col">Type</th>
                    <th scope="col" class="text-end">Orders</th>
                    <th scope="col" class="text-end">Gross</th>
                    <th scope="col" class="text-end">Discount</th>
                    <th scope="col" class="text-end">Tax</th>
                    <th scope="col" class="text-end">Svc Charge</th>
                    <th scope="col" class="text-end">Tips</th>
                    <th scope="col" class="text-end">Net</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $ot)
                <tr>
                    <td><span class="badge bg-secondary">{{ ucfirst(str_replace('_', ' ', $ot->order_type)) }}</span></td>
                    <td class="text-end">{{ number_format($ot->order_count) }}</td>
                    <td class="text-end">{{ number_format($ot->gross_sales, 2) }}</td>
                    <td class="text-end text-danger">{{ number_format($ot->total_discount, 2) }}</td>
                    <td class="text-end">{{ number_format($ot->total_tax, 2) }}</td>
                    <td class="text-end">{{ number_format($ot->total_service_charge, 2) }}</td>
                    <td class="text-end">{{ number_format($ot->total_tips, 2) }}</td>
                    <td class="text-end fw-semibold">{{ number_format($ot->net_sales, 2) }}</td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No sales.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
