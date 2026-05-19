@extends('layouts.app')

@section('title', $user->name)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $user->name }}</h1>
        @if($user->employee_code)
            <p class="fw-medium"><code>{{ $user->employee_code }}</code></p>
        @endif
    </div>
    <div class="d-flex gap-2">
        @can('tenant.users.edit')
            <a href="{{ url('/users/' . $user->id . '/edit') }}" class="btn btn-primary">Edit</a>
        @endcan
        <a href="{{ url('/users') }}" class="btn btn-light">Back</a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger" role="alert" aria-live="polite">{{ $errors->first() }}</div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        {{-- User details --}}
        <div class="card mb-3">
            <div class="card-header"><strong>User Details</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Name</dt>
                    <dd class="col-sm-8">{{ $user->name }}</dd>

                    <dt class="col-sm-4">Email</dt>
                    <dd class="col-sm-8">{{ $user->email }}</dd>

                    <dt class="col-sm-4">Employee Code</dt>
                    <dd class="col-sm-8">{{ $user->employee_code ?? '—' }}</dd>

                    <dt class="col-sm-4">Phone</dt>
                    <dd class="col-sm-8">{{ $user->phone ?? '—' }}</dd>

                    <dt class="col-sm-4">Default Branch</dt>
                    <dd class="col-sm-8">{{ $user->defaultBranch?->name ?? '—' }}</dd>

                    <dt class="col-sm-4">Default Terminal</dt>
                    <dd class="col-sm-8">{{ $user->defaultTerminal?->name ?? '—' }}</dd>

                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        <span class="badge bg-{{ $user->status === 'active' ? 'success' : 'secondary' }}">
                            {{ ucfirst($user->status) }}
                        </span>
                    </dd>

                    <dt class="col-sm-4">Force Password Change</dt>
                    <dd class="col-sm-8">{{ $user->force_password_change ? 'Yes' : 'No' }}</dd>

                    <dt class="col-sm-4">Last Login</dt>
                    <dd class="col-sm-8">{{ $user->last_login_at?->format('Y-m-d H:i') ?? 'Never' }}</dd>
                </dl>
            </div>
        </div>

        {{-- Roles --}}
        <div class="card mb-3">
            <div class="card-header"><strong>Roles</strong></div>
            <div class="card-body">
                @forelse($user->roles as $role)
                    <span class="badge bg-secondary me-1 mb-1">{{ $role->name }}</span>
                @empty
                    <p class="text-muted mb-0">No roles assigned.</p>
                @endforelse
            </div>
        </div>

        {{-- Branch Access --}}
        <div class="card mb-3">
            <div class="card-header"><strong>Branch Access</strong></div>
            <div class="card-body">
                @forelse($user->branches as $branch)
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span>{{ $branch->name }}</span>
                        @if($branch->pivot->is_default)
                            <span class="badge bg-primary">Default</span>
                        @endif
                    </div>
                @empty
                    <p class="text-muted mb-0">No branch access assigned.</p>
                @endforelse
            </div>
        </div>

        {{-- Terminal Access --}}
        <div class="card mb-3">
            <div class="card-header"><strong>Terminal Access</strong></div>
            <div class="card-body">
                @forelse($user->terminals as $terminal)
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span>{{ $terminal->name }}</span>
                        @if($terminal->pivot->is_default)
                            <span class="badge bg-primary">Default</span>
                        @endif
                    </div>
                @empty
                    <p class="text-muted mb-0">No terminal access assigned.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        {{-- Reset Password --}}
        @can('tenant.users.reset-password')
        <div class="card mb-3">
            <div class="card-header"><strong>Reset Password</strong></div>
            <div class="card-body">
                <form method="POST" action="{{ url('/users/' . $user->id . '/reset-password') }}" novalidate>
                    @csrf
                    <div class="mb-3">
                        <label for="new_password" class="form-label required">New Password</label>
                        <input id="new_password" type="password" name="new_password" required
                               class="form-control" autocomplete="new-password" minlength="8">
                    </div>
                    <div class="mb-3">
                        <label for="new_password_confirmation" class="form-label required">Confirm Password</label>
                        <input id="new_password_confirmation" type="password"
                               name="new_password_confirmation" required
                               class="form-control" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-warning w-100"
                            onclick="return confirm('Reset password for {{ addslashes($user->name) }}?')">
                        Reset Password
                    </button>
                </form>
            </div>
        </div>
        @endcan

        {{-- Activate / Deactivate --}}
        @can('tenant.users.activate')
        @if($user->id !== auth('tenant')->id())
        <div class="card mb-3">
            <div class="card-header"><strong>Account Status</strong></div>
            <div class="card-body">
                <form method="POST" action="{{ url('/users/' . $user->id . '/activate') }}">
                    @csrf
                    <button type="submit"
                            class="btn w-100 btn-{{ $user->status === 'active' ? 'outline-secondary' : 'success' }}"
                            onclick="return confirm('{{ $user->status === 'active' ? 'Deactivate' : 'Activate' }} {{ addslashes($user->name) }}?')">
                        {{ $user->status === 'active' ? 'Deactivate User' : 'Activate User' }}
                    </button>
                </form>
            </div>
        </div>
        @endif
        @endcan

        {{-- Deactivate (destroy) --}}
        @can('tenant.users.destroy')
        @if($user->id !== auth('tenant')->id() && $user->status === 'active')
        <div class="card mb-3">
            <div class="card-header"><strong>Danger Zone</strong></div>
            <div class="card-body">
                <form method="POST" action="{{ url('/users/' . $user->id) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="btn btn-outline-danger w-100"
                            onclick="return confirm('Deactivate {{ addslashes($user->name) }}? They will lose access immediately.')">
                        Deactivate Account
                    </button>
                </form>
            </div>
        </div>
        @endif
        @endcan
    </div>
</div>
@endsection
