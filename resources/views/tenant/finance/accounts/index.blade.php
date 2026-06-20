@extends('layouts.app')

@section('title', 'Chart of Accounts — Level Wise View')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Chart of Accounts</h4>
                <h6>{{ $hasFilter ? 'Filtered results' : 'Level Wise View' }}</h6>
            </div>
            @can('tenant.finance.accounts.create')
            <div class="page-btn">
                <a href="{{ url('/finance/accounts/create') }}" class="btn btn-added">
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

        <div class="card">
            <div class="card-body">
                <form method="GET" action="{{ url('/finance/accounts') }}" class="row g-2 align-items-end">
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
                    <div class="col-sm-2 d-flex gap-1">
                        <button type="submit" class="btn btn-primary flex-grow-1">Filter</button>
                        @if($hasFilter)
                            <a href="{{ url('/finance/accounts') }}" class="btn btn-outline-secondary" title="Clear filters"><i class="ti ti-x"></i></a>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="card table-list-card">
            <div class="card-body">
                @if(!$hasFilter && count($tree))
                {{-- ── LEVEL-WISE TREE VIEW ─────────────────────────────────── --}}
                <div class="table-responsive">
                    <table class="table table-sm mb-0" id="coa-tree">
                        <caption class="visually-hidden">Chart of accounts — level wise view</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col" style="min-width:110px;">Code</th>
                                <th scope="col">Account Name</th>
                                <th scope="col">Type</th>
                                <th scope="col">Normal Balance</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $typeColors = [
                                    'asset'     => 'primary',
                                    'liability' => 'danger',
                                    'equity'    => 'success',
                                    'income'    => 'info',
                                    'expense'   => 'warning',
                                ];
                            @endphp
                            @foreach($tree as $account)
                            @php $lvl = $account->_level ?? 0; @endphp
                            <tr class="{{ $lvl === 0 ? 'table-light' : '' }}">
                                <td>
                                    <span class="fw-{{ $lvl === 0 ? 'bold' : 'semibold' }}"
                                          style="padding-left:{{ $lvl * 18 }}px;">
                                        @if($lvl === 0)
                                            <i class="ti ti-folder fs-14 me-1 text-muted"></i>
                                        @elseif($lvl === 1)
                                            <i class="ti ti-corner-down-right fs-12 me-1 text-muted"></i>
                                        @else
                                            <i class="ti ti-arrow-right fs-11 me-1 text-muted"></i>
                                        @endif
                                        {{ $account->code }}
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-{{ $lvl === 0 ? 'bold' : ($lvl === 1 ? 'semibold' : 'normal') }}"
                                          style="padding-left:{{ $lvl * 18 }}px;">
                                        {{ $account->name }}
                                    </span>
                                    @if($account->is_system)
                                        <span class="badge bg-light text-muted ms-1" style="font-size:.6rem;">sys</span>
                                    @endif
                                    @if($lvl > 0)
                                        <span class="badge bg-light text-secondary ms-1" style="font-size:.6rem;">L{{ $lvl + 1 }}</span>
                                    @endif
                                </td>
                                <td><span class="badge bg-{{ $typeColors[$account->type] ?? 'secondary' }}">{{ ucfirst($account->type) }}</span></td>
                                <td class="text-muted">{{ ucfirst($account->normal_balance) }}</td>
                                <td><span class="badge bg-{{ $account->is_active ? 'success' : 'secondary' }}">{{ $account->is_active ? 'Active' : 'Inactive' }}</span></td>
                                <td class="text-end">
                                    @can('tenant.finance.accounts.edit')
                                    <a href="{{ url('/finance/accounts/' . $account->id . '/edit') }}" class="btn btn-sm btn-outline-secondary me-1">
                                        <i class="ti ti-pencil"></i>
                                    </a>
                                    @endcan
                                    @can('tenant.finance.accounts.destroy')
                                        @if(!$account->is_system && $account->is_active)
                                        <form method="POST" action="{{ url('/finance/accounts/' . $account->id) }}" class="d-inline" onsubmit="return confirm('Deactivate this account?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate"><i class="ti ti-archive"></i></button>
                                        </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                            @endforeach
                            @if(!count($tree))
                            <tr><td colspan="6" class="text-center text-muted py-4">No accounts found.</td></tr>
                            @endif
                        </tbody>
                    </table>
                </div>
                @else
                {{-- ── FLAT / SEARCH RESULTS VIEW ──────────────────────────── --}}
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">Chart of accounts</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">Code</th>
                                <th scope="col">Name</th>
                                <th scope="col">Type</th>
                                <th scope="col">Normal Balance</th>
                                <th scope="col">Parent</th>
                                <th scope="col">Status</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($accounts as $account)
                            <tr>
                                <td><span class="fw-semibold">{{ $account->code }}</span></td>
                                <td>{{ $account->name }}</td>
                                <td><span class="badge bg-secondary">{{ ucfirst($account->type) }}</span></td>
                                <td>{{ ucfirst($account->normal_balance) }}</td>
                                <td class="text-muted">{{ $account->parent ? $account->parent->code . ' — ' . $account->parent->name : '—' }}</td>
                                <td><span class="badge bg-{{ $account->is_active ? 'success' : 'secondary' }}">{{ $account->is_active ? 'Active' : 'Inactive' }}</span></td>
                                <td>
                                    @can('tenant.finance.accounts.edit')
                                    <a href="{{ url('/finance/accounts/' . $account->id . '/edit') }}" class="btn btn-sm btn-outline-secondary me-1">
                                        <i class="ti ti-pencil"></i>
                                    </a>
                                    @endcan
                                    @can('tenant.finance.accounts.destroy')
                                        @if(!$account->is_system && $account->is_active)
                                        <form method="POST" action="{{ url('/finance/accounts/' . $account->id) }}" class="d-inline" onsubmit="return confirm('Deactivate this account?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate"><i class="ti ti-archive"></i></button>
                                        </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No accounts found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
        @if(!$hasFilter)
        <p class="text-muted small"><i class="ti ti-info-circle me-1"></i>Showing level-wise hierarchy. Use filters above to search across all accounts.</p>
        @endif
@endsection
