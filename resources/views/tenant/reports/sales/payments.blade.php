@extends('layouts.app')

@section('title', 'Payment Method Report')

@section('content')
        <div class="page-header">
            <div class="page-title"><h4>Payment Methods</h4><h6>Sales collected by payment method</h6></div>
            <div class="page-btn">
                <a href="{{ url('/reports/sales/summary') }}" class="btn btn-outline-secondary btn-sm">Summary</a>
            </div>
        </div>

        @include('tenant.reports.partials.filters', ['showOrderType' => false, 'showCsvExport' => false, 'branchMulti' => true])

        <div class="row g-3 mb-3">
            @foreach($rows as $row)
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold">{{ $row->method_name }}</span>
                            <span class="badge bg-secondary small">{{ $row->method_type }}</span>
                        </div>
                        <div class="fs-5 fw-bold">{{ number_format($row->total_amount, 2) }}</div>
                        <div class="text-muted small">{{ number_format($row->transaction_count) }} transaction{{ $row->transaction_count != 1 ? 's' : '' }}</div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <caption class="visually-hidden">Payment method totals</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Method</th>
                            <th scope="col">Type</th>
                            <th scope="col" class="text-end">Transactions</th>
                            <th scope="col" class="text-end">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                        <tr>
                            <td>{{ $row->method_name }}</td>
                            <td class="text-muted small">{{ ucfirst(str_replace('_', ' ', $row->method_type)) }}</td>
                            <td class="text-end">{{ number_format($row->transaction_count) }}</td>
                            <td class="text-end fw-semibold">{{ number_format($row->total_amount, 2) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">No payments in selected range.</td></tr>
                        @endforelse
                    </tbody>
                    @if($rows->isNotEmpty())
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="2">Total</td>
                            <td class="text-end">{{ number_format($rows->sum('transaction_count')) }}</td>
                            <td class="text-end">{{ number_format($rows->sum('total_amount'), 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>
@endsection
