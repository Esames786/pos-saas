@extends('layouts.app')

@section('title', 'Suppliers')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Suppliers</h1>
        <p class="fw-medium">Manage supplier profiles, balances, and ledger history.</p>
    </div>
    @can('tenant.suppliers.create')
        <a href="{{ url('/suppliers/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Supplier
        </a>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Supplier list</caption>
            <thead>
            <tr>
                <th scope="col">Code</th>
                <th scope="col">Supplier</th>
                <th scope="col">Contact</th>
                <th scope="col">Balance</th>
                <th scope="col">Status</th>
                <th scope="col" class="text-end">Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse($suppliers as $supplier)
                <tr>
                    <td><code>{{ $supplier->code }}</code></td>
                    <td>
                        <strong>{{ $supplier->name }}</strong>
                        @if($supplier->tax_number)
                            <small class="d-block text-muted">{{ $supplier->tax_number }}</small>
                        @endif
                    </td>
                    <td>
                        {{ $supplier->phone ?: '—' }}
                        @if($supplier->email)
                            <small class="d-block text-muted">{{ $supplier->email }}</small>
                        @endif
                    </td>
                    <td>{{ number_format($supplier->current_balance, 2) }}</td>
                    <td>
                        <span class="badge bg-{{ $supplier->status === 'active' ? 'success' : 'secondary' }}">
                            {{ ucfirst($supplier->status) }}
                        </span>
                    </td>
                    <td class="text-end">
                        @can('tenant.suppliers.show')
                            <a href="{{ url('/suppliers/' . $supplier->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                        @can('tenant.suppliers.ledger')
                            <a href="{{ url('/suppliers/' . $supplier->id . '/ledger') }}" class="btn btn-sm btn-dark">Ledger</a>
                        @endcan
                        @can('tenant.suppliers.edit')
                            <a href="{{ url('/suppliers/' . $supplier->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">No suppliers found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $suppliers->links() }}</div>
    </div>
</div>
@endsection
