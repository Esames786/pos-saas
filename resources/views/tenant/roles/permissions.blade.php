@extends('layouts.app')

@section('title', 'Role Permissions')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Role Permissions</h1>
        <p class="fw-medium">Role: <span class="fw-bold">{{ $role->name }}</span></p>
    </div>
    <a href="{{ url('/roles') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/roles/' . $role->id . '/permissions') }}">
    @csrf
    @method('PUT')

    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <div>
                    <button type="button" class="btn btn-sm btn-light" onclick="selectAllPermissions()">Select All</button>
                    <button type="button" class="btn btn-sm btn-light" onclick="clearAllPermissions()">Select None</button>
                </div>
                <button class="btn btn-primary">
                    <i class="ti ti-device-floppy me-1"></i>Save Permissions
                </button>
            </div>

            @forelse($permissionGroups as $module => $permissions)
                <div class="border rounded p-3 mb-3">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h5 class="mb-0 text-capitalize">{{ str_replace('.', ' / ', $module) }}</h5>
                        <div>
                            <button type="button" class="btn btn-sm btn-light" onclick="selectGroup('{{ md5($module) }}')">Select Group</button>
                            <button type="button" class="btn btn-sm btn-light" onclick="clearGroup('{{ md5($module) }}')">Clear Group</button>
                        </div>
                    </div>

                    <div class="row">
                        @foreach($permissions as $permission)
                            <div class="col-md-4 col-sm-6 mb-2">
                                <label class="d-flex align-items-center gap-2 cursor-pointer">
                                    <input type="checkbox"
                                        name="permissions[]"
                                        value="{{ $permission->name }}"
                                        class="permission-check group-{{ md5($module) }}"
                                        @checked(in_array($permission->name, $assignedPermissions))>
                                    <code class="small">{{ $permission->name }}</code>
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="text-center text-muted py-5">
                    No permissions found.
                    <a href="{{ url('/roles') }}" class="d-block mt-2">Click Sync Permissions from the roles screen.</a>
                </div>
            @endforelse

            <div class="text-end mt-3">
                <button class="btn btn-primary">
                    <i class="ti ti-device-floppy me-1"></i>Save Permissions
                </button>
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
    function selectAllPermissions() {
        document.querySelectorAll('.permission-check').forEach(cb => cb.checked = true);
    }
    function clearAllPermissions() {
        document.querySelectorAll('.permission-check').forEach(cb => cb.checked = false);
    }
    function selectGroup(groupClass) {
        document.querySelectorAll('.group-' + groupClass).forEach(cb => cb.checked = true);
    }
    function clearGroup(groupClass) {
        document.querySelectorAll('.group-' + groupClass).forEach(cb => cb.checked = false);
    }
</script>
@endpush
