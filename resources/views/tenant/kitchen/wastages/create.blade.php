@extends('layouts.app')

@section('title', 'Record Wastage')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">Record Kitchen Wastage</h1>
    <a href="{{ url('/kitchen/wastages') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ url('/kitchen/wastages') }}">
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
                    <label class="form-label required">Product</label>
                    <select name="product_id" class="form-select @error('product_id') is-invalid @enderror" required>
                        <option value="">— Select Product —</option>
                        @foreach($products as $p)
                            <option value="{{ $p->id }}" @selected(old('product_id') == $p->id)>{{ $p->name }}</option>
                        @endforeach
                    </select>
                    @error('product_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label required">Wastage Date</label>
                    <input type="date" name="wastage_date" value="{{ old('wastage_date', now()->toDateString()) }}"
                           class="form-control @error('wastage_date') is-invalid @enderror" required>
                    @error('wastage_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label required">Quantity</label>
                    <input type="number" name="quantity" value="{{ old('quantity') }}"
                           class="form-control @error('quantity') is-invalid @enderror"
                           step="0.0001" min="0.0001" required>
                    @error('quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label">Unit</label>
                    <select name="unit_id" class="form-select">
                        <option value="">— Same as product —</option>
                        @foreach($units as $u)
                            <option value="{{ $u->id }}" @selected(old('unit_id') == $u->id)>{{ $u->name }} ({{ $u->code }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Reason</label>
                    <input type="text" name="reason" value="{{ old('reason') }}"
                           class="form-control" maxlength="255"
                           placeholder="e.g. spoilage, spillage, over-production">
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Record Wastage</button>
                <a href="{{ url('/kitchen/wastages') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
