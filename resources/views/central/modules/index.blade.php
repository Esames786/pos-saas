@extends('layouts.app')

@section('title', 'Modules')

@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">Modules</h1>
        <p class="text-muted mb-0">Business feature groups used by plan enforcement and the Plan Builder.</p>
    </div>
</div>

<div class="alert alert-info d-flex align-items-start gap-2">
    <i class="ti ti-info-circle fs-5"></i>
    <div>
        Modules group routes into business features that can be enabled or disabled on plans.
        Route keys are hidden by default because they are technical enforcement mappings.
    </div>
</div>

@foreach($modules as $category => $items)
    <section class="mb-4">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h5 class="mb-0">{{ $category ?: 'Uncategorized' }}</h5>
            <span class="badge bg-light text-dark border">{{ $items->count() }} modules</span>
        </div>

        <div class="card">
            <div class="card-body table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Core</th>
                            <th>Status</th>
                            <th>Route Keys</th>
                            <th>Sort</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $module)
                            @php
                                $routeKeys = $module->route_module_keys ?? [];
                                $summary = $moduleSummaries[$module->key] ?? [];
                                $collapseId = 'module-routes-' . $module->id;
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $module->name }}</div>
                                    <code class="small">{{ $module->key }}</code>
                                </td>
                                <td class="text-muted">{{ $summary['description'] ?? ($module->description ?: 'Business feature controlled by this module.') }}</td>
                                <td>{{ $module->category ?: 'Uncategorized' }}</td>
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
                                <td>
                                    <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $collapseId }}" aria-expanded="false" aria-controls="{{ $collapseId }}">
                                        {{ count($routeKeys) }} keys
                                    </button>
                                </td>
                                <td>{{ $module->sort_order }}</td>
                                <td class="text-end">
                                    <a href="{{ url('/modules/' . $module->id . '/edit') }}" class="btn btn-sm btn-primary">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                            <tr class="collapse" id="{{ $collapseId }}">
                                <td colspan="8" class="bg-light">
                                    <div class="small text-muted mb-2">Advanced route keys mapped to this business module.</div>
                                    @forelse($routeKeys as $key)
                                        <span class="badge bg-white text-dark border mb-1">{{ $key }}</span>
                                    @empty
                                        <span class="text-muted">No route keys mapped.</span>
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endforeach
@endsection
