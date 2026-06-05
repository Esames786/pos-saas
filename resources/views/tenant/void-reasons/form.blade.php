@php $editing = isset($reason); @endphp
@extends('layouts.app')

@section('title', $editing ? 'Edit Void Reason' : 'Create Void Reason')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $editing ? 'Edit Void Reason' : 'New Void Reason' }}</h4>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ $editing ? url('/void-reasons/' . $reason->id) : url('/void-reasons') }}">
                    @csrf
                    @if($editing) @method('PUT') @endif

                    @if($errors->any())
                        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
                    @endif

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required" for="name">Name</label>
                            <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
                                value="{{ old('name', $reason->name ?? '') }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label required" for="reason_type">Type</label>
                            <select id="reason_type" name="reason_type" class="form-select" required>
                                @foreach(['void' => 'Void', 'discount' => 'Discount', 'return' => 'Return', 'cancel' => 'Cancel', 'wastage' => 'Wastage', 'other' => 'Other'] as $v => $l)
                                    <option value="{{ $v }}" {{ old('reason_type', $reason->reason_type ?? 'void') === $v ? 'selected' : '' }}>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="requires_manager_approval" id="requires_manager_approval" value="1"
                                    {{ old('requires_manager_approval', $reason->requires_manager_approval ?? false) ? 'checked' : '' }}>
                                <label class="form-check-label" for="requires_manager_approval">
                                    Requires Manager PIN approval
                                    <small class="text-muted d-block">Cashier must enter manager PIN to use this reason</small>
                                </label>
                            </div>
                        </div>

                        <div class="col-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                                    {{ old('is_active', $reason->is_active ?? true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">{{ $editing ? 'Update' : 'Create' }}</button>
                        <a href="{{ url('/void-reasons') }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
@endsection
