@extends('layouts.app')

@section('title', 'Combos')

@section('content')
    <div class="page-header">
        <div class="page-title">
            <h4>Combos</h4>
            <h6>Manage meal bundles that expand into real sale lines</h6>
        </div>
        @can('tenant.combos.create')
            <div class="page-btn">
                <a href="{{ url('/combos/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i> New Combo
                </a>
            </div>
        @endcan
    </div>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="card-body table-responsive">
            <table class="table">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Code</th>
                    <th>Branch</th>
                    <th>Price</th>
                    <th>Components</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($combos as $combo)
                    <tr>
                        <td>{{ $combo->name }}</td>
                        <td><code>{{ $combo->code ?: '-' }}</code></td>
                        <td>{{ $combo->branch?->name ?? 'All branches' }}</td>
                        <td>{{ number_format((float) $combo->price, 2) }}</td>
                        <td>
                            @foreach($combo->components as $component)
                                <div class="small text-muted">
                                    {{ number_format((float) $component->quantity, 3) }} x {{ $component->product?->name }}
                                </div>
                            @endforeach
                        </td>
                        <td>
                            <span class="badge {{ $combo->status === 'active' ? 'bg-success' : 'bg-secondary' }}">
                                {{ ucfirst($combo->status) }}
                            </span>
                        </td>
                        <td class="text-end">
                            @can('tenant.combos.edit')
                                <a href="{{ url('/combos/' . $combo->id . '/edit') }}" class="btn btn-sm btn-outline-secondary me-1">
                                    Edit
                                </a>
                            @endcan
                            @can('tenant.combos.destroy')
                                <form method="POST" action="{{ url('/combos/' . $combo->id) }}" class="d-inline" onsubmit="return confirm('Delete this combo?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No combos found.</td></tr>
                @endforelse
                </tbody>
            </table>

            <div class="mt-3">{{ $combos->links() }}</div>
        </div>
    </div>
@endsection
