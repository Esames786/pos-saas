@extends('layouts.app')

@section('title', 'Terminals')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Terminals</h1>
        <p class="fw-medium">Manage POS terminals assigned to branches.</p>
        @isset($usage)
            <span class="badge {{ $usage['allowed'] ? 'bg-light text-dark border' : 'bg-danger' }}">
                Terminals: {{ $usage['used'] }} / {{ $usage['limit'] ?? 'Unlimited' }}
            </span>
        @endisset
    </div>

    @can('tenant.terminals.create')
        @if(!isset($usage) || $usage['allowed'])
            <a href="{{ url('/terminals/create') }}" class="btn btn-primary">
                <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Terminal
            </a>
        @else
            <span class="btn btn-secondary disabled" aria-disabled="true">
                <i class="ti ti-lock me-1" aria-hidden="true"></i>Plan limit reached
            </span>
        @endif
    @endcan
</div>

@if(isset($usage) && !$usage['allowed'])
    <div class="alert alert-warning" role="alert">{{ $usage['message'] }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/terminals') }}" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="terminal-search" class="form-label">Search</label>
                <input type="text" id="terminal-search" name="search" value="{{ request('search') }}"
                    class="form-control" placeholder="Code or name">
            </div>
            <div class="col-md-4">
                <label for="branch-filter" class="form-label">Branch</label>
                <select id="branch-filter" name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-dark" type="submit">Filter</button>
                <a href="{{ url('/terminals') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption>Terminal list</caption>
            <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Name</th>
                    <th scope="col">Branch</th>
                    <th scope="col">Shift Required</th>
                    <th scope="col">Open Shift</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($terminals as $terminal)
                <tr>
                    <td><code>{{ $terminal->code }}</code></td>
                    <td>{{ $terminal->name }}</td>
                    <td>{{ $terminal->branch?->name ?? '—' }}</td>
                    <td>{{ $terminal->requires_shift ? 'Yes' : 'No' }}</td>
                    <td>
                        @if($terminal->openShift)
                            <span class="badge bg-warning text-dark">Open</span>
                        @else
                            <span class="badge bg-light text-muted">None</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $terminal->status === 'active' ? 'bg-success' : 'bg-secondary' }}">
                            {{ ucfirst($terminal->status) }}
                        </span>
                    </td>
                    <td class="text-end">
                        <div class="action-toolbar justify-content-end">
                            @can('tenant.terminals.edit')
                                <a href="{{ url('/terminals/' . $terminal->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                            @endcan

                            @can('tenant.terminals.destroy')
                                <form method="POST" action="{{ url('/terminals/' . $terminal->id) }}" class="d-inline"
                                      onsubmit="return confirm('Delete terminal {{ addslashes($terminal->name) }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No terminals found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        <div class="mt-3">{{ $terminals->links() }}</div>
    </div>
</div>
@endsection
