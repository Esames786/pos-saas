@extends('layouts.app')

@section('title', 'Create Role')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Create Role</h1>
        <p class="fw-medium">Create a new employee role.</p>
    </div>
    <a href="{{ url('/roles') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ url('/roles') }}" class="row g-3">
            @csrf

            <div class="col-md-6">
                <label class="form-label">Role Name <span class="text-danger">*</span></label>
                <input type="text" name="name" value="{{ old('name') }}" class="form-control" required placeholder="Cashier">
            </div>

            <div class="col-12">
                <button class="btn btn-primary">
                    <i class="ti ti-device-floppy me-1"></i>Create Role
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
