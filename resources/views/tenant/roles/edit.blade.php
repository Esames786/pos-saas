@extends('layouts.app')

@section('title', 'Edit Role')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Edit Role</h1>
        <p class="fw-medium">{{ $role->name }}</p>
    </div>
    <a href="{{ url('/roles') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ url('/roles/' . $role->id) }}" class="row g-3">
            @csrf
            @method('PUT')

            <div class="col-md-6">
                <label class="form-label">Role Name <span class="text-danger">*</span></label>
                <input type="text" name="name" value="{{ old('name', $role->name) }}" class="form-control" required
                    @disabled($role->name === 'Owner')>

                @if($role->name === 'Owner')
                    <small class="text-muted">Owner role name cannot be changed.</small>
                @endif
            </div>

            <div class="col-12">
                <button class="btn btn-primary" @disabled($role->name === 'Owner')>
                    <i class="ti ti-device-floppy me-1"></i>Update Role
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
