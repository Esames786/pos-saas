@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $title }}</h1>
    </div>
    <a href="{{ url('/customers') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

@php
    $action = $customer ? url('/customers/' . $customer->id) : url('/customers');
    $method = $customer ? 'PUT' : 'POST';
@endphp

<form method="POST" action="{{ $action }}" novalidate>
    @csrf
    @if($method === 'PUT') @method('PUT') @endif

    <div class="card">
        <div class="card-body row g-3">
            <div class="col-md-3">
                <label for="code" class="form-label">Customer Code</label>
                <input id="code" name="code" type="text"
                       class="form-control @error('code') is-invalid @enderror"
                       value="{{ old('code', $customer?->code) }}"
                       placeholder="AUTO if blank">
                @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-5">
                <label for="name" class="form-label required">Name</label>
                <input id="name" name="name" type="text" required
                       class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name', $customer?->name) }}">
                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="phone" class="form-label">Phone</label>
                <input id="phone" name="phone" type="text"
                       class="form-control @error('phone') is-invalid @enderror"
                       value="{{ old('phone', $customer?->phone) }}">
                @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="email" class="form-label">Email</label>
                <input id="email" name="email" type="email"
                       class="form-control @error('email') is-invalid @enderror"
                       value="{{ old('email', $customer?->email) }}">
                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="tax_number" class="form-label">Tax Number</label>
                <input id="tax_number" name="tax_number" type="text"
                       class="form-control @error('tax_number') is-invalid @enderror"
                       value="{{ old('tax_number', $customer?->tax_number) }}">
                @error('tax_number') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-2">
                <label for="gender" class="form-label">Gender</label>
                <select id="gender" name="gender" class="form-select @error('gender') is-invalid @enderror">
                    <option value="">—</option>
                    <option value="male"   @selected(old('gender', $customer?->gender) === 'male')>Male</option>
                    <option value="female" @selected(old('gender', $customer?->gender) === 'female')>Female</option>
                    <option value="other"  @selected(old('gender', $customer?->gender) === 'other')>Other</option>
                </select>
                @error('gender') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-2">
                <label for="date_of_birth" class="form-label">Date of Birth</label>
                <input id="date_of_birth" name="date_of_birth" type="date"
                       class="form-control @error('date_of_birth') is-invalid @enderror"
                       value="{{ old('date_of_birth', $customer?->date_of_birth?->format('Y-m-d')) }}">
                @error('date_of_birth') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-12">
                <label for="address" class="form-label">Address</label>
                <textarea id="address" name="address" rows="2"
                          class="form-control @error('address') is-invalid @enderror">{{ old('address', $customer?->address) }}</textarea>
                @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="status" class="form-label required">Status</label>
                <select id="status" name="status" class="form-select @error('status') is-invalid @enderror" required>
                    <option value="active"   @selected(old('status', $customer?->status ?? 'active') === 'active')>Active</option>
                    <option value="inactive" @selected(old('status', $customer?->status) === 'inactive')>Inactive</option>
                </select>
                @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save Customer</button>
                <a href="{{ url('/customers') }}" class="btn btn-light ms-2">Cancel</a>
            </div>
        </div>
    </div>
</form>
@endsection
