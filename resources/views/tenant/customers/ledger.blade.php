@extends('layouts.app')

@section('title', 'Customer Ledger — ' . $customer->name)

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Customer Ledger</h4>
                <h6>{{ $customer->name }} @if($customer->code)<span class="text-muted">({{ $customer->code }})</span>@endif</h6>
            </div>
            <div class="page-btn d-flex gap-2">
                <a href="{{ url('/customers/' . $customer->id) }}" class="btn btn-secondary">Back</a>
                @can('tenant.finance.customer-payments.create')
                <a href="{{ url('/finance/customer-payments/create?customer_id=' . $customer->id) }}" class="btn btn-primary"><i class="ti ti-cash me-1"></i>Record Payment</a>
                @endcan
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-3"><div class="card"><div class="card-body"><small class="text-muted d-block">Opening Balance</small><strong>{{ number_format((float) $customer->opening_balance, 2) }}</strong></div></div></div>
            <div class="col-md-3"><div class="card"><div class="card-body"><small class="text-muted d-block">Current Balance</small><strong class="text-warning">{{ number_format((float) $customer->current_balance, 2) }}</strong></div></div></div>
            <div class="col-md-3"><div class="card"><div class="card-body"><small class="text-muted d-block">Credit Limit</small><strong>{{ $customer->credit_limit !== null ? number_format((float) $customer->credit_limit, 2) : '—' }}</strong></div></div></div>
            <div class="col-md-3"><div class="card"><div class="card-body"><small class="text-muted d-block">Credit Days</small><strong>{{ $customer->credit_days ?? '—' }}</strong></div></div></div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <caption class="visually-hidden">Customer ledger entries</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Type</th>
                                <th scope="col">Reference</th>
                                <th scope="col">Branch</th>
                                <th scope="col" class="text-end">Debit</th>
                                <th scope="col" class="text-end">Credit</th>
                                <th scope="col" class="text-end">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($ledgers as $l)
                            <tr>
                                <td>{{ optional($l->entry_date)->format('Y-m-d') }}</td>
                                <td>{{ str_replace('_', ' ', ucfirst($l->entry_type)) }}</td>
                                <td class="text-muted">{{ $l->reference_no ?: '—' }}</td>
                                <td class="text-muted">{{ $l->branch->name ?? '—' }}</td>
                                <td class="text-end">{{ $l->direction === 'debit' ? number_format((float) $l->amount, 2) : '' }}</td>
                                <td class="text-end">{{ $l->direction === 'credit' ? number_format((float) $l->amount, 2) : '' }}</td>
                                <td class="text-end fw-semibold">{{ number_format((float) $l->balance_after, 2) }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No ledger entries.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mt-3">{{ $ledgers->links() }}</div>
@endsection
