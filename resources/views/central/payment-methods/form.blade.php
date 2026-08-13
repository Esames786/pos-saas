@extends('layouts.app')

@section('title', $method->exists ? 'Edit Payment Method' : 'Add Payment Method')

@section('content')
<div class="mb-3">
    <h1 class="mb-1">{{ $method->exists ? 'Edit' : 'Add' }} Payment Method</h1>
    <p class="text-muted mb-0">Account details are stored only here (never in code). A method must be Active and have an account title + number before tenants see it.</p>
</div>

@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ $method->exists ? route('central.payment-methods.update', $method) : route('central.payment-methods.store') }}">
            @csrf
            @if($method->exists) @method('PUT') @endif

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Code <span class="text-danger">*</span></label>
                    <input type="text" name="code" class="form-control" value="{{ old('code', $method->code) }}"
                           placeholder="easypaisa" {{ $method->exists ? 'readonly' : '' }} required>
                    <div class="form-text">lowercase, letters/numbers/-/_ ; cannot change after creation.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Display Name <span class="text-danger">*</span></label>
                    <input type="text" name="display_name" class="form-control" value="{{ old('display_name', $method->display_name) }}" placeholder="EasyPaisa" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', $method->sort_order ?? 0) }}" min="0">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Account Title</label>
                    <input type="text" name="account_title" class="form-control" value="{{ old('account_title', $method->account_title) }}" placeholder="Account holder name">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Account / Number</label>
                    <input type="text" name="account_number" class="form-control" value="{{ old('account_number', $method->account_number) }}" placeholder="03xxxxxxxxx / IBAN">
                </div>

                <div class="col-12">
                    <label class="form-label">Instructions (optional)</label>
                    <textarea name="instructions" class="form-control" rows="3" placeholder="How to pay, what to send in the reference, etc.">{{ old('instructions', $method->instructions) }}</textarea>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input" id="is_active" {{ old('is_active', $method->is_active) ? 'checked' : '' }}>
                        <label class="form-check-label" for="is_active">Active (shown to tenants)</label>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <button class="btn btn-primary">{{ $method->exists ? 'Save' : 'Create' }}</button>
                <a href="{{ route('central.payment-methods.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
