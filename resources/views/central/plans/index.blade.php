@extends('layouts.app')

@section('title', 'Plans')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">Plans</h1>
        <p class="text-muted mb-0">Manage SaaS plans and enabled modules.</p>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Price</th>
                        <th>Period</th>
                        <th>Status</th>
                        <th>Enabled Modules</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($plans as $plan)
                        <tr>
                            <td><code>{{ $plan->code }}</code></td>
                            <td>{{ $plan->name }}</td>
                            <td>{{ $plan->currency_code }} {{ number_format((float) $plan->price, 2) }}</td>
                            <td>{{ ucfirst($plan->billing_period) }}</td>
                            <td>
                                <span class="badge {{ $plan->is_active ? 'bg-success' : 'bg-secondary' }}">
                                    {{ $plan->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td>{{ $plan->modules->where('pivot.is_enabled', true)->count() }} / {{ $plan->modules->count() }}</td>
                            <td class="text-end">
                                <a href="{{ url('/plans/' . $plan->id . '/edit') }}" class="btn btn-sm btn-primary">
                                    Edit
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted">No plans found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
