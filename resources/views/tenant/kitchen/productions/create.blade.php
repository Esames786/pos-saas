@extends('layouts.app')

@section('title', 'New Production')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">New Kitchen Production</h1>
    <a href="{{ url('/kitchen/productions') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ url('/kitchen/productions') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label required">Branch</label>
                    <select name="branch_id" class="form-select @error('branch_id') is-invalid @enderror" required>
                        <option value="">— Select —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label required">Recipe</label>
                    <select name="recipe_id" class="form-select @error('recipe_id') is-invalid @enderror" required>
                        <option value="">— Select Recipe —</option>
                        @foreach($recipes as $r)
                            <option value="{{ $r->id }}" @selected(old('recipe_id') == $r->id)>
                                {{ $r->name }} — {{ $r->product?->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('recipe_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label required">Quantity to Produce</label>
                    <input type="number" name="quantity_produced" value="{{ old('quantity_produced', 1) }}"
                           class="form-control @error('quantity_produced') is-invalid @enderror"
                           step="0.0001" min="0.0001" required>
                    @error('quantity_produced') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label required">Production Date</label>
                    <input type="date" name="production_date" value="{{ old('production_date', now()->toDateString()) }}"
                           class="form-control @error('production_date') is-invalid @enderror" required>
                    @error('production_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-8">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" value="{{ old('notes') }}"
                           class="form-control" maxlength="255">
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Plan Production</button>
                <a href="{{ url('/kitchen/productions') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
