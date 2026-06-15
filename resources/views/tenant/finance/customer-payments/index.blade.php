@extends('layouts.app')

@section('title', 'Customer Payments')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Customer Payments</h4>
                <h6>Payments received from customers against receivables</h6>
            </div>
            @can('tenant.finance.customer-payments.create')
            <div class="page-btn">
                <a href="{{ url('/finance/customer-payments/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>Record Payment
                </a>
            </div>
            @endcan
        </div>

        @if(session('status'))
            <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger alert-dismissible fade show">{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif

        <div class="card">
            <div class="card-body">
                <form method="GET" action="{{ url('/finance/customer-payments') }}" class="row g-2 align-items-end">
                    <div class="col-sm-3">
                        <label class="form-label mb-1">Customer</label>
                        <select name="customer_id" class="form-select">
                            <option value="">All customers</option>
                            @foreach($customers as $c)
                                <option value="{{ $c->id }}" {{ (string)($filters['customer_id'] ?? '') === (string)$c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label mb-1">Branch</label>
                        <select name="branch_id" class="form-select">
                            <option value="">All</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ (string)($filters['branch_id'] ?? '') === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label mb-1">From</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label mb-1">To</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label mb-1">Search</label>
                        <input type="text" name="q" class="form-control" placeholder="No / ref" value="{{ $filters['q'] ?? '' }}">
                    </div>
                    <div class="col-sm-1">
                        <button type="submit" class="btn btn-primary w-100">Go</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">Customer payments</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">Payment #</th>
                                <th scope="col">Date</th>
                                <th scope="col">Customer</th>
                                <th scope="col">Branch</th>
                                <th scope="col">Cash/Bank</th>
                                <th scope="col">Method</th>
                                <th scope="col" class="text-end">Amount</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($payments as $p)
                            <tr>
                                <td><a href="{{ url('/finance/customer-payments/' . $p->id) }}" class="fw-semibold">{{ $p->payment_no }}</a></td>
                                <td>{{ optional($p->payment_date)->format('Y-m-d') }}</td>
                                <td>{{ $p->customer->name ?? '—' }}</td>
                                <td class="text-muted">{{ $p->branch->name ?? '—' }}</td>
                                <td class="text-muted">{{ $p->cashBankAccount->name ?? '—' }}</td>
                                <td>{{ $p->payment_method ?: '—' }}</td>
                                <td class="text-end fw-semibold">{{ number_format((float) $p->amount, 2) }}</td>
                                <td><a href="{{ url('/finance/customer-payments/' . $p->id) }}" class="btn btn-sm btn-outline-secondary"><i class="ti ti-eye"></i></a></td>
                            </tr>
                            @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No customer payments found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
@endsection
