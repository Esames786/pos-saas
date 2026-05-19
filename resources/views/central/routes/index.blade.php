@extends('layouts.app')

@section('title', 'Route Catalog')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Route Catalog</h1>
        <p class="fw-medium">Sync system routes, publish permissions, and manage route visibility.</p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        @can('central.routes.sync')
            <form method="POST" action="{{ url('/routes/sync') }}">
                @csrf
                <button class="btn btn-dark">
                    <i class="ti ti-refresh me-1"></i>Sync Routes
                </button>
            </form>
        @endcan

        @can('central.routes.publish-all')
            <form method="POST" action="{{ url('/routes/publish-all') }}">
                @csrf
                <button class="btn btn-primary">
                    <i class="ti ti-upload me-1"></i>Publish All
                </button>
            </form>
        @endcan

        @can('central.routes.sync-permissions')
            <form method="POST" action="{{ url('/routes/sync-permissions') }}">
                @csrf
                <button class="btn btn-success">
                    <i class="ti ti-shield-check me-1"></i>Sync Central Permissions
                </button>
            </form>
        @endcan
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/routes') }}" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="route, uri, module">
            </div>

            <div class="col-md-3">
                <label class="form-label">Guard Prefix</label>
                <select name="guard" class="form-select">
                    <option value="">All</option>
                    <option value="central" @selected(request('guard') === 'central')>Central</option>
                    <option value="tenant" @selected(request('guard') === 'tenant')>Tenant</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Published</label>
                <select name="published" class="form-select">
                    <option value="">All</option>
                    <option value="1" @selected(request('published') === '1')>Published</option>
                    <option value="0" @selected(request('published') === '0')>Unpublished</option>
                </select>
            </div>

            <div class="col-md-2">
                <button class="btn btn-dark w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<form method="POST" id="routeBulkForm">
    @csrf

    <div class="card">
        <div class="card-body table-responsive">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div>
                    <button type="button" class="btn btn-sm btn-light" onclick="selectAllRoutes()">Select All</button>
                    <button type="button" class="btn btn-sm btn-light" onclick="clearAllRoutes()">Select None</button>
                </div>

                <div class="d-flex gap-2">
                    @can('central.routes.publish')
                        <button type="button" class="btn btn-sm btn-primary" onclick="submitBulk('{{ url('/routes/publish') }}')">
                            Publish Selected
                        </button>
                    @endcan

                    @can('central.routes.unpublish')
                        <button type="button" class="btn btn-sm btn-warning" onclick="submitBulk('{{ url('/routes/unpublish') }}')">
                            Unpublish Selected
                        </button>
                    @endcan
                </div>
            </div>

            <table class="table table-nowrap align-middle">
                <thead>
                    <tr>
                        <th width="40">
                            <input type="checkbox" onclick="toggleAllRoutes(this)">
                        </th>
                        <th>Route Name</th>
                        <th>URI</th>
                        <th>Method</th>
                        <th>Module</th>
                        <th>Action</th>
                        <th>Published</th>
                        <th>Synced At</th>
                    </tr>
                </thead>

                <tbody>
                @forelse($routes as $route)
                    <tr>
                        <td>
                            <input type="checkbox" name="route_ids[]" value="{{ $route->id }}" class="route-check">
                        </td>
                        <td><code>{{ $route->route_name }}</code></td>
                        <td>{{ $route->uri }}</td>
                        <td><span class="badge bg-light text-dark">{{ $route->method }}</span></td>
                        <td>{{ $route->module_key }}</td>
                        <td>{{ $route->action_key }}</td>
                        <td>
                            @if($route->is_published)
                                <span class="badge bg-success">Published</span>
                            @else
                                <span class="badge bg-secondary">Draft</span>
                            @endif
                        </td>
                        <td>{{ $route->synced_at?->format('Y-m-d H:i') ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            No routes found. Click <strong>Sync Routes</strong> first.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>

            <div class="mt-3">{{ $routes->links() }}</div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
    function toggleAllRoutes(source) {
        document.querySelectorAll('.route-check').forEach(cb => cb.checked = source.checked);
    }
    function selectAllRoutes() {
        document.querySelectorAll('.route-check').forEach(cb => cb.checked = true);
    }
    function clearAllRoutes() {
        document.querySelectorAll('.route-check').forEach(cb => cb.checked = false);
    }
    function submitBulk(action) {
        if (!document.querySelectorAll('.route-check:checked').length) {
            alert('Please select at least one route.');
            return;
        }
        const form = document.getElementById('routeBulkForm');
        form.action = action;
        form.submit();
    }
</script>
@endpush
