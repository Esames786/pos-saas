@extends('layouts.app')

@section('title', 'Users')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Users</h1>
        <p class="fw-medium">Manage tenant user accounts and access.</p>
        @isset($usage)
            <span class="badge {{ $usage['allowed'] ? 'bg-light text-dark border' : 'bg-danger' }}">
                Users: {{ $usage['used'] }} / {{ $usage['limit'] ?? 'Unlimited' }}
            </span>
        @endisset
    </div>
    @can('tenant.users.create')
        @if(!isset($usage) || $usage['allowed'])
            <a href="{{ url('/users/create') }}" class="btn btn-primary">
                <i class="ti ti-plus me-1" aria-hidden="true"></i>New User
            </a>
        @else
            <span class="btn btn-secondary disabled" aria-disabled="true">
                <i class="ti ti-lock me-1" aria-hidden="true"></i>Plan limit reached
            </span>
        @endif
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

@if(isset($usage) && !$usage['allowed'])
    <div class="alert alert-warning" role="alert">{{ $usage['message'] }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger" role="alert" aria-live="polite">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/users') }}" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label for="search-input" class="form-label">Search</label>
                <input id="search-input" type="text" name="search" class="form-control"
                       placeholder="Name, email, phone or employee code"
                       value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <label for="status-filter" class="form-label">Status</label>
                <select id="status-filter" name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="active"   @selected(request('status') === 'active')>Active</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-dark" type="submit">Filter</button>
                <a href="{{ url('/users') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">User account list</caption>
            <thead>
            <tr>
                <th scope="col">Code</th>
                <th scope="col">Name</th>
                <th scope="col">Email</th>
                <th scope="col">Phone</th>
                <th scope="col">Default Branch</th>
                <th scope="col">Roles</th>
                <th scope="col">Status</th>
                <th scope="col" class="text-end">Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse($users as $user)
                <tr>
                    <td>{{ $user->employee_code ?? '—' }}</td>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>{{ $user->phone ?? '—' }}</td>
                    <td>{{ $user->defaultBranch?->name ?? '—' }}</td>
                    <td>
                        @foreach($user->roles as $role)
                            <span class="badge bg-secondary me-1">{{ $role->name }}</span>
                        @endforeach
                    </td>
                    <td>
                        <span class="badge bg-{{ $user->status === 'active' ? 'success' : 'secondary' }}">
                            {{ ucfirst($user->status) }}
                        </span>
                    </td>
                    <td class="text-end">
                        @can('tenant.users.show')
                            <a href="{{ url('/users/' . $user->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                        @can('tenant.users.edit')
                            <a href="{{ url('/users/' . $user->id . '/edit') }}" class="btn btn-sm btn-light">Edit</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">No users found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $users->links() }}</div>
    </div>
</div>
@endsection
