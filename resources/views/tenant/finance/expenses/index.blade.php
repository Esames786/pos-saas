@extends('layouts.app')

@section('title', 'Expenses')

@php
    $statusBadge = ['draft' => 'bg-secondary', 'posted' => 'bg-success', 'void' => 'bg-danger'];
@endphp

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Expenses</h4>
                <h6>Record and pay business expenses</h6>
            </div>
            @can('tenant.finance.expenses.create')
            <div class="page-btn">
                <a href="{{ url('/finance/expenses/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>Add Expense
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
                <form method="GET" action="{{ url('/finance/expenses') }}" class="row g-2 align-items-end">
                    <div class="col-sm-3">
                        <label class="form-label mb-1">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            @foreach($statuses as $s)
                                <option value="{{ $s }}" {{ ($filters['status'] ?? '') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label mb-1">Branch</label>
                        <select name="branch_id" class="form-select">
                            <option value="">All branches</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ (string) ($filters['branch_id'] ?? '') === (string) $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label mb-1">Search</label>
                        <input type="text" name="q" class="form-control" placeholder="Voucher no or payee" value="{{ $filters['q'] ?? '' }}">
                    </div>
                    <div class="col-sm-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">Expense vouchers</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">Voucher #</th>
                                <th scope="col">Date</th>
                                <th scope="col">Branch</th>
                                <th scope="col">Payee</th>
                                <th scope="col">Cash/Bank</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Total</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($vouchers as $v)
                            <tr>
                                <td><a href="{{ url('/finance/expenses/' . $v->id) }}" class="fw-semibold">{{ $v->voucher_no }}</a></td>
                                <td>{{ optional($v->expense_date)->format('Y-m-d') }}</td>
                                <td>{{ $v->branch->name ?? '—' }}</td>
                                <td>{{ $v->payee_name ?: '—' }}</td>
                                <td class="text-muted">{{ $v->cashBankAccount->name ?? '—' }}</td>
                                <td><span class="badge {{ $statusBadge[$v->status] ?? 'bg-secondary' }}">{{ ucfirst($v->status) }}</span></td>
                                <td class="text-end">{{ number_format((float) $v->total_amount, 2) }}</td>
                                <td>
                                    <a href="{{ url('/finance/expenses/' . $v->id) }}" class="btn btn-sm btn-outline-secondary me-1" title="View"><i class="ti ti-eye"></i></a>
                                    @can('tenant.finance.expenses.edit')
                                        @if($v->isDraft())
                                        <a href="{{ url('/finance/expenses/' . $v->id . '/edit') }}" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="ti ti-pencil"></i></a>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No expenses found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
@endsection
