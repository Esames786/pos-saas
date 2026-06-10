@extends('layouts.app')

@section('title', 'New Stock Count')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">New Stock Count</h1>
        <p class="text-muted mb-0">Create a physical inventory count session</p>
    </div>
    <a href="{{ url('/stock-counts') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card">
    <form method="POST" action="{{ url('/stock-counts') }}">
        @csrf
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label required">Branch</label>
                <select name="branch_id" class="form-select @error('branch_id') is-invalid @enderror" required>
                    <option value="">— Select Branch —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
                @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control @error('notes') is-invalid @enderror"
                          rows="3">{{ old('notes') }}</textarea>
                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="card-footer d-flex gap-2">
            <button class="btn btn-primary">Create Stock Count</button>
            <a href="{{ url('/stock-counts') }}" class="btn btn-light">Cancel</a>
        </div>
    </form>
</div>
@endsection
