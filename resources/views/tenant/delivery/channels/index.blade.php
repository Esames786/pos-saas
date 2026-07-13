@extends('layouts.app')
@section('title', 'Delivery Channels')
@section('content')
<div class="content-wrapper">
    <div class="content">
        <div class="container-fluid">
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col"><h3 class="page-title">Delivery Channels</h3></div>
                </div>
            </div>

            @if(session('status'))
                <div class="alert alert-success alert-dismissible">
                    {{ session('status') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            {{-- Filters --}}
            <form method="GET" action="{{ url('/delivery/channels') }}" class="card card-body mb-3 py-2">
                <div class="row g-2 align-items-end">
                    <div class="col-sm-3">
                        <label class="form-label small mb-1">Type</label>
                        <select name="type" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="aggregator" @selected(request('type') === 'aggregator')>Aggregator (Foodpanda etc.)</option>
                            <option value="own" @selected(request('type') === 'own')>Own Delivery</option>
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label small mb-1">Status</label>
                        <select name="is_active" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="1" @selected(request('is_active') === '1')>Active</option>
                            <option value="0" @selected(request('is_active') === '0')>Inactive</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-secondary">Filter</button>
                        <a href="{{ url('/delivery/channels') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                    </div>
                </div>
            </form>

            @can('tenant.delivery-channels.store')
            <div class="card mb-3">
                <div class="card-header fw-semibold">Add Channel</div>
                <div class="card-body">
                    <form method="POST" action="{{ url('/delivery/channels') }}" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-sm-3">
                            <label class="form-label small mb-1">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control form-control-sm"
                                   placeholder="Foodpanda" maxlength="100" required value="{{ old('name') }}">
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small mb-1">Type <span class="text-danger">*</span></label>
                            <select name="type" class="form-select form-select-sm" required>
                                <option value="aggregator" @selected(old('type', 'aggregator') === 'aggregator')>Aggregator</option>
                                <option value="own" @selected(old('type') === 'own')>Own Delivery</option>
                            </select>
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small mb-1">Commission %</label>
                            <input type="number" name="commission_percent" class="form-control form-control-sm"
                                   step="0.01" min="0" max="100" value="{{ old('commission_percent', 0) }}">
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small mb-1">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control form-control-sm"
                                   min="0" value="{{ old('sort_order', 0) }}">
                        </div>
                        <div class="col-sm-1">
                            <label class="form-label small mb-1">Active</label>
                            <select name="is_active" class="form-select form-select-sm">
                                <option value="1" @selected(old('is_active', '1') === '1')>Yes</option>
                                <option value="0" @selected(old('is_active') === '0')>No</option>
                            </select>
                        </div>
                        <div class="col-sm-1">
                            <button class="btn btn-sm btn-primary w-100">Add</button>
                        </div>
                    </form>
                </div>
            </div>
            @endcan

            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th class="text-end">Commission %</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($channels as $channel)
                                <tr>
                                    <td class="fw-medium">{{ $channel->name }}</td>
                                    <td>
                                        <span class="badge bg-{{ $channel->type === 'own' ? 'info' : 'warning text-dark' }}">
                                            {{ $channel->type === 'own' ? 'Own Delivery' : 'Aggregator' }}
                                        </span>
                                    </td>
                                    <td class="text-end">{{ number_format((float) $channel->commission_percent, 2) }}</td>
                                    <td>
                                        <span class="badge bg-{{ $channel->is_active ? 'success' : 'secondary' }}">
                                            {{ $channel->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td>
                                        @can('tenant.delivery-channels.update')
                                        <button class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editChannelModal-{{ $channel->id }}">Edit</button>
                                        @endcan
                                        @can('tenant.delivery-channels.destroy')
                                        <form method="POST" action="{{ url('/delivery/channels/' . $channel->id) }}"
                                              class="d-inline"
                                              onsubmit="return confirm('Delete channel {{ addslashes($channel->name) }}?')">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                        @endcan
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="5" class="text-center py-4 text-muted">No delivery channels found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $channels->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

@foreach($channels as $channel)
<div class="modal fade" id="editChannelModal-{{ $channel->id }}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Channel - {{ $channel->name }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="{{ url('/delivery/channels/' . $channel->id) }}">
                @csrf @method('PUT')
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control"
                               value="{{ $channel->name }}" maxlength="100" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                <option value="aggregator" @selected($channel->type === 'aggregator')>Aggregator</option>
                                <option value="own" @selected($channel->type === 'own')>Own Delivery</option>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Commission %</label>
                            <input type="number" name="commission_percent" class="form-control"
                                   step="0.01" min="0" max="100" value="{{ $channel->commission_percent }}">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control"
                                   min="0" value="{{ $channel->sort_order }}">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Active</label>
                            <select name="is_active" class="form-select">
                                <option value="1" @selected($channel->is_active)>Yes</option>
                                <option value="0" @selected(! $channel->is_active)>No</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach
@endsection
