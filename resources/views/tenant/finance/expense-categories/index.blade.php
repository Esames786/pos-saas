@extends('layouts.app')

@section('title', 'Expense Categories')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Expense Categories</h4>
                <h6>Group expenses and link them to the chart of accounts</h6>
            </div>
            @can('tenant.finance.expense-categories.create')
            <div class="page-btn">
                <a href="{{ url('/finance/expense-categories/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>Add Category
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
                <form method="GET" action="{{ url('/finance/expense-categories') }}" class="row g-2 align-items-end">
                    <div class="col-sm-3">
                        <label class="form-label mb-1">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <option value="active" {{ ($filters['status'] ?? '') === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                    </div>
                    <div class="col-sm-5">
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
                        <caption class="visually-hidden">Expense categories</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">Code</th>
                                <th scope="col">Name</th>
                                <th scope="col">Linked CoA</th>
                                <th scope="col">System</th>
                                <th scope="col">Active</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($categories as $category)
                            <tr>
                                <td><span class="fw-semibold">{{ $category->code }}</span></td>
                                <td>{{ $category->name }}</td>
                                <td class="text-muted">{{ $category->account ? $category->account->code . ' — ' . $category->account->name : '—' }}</td>
                                <td>@if($category->is_system)<span class="badge bg-info text-dark">System</span>@else<span class="text-muted">Custom</span>@endif</td>
                                <td><span class="badge bg-{{ $category->is_active ? 'success' : 'secondary' }}">{{ $category->is_active ? 'Active' : 'Inactive' }}</span></td>
                                <td>
                                    @can('tenant.finance.expense-categories.edit')
                                    <a href="{{ url('/finance/expense-categories/' . $category->id . '/edit') }}" class="btn btn-sm btn-outline-secondary me-1"><i class="ti ti-pencil"></i></a>
                                    @endcan
                                    @can('tenant.finance.expense-categories.destroy')
                                        @if(!$category->is_system && $category->is_active)
                                        <form method="POST" action="{{ url('/finance/expense-categories/' . $category->id) }}" class="d-inline" onsubmit="return confirm('Deactivate this category?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate"><i class="ti ti-archive"></i></button>
                                        </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No expense categories found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
@endsection
