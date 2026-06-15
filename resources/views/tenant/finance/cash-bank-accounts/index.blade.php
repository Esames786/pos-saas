@extends('layouts.app')

@section('title', 'Cash & Bank Accounts')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Cash &amp; Bank Accounts</h4>
                <h6>Operational money accounts (cash drawers, bank, wallets)</h6>
            </div>
            @can('tenant.finance.cash-bank-accounts.create')
            <div class="page-btn">
                <a href="{{ url('/finance/cash-bank-accounts/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>Add Account
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

        <div class="alert alert-info">
            <i class="ti ti-info-circle me-1"></i>Cash &amp; Bank Accounts are operational money accounts. General Ledger journal posting will be added later.
        </div>

        <div class="card">
            <div class="card-body">
                <form method="GET" action="{{ url('/finance/cash-bank-accounts') }}" class="row g-2 align-items-end">
                    <div class="col-sm-3">
                        <label class="form-label mb-1">Type</label>
                        <select name="type" class="form-select">
                            <option value="">All types</option>
                            @foreach($types as $t)
                                <option value="{{ $t }}" {{ ($filters['type'] ?? '') === $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label mb-1">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <option value="active" {{ ($filters['status'] ?? '') === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label mb-1">Search</label>
                        <input type="text" name="q" class="form-control" placeholder="Code or name" value="{{ $filters['q'] ?? '' }}">
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
                        <caption class="visually-hidden">Cash and bank accounts</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">Code</th>
                                <th scope="col">Name</th>
                                <th scope="col">Type</th>
                                <th scope="col">Linked CoA</th>
                                <th scope="col">Branch</th>
                                <th scope="col" class="text-end">Current Balance</th>
                                <th scope="col">Default</th>
                                <th scope="col">System</th>
                                <th scope="col">Active</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($accounts as $account)
                            <tr>
                                <td><span class="fw-semibold">{{ $account->code }}</span></td>
                                <td>{{ $account->name }}</td>
                                <td><span class="badge bg-secondary">{{ ucfirst($account->account_type) }}</span></td>
                                <td class="text-muted">{{ $account->account ? $account->account->code . ' — ' . $account->account->name : '—' }}</td>
                                <td class="text-muted">{{ $account->branch->name ?? '—' }}</td>
                                <td class="text-end">{{ number_format((float) $account->current_balance, 2) }}</td>
                                <td>@if($account->is_default)<span class="badge bg-primary">Default</span>@else<span class="text-muted">—</span>@endif</td>
                                <td>@if($account->is_system)<span class="badge bg-info text-dark">System</span>@else<span class="text-muted">Custom</span>@endif</td>
                                <td><span class="badge bg-{{ $account->is_active ? 'success' : 'secondary' }}">{{ $account->is_active ? 'Active' : 'Inactive' }}</span></td>
                                <td>
                                    @can('tenant.finance.cash-bank-accounts.edit')
                                    <a href="{{ url('/finance/cash-bank-accounts/' . $account->id . '/edit') }}" class="btn btn-sm btn-outline-secondary me-1">
                                        <i class="ti ti-pencil"></i>
                                    </a>
                                    @endcan
                                    @can('tenant.finance.cash-bank-accounts.destroy')
                                        @if(!$account->is_system && $account->is_active)
                                        <form method="POST" action="{{ url('/finance/cash-bank-accounts/' . $account->id) }}" class="d-inline" onsubmit="return confirm('Deactivate this account?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate"><i class="ti ti-archive"></i></button>
                                        </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="10" class="text-center text-muted py-4">No cash/bank accounts found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
@endsection
