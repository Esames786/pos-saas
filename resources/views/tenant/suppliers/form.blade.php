@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $title }}</h1>
        <p class="fw-medium">Supplier details, payment terms, tax number, and opening balance.</p>
    </div>
    <a href="{{ url('/suppliers') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ $supplier ? url('/suppliers/' . $supplier->id) : url('/suppliers') }}" novalidate>
    @csrf
    @if($supplier)
        @method('PUT')
    @endif

    <div class="card">
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label for="code" class="form-label required">Code</label>
                <input id="code" name="code" required
                       class="form-control @error('code') is-invalid @enderror"
                       value="{{ old('code', $supplier?->code) }}">
                @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-8">
                <label for="name" class="form-label required">Supplier Name</label>
                <input id="name" name="name" required
                       class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name', $supplier?->name) }}">
                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="contact_person" class="form-label">Contact Person</label>
                <input id="contact_person" name="contact_person"
                       class="form-control @error('contact_person') is-invalid @enderror"
                       value="{{ old('contact_person', $supplier?->contact_person) }}">
                @error('contact_person') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="phone" class="form-label">Phone</label>
                <input id="phone" name="phone"
                       class="form-control @error('phone') is-invalid @enderror"
                       value="{{ old('phone', $supplier?->phone) }}">
                @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="email" class="form-label">Email</label>
                <input id="email" type="email" name="email"
                       class="form-control @error('email') is-invalid @enderror"
                       value="{{ old('email', $supplier?->email) }}">
                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="tax_number" class="form-label">Tax Number / NTN / VAT</label>
                <input id="tax_number" name="tax_number"
                       class="form-control @error('tax_number') is-invalid @enderror"
                       value="{{ old('tax_number', $supplier?->tax_number) }}">
                @error('tax_number') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="payment_terms_days" class="form-label">Payment Terms (Days)</label>
                <input id="payment_terms_days" type="number" min="0" max="365" name="payment_terms_days"
                       class="form-control @error('payment_terms_days') is-invalid @enderror"
                       value="{{ old('payment_terms_days', $supplier?->payment_terms_days ?? 0) }}">
                @error('payment_terms_days') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="status" class="form-label required">Status</label>
                <select id="status" name="status" required
                        class="form-select @error('status') is-invalid @enderror">
                    <option value="active" @selected(old('status', $supplier?->status ?? 'active') === 'active')>Active</option>
                    <option value="inactive" @selected(old('status', $supplier?->status) === 'inactive')>Inactive</option>
                </select>
                @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            @if(!$supplier)
                <div class="col-md-4">
                    <label for="opening_balance" class="form-label">Opening Payable Balance</label>
                    <input id="opening_balance" type="number" step="0.01" min="0" name="opening_balance"
                           class="form-control @error('opening_balance') is-invalid @enderror"
                           value="{{ old('opening_balance', 0) }}">
                    <div class="form-text">Amount your business already owes this supplier.</div>
                    @error('opening_balance') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            @endif

            <div class="col-12">
                <label for="address" class="form-label">Address</label>
                <textarea id="address" name="address" rows="3"
                          class="form-control @error('address') is-invalid @enderror">{{ old('address', $supplier?->address) }}</textarea>
                @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save Supplier</button>
                <a href="{{ url('/suppliers') }}" class="btn btn-light ms-2">Cancel</a>
            </div>
        </div>
    </div>
</form>
@endsection
