@extends('layouts.app')

@section('title', 'Payment Methods')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">Manual Payment Methods</h1>
        <p class="text-muted mb-0">Where tenants send subscription payments. Only <strong>active</strong> methods with account details are shown to tenants.</p>
    </div>
    <a href="{{ route('central.payment-methods.create') }}" class="btn btn-primary">Add Method</a>
</div>

@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Account Title</th>
                        <th>Account / Number</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($methods as $method)
                        <tr>
                            <td>{{ $method->sort_order }}</td>
                            <td><code>{{ $method->code }}</code></td>
                            <td>{{ $method->display_name }}</td>
                            <td>{{ $method->account_title ?: '—' }}</td>
                            <td>{{ $method->account_number ?: '—' }}</td>
                            <td>
                                @if($method->is_active)
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-secondary">Inactive</span>
                                @endif
                                @if($method->is_active && (! $method->account_title || ! $method->account_number))
                                    <span class="badge bg-warning text-dark" title="Active but missing account details">Incomplete</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('central.payment-methods.edit', $method) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                                <form method="POST" action="{{ route('central.payment-methods.toggle', $method) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-{{ $method->is_active ? 'warning' : 'success' }}">{{ $method->is_active ? 'Deactivate' : 'Activate' }}</button>
                                </form>
                                <form method="POST" action="{{ route('central.payment-methods.destroy', $method) }}" class="d-inline" onsubmit="return confirm('Delete this payment method?');">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No payment methods yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
