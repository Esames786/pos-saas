@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $title }}</h1>
        <p class="fw-medium">Terminal details and branch assignment.</p>
    </div>
    <a href="{{ url('/terminals') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card">
    <div class="card-body">
        <form method="POST"
              action="{{ $terminal ? url('/terminals/' . $terminal->id) : url('/terminals') }}"
              class="row g-3"
              novalidate>
            @csrf
            @if($terminal) @method('PUT') @endif

            <div class="col-md-4">
                <label for="branch_id" class="form-label required">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select" required>
                    <option value="">Select branch…</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}"
                            @selected(old('branch_id', $terminal?->branch_id) == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-3">
                <label for="code" class="form-label required">Terminal Code</label>
                <input type="text" id="code" name="code"
                    value="{{ old('code', $terminal?->code) }}"
                    class="form-control" required maxlength="50"
                    placeholder="T-001">
            </div>

            <div class="col-md-5">
                <label for="name" class="form-label required">Terminal Name</label>
                <input type="text" id="name" name="name"
                    value="{{ old('name', $terminal?->name) }}"
                    class="form-control" required maxlength="190"
                    placeholder="Main Counter">
            </div>

            <div class="col-md-6">
                <label for="device_identifier" class="form-label">Device Identifier</label>
                <input type="text" id="device_identifier" name="device_identifier"
                    value="{{ old('device_identifier', $terminal?->device_identifier) }}"
                    class="form-control" maxlength="190"
                    placeholder="MAC address or hostname">
                <p class="form-help mt-1">Optional identifier for auto-detecting this terminal.</p>
            </div>

            <div class="col-md-3">
                <label for="status" class="form-label required">Status</label>
                <select id="status" name="status" class="form-select" required>
                    <option value="active" @selected(old('status', $terminal?->status ?? 'active') === 'active')>Active</option>
                    <option value="inactive" @selected(old('status', $terminal?->status) === 'inactive')>Inactive</option>
                </select>
            </div>

            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch"
                        id="requires_shift" name="requires_shift" value="1"
                        @checked(old('requires_shift', $terminal?->requires_shift ?? true))>
                    <label class="form-check-label" for="requires_shift">Requires Shift</label>
                </div>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>
                    {{ $terminal ? 'Update Terminal' : 'Create Terminal' }}
                </button>
                <a href="{{ url('/terminals') }}" class="btn btn-light ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
