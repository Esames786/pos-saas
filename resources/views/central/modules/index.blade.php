@extends('layouts.app')

@section('title', 'Modules')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">Modules</h1>
        <p class="text-muted mb-0">Commercial module definitions used by plan enforcement.</p>
    </div>
</div>

@foreach($modules as $category => $items)
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">{{ $category ?: 'Uncategorized' }}</h5>
        </div>

        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Key</th>
                            <th>Name</th>
                            <th>Route Keys</th>
                            <th>Core</th>
                            <th>Status</th>
                            <th>Sort</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $module)
                            <tr>
                                <td><code>{{ $module->key }}</code></td>
                                <td>{{ $module->name }}</td>
                                <td class="small">
                                    @foreach(($module->route_module_keys ?? []) as $key)
                                        <span class="badge bg-light text-dark border mb-1">{{ $key }}</span>
                                    @endforeach
                                </td>
                                <td>
                                    <span class="badge {{ $module->is_core ? 'bg-info text-dark' : 'bg-secondary' }}">
                                        {{ $module->is_core ? 'Core' : 'Optional' }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge {{ $module->is_active ? 'bg-success' : 'bg-secondary' }}">
                                        {{ $module->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td>{{ $module->sort_order }}</td>
                                <td class="text-end">
                                    <a href="{{ url('/modules/' . $module->id . '/edit') }}" class="btn btn-sm btn-primary">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endforeach
@endsection
