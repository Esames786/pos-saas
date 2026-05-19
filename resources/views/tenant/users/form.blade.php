@extends('layouts.app')

@section('title', isset($user) ? 'Edit User' : 'New User')

@section('content')
@php $isEdit = isset($user); @endphp

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $isEdit ? 'Edit User' : 'New User' }}</h1>
        @if($isEdit)
            <p class="fw-medium">{{ $user->name }}</p>
        @endif
    </div>
    <a href="{{ $isEdit ? url('/users/' . $user->id) : url('/users') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert" aria-live="polite">{{ $errors->first() }}</div>
@endif

<form method="POST"
      action="{{ $isEdit ? url('/users/' . $user->id) : url('/users') }}"
      novalidate>
    @csrf
    @if($isEdit) @method('PUT') @endif

    {{-- Profile --}}
    <div class="card mb-3">
        <div class="card-header"><strong>Profile</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label for="name" class="form-label required">Full Name</label>
                <input id="name" type="text" name="name" required
                       class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name', $user->name ?? '') }}">
                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="email" class="form-label required">Email</label>
                <input id="email" type="email" name="email" required
                       class="form-control @error('email') is-invalid @enderror"
                       value="{{ old('email', $user->email ?? '') }}">
                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="employee_code" class="form-label">Employee Code</label>
                <input id="employee_code" type="text" name="employee_code"
                       class="form-control @error('employee_code') is-invalid @enderror"
                       value="{{ old('employee_code', $user->employee_code ?? '') }}">
                @error('employee_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="phone" class="form-label">Phone</label>
                <input id="phone" type="text" name="phone"
                       class="form-control @error('phone') is-invalid @enderror"
                       value="{{ old('phone', $user->phone ?? '') }}">
                @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="status" class="form-label required">Status</label>
                <select id="status" name="status" required
                        class="form-select @error('status') is-invalid @enderror">
                    <option value="active"   @selected(old('status', $user->status ?? 'active') === 'active')>Active</option>
                    <option value="inactive" @selected(old('status', $user->status ?? '') === 'inactive')>Inactive</option>
                </select>
                @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check mb-2">
                    <input type="hidden" name="force_password_change" value="0">
                    <input id="force_password_change" type="checkbox" name="force_password_change" value="1"
                           class="form-check-input"
                           @checked(old('force_password_change', $isEdit ? ($user->force_password_change ? '1' : '0') : '0') == '1')>
                    <label class="form-check-label" for="force_password_change">
                        Force password change on next login
                    </label>
                </div>
            </div>
        </div>
    </div>

    {{-- Password (create only) --}}
    @if(!$isEdit)
    <div class="card mb-3">
        <div class="card-header"><strong>Password</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label for="password" class="form-label required">Password</label>
                <input id="password" type="password" name="password" required
                       class="form-control @error('password') is-invalid @enderror"
                       autocomplete="new-password">
                @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>
    @endif

    {{-- Default Branch & Terminal --}}
    <div class="card mb-3">
        <div class="card-header"><strong>Default Branch &amp; Terminal</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label for="default_branch_id" class="form-label">Default Branch</label>
                <select id="default_branch_id" name="default_branch_id"
                        class="form-select @error('default_branch_id') is-invalid @enderror">
                    <option value="">— None —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}"
                            @selected(old('default_branch_id', $user->default_branch_id ?? '') == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
                @error('default_branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="default_terminal_id" class="form-label">Default Terminal</label>
                <select id="default_terminal_id" name="default_terminal_id"
                        class="form-select @error('default_terminal_id') is-invalid @enderror">
                    <option value="">— None —</option>
                    @foreach($terminals as $terminal)
                        <option value="{{ $terminal->id }}"
                            @selected(old('default_terminal_id', $user->default_terminal_id ?? '') == $terminal->id)>
                            {{ $terminal->name }}
                        </option>
                    @endforeach
                </select>
                @error('default_terminal_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    {{-- Branch Access --}}
    <div class="card mb-3">
        <div class="card-header"><strong>Branch Access</strong></div>
        <div class="card-body">
            @php
                $assignedBranchIds = $isEdit
                    ? $user->branches->pluck('id')->toArray()
                    : [];
                $oldBranchIds = old('branch_ids', $assignedBranchIds);
            @endphp
            @if($branches->isEmpty())
                <p class="text-muted mb-0">No active branches.</p>
            @else
                <div class="row g-2">
                    @foreach($branches as $branch)
                        <div class="col-md-3">
                            <div class="form-check">
                                <input type="checkbox" id="branch_{{ $branch->id }}"
                                       name="branch_ids[]" value="{{ $branch->id }}"
                                       class="form-check-input"
                                       @checked(in_array($branch->id, (array) $oldBranchIds))>
                                <label class="form-check-label" for="branch_{{ $branch->id }}">
                                    {{ $branch->name }}
                                </label>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Terminal Access --}}
    <div class="card mb-3">
        <div class="card-header"><strong>Terminal Access</strong></div>
        <div class="card-body">
            @php
                $assignedTerminalIds = $isEdit
                    ? $user->terminals->pluck('id')->toArray()
                    : [];
                $oldTerminalIds = old('terminal_ids', $assignedTerminalIds);
            @endphp
            @if($terminals->isEmpty())
                <p class="text-muted mb-0">No active terminals.</p>
            @else
                <div class="row g-2">
                    @foreach($terminals as $terminal)
                        <div class="col-md-3">
                            <div class="form-check">
                                <input type="checkbox" id="terminal_{{ $terminal->id }}"
                                       name="terminal_ids[]" value="{{ $terminal->id }}"
                                       class="form-check-input"
                                       @checked(in_array($terminal->id, (array) $oldTerminalIds))>
                                <label class="form-check-label" for="terminal_{{ $terminal->id }}">
                                    {{ $terminal->name }}
                                </label>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Role Assignment --}}
    <div class="card mb-3">
        <div class="card-header"><strong>Roles</strong></div>
        <div class="card-body">
            @php
                $assignedRoles = $isEdit
                    ? $user->roles->pluck('name')->toArray()
                    : [];
                $oldRoles = old('roles', $assignedRoles);
            @endphp
            @if($roles->isEmpty())
                <p class="text-muted mb-0">No roles defined yet.</p>
            @else
                <div class="row g-2">
                    @foreach($roles as $role)
                        <div class="col-md-3">
                            <div class="form-check">
                                <input type="checkbox" id="role_{{ $role->id }}"
                                       name="roles[]" value="{{ $role->name }}"
                                       class="form-check-input"
                                       @checked(in_array($role->name, (array) $oldRoles))>
                                <label class="form-check-label" for="role_{{ $role->id }}">
                                    {{ $role->name }}
                                </label>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit">
            {{ $isEdit ? 'Update User' : 'Create User' }}
        </button>
        <a href="{{ $isEdit ? url('/users/' . $user->id) : url('/users') }}" class="btn btn-light">Cancel</a>
    </div>
</form>
@endsection
