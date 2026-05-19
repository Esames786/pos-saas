@extends('layouts.app')

@section('title', 'New Daily Closing')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">New Daily Closing</h1>
        <p class="fw-medium">Consolidate all closed shifts for a branch on a specific date.</p>
    </div>
    <a href="{{ url('/daily-closings') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/daily-closings') }}" novalidate>
    @csrf

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Closing Details</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <label for="branch_id" class="form-label required">Branch</label>
                    <select id="branch_id" name="branch_id" class="form-select" required>
                        <option value="">Select branch…</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label for="closing_date" class="form-label required">Closing Date</label>
                    <input type="date" id="closing_date" name="closing_date"
                        value="{{ old('closing_date', date('Y-m-d')) }}"
                        class="form-control" required>
                </div>

                <div class="col-md-4">
                    <label for="counted_cash" class="form-label">Manual Counted Cash</label>
                    <input type="number" id="counted_cash" name="counted_cash"
                        value="{{ old('counted_cash') }}"
                        class="form-control" min="0" step="0.01"
                        placeholder="0.00">
                    <p class="form-help mt-1">Used only if no denomination entries are made below.</p>
                </div>

                <div class="col-12">
                    <label for="notes" class="form-label">Notes</label>
                    <textarea id="notes" name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Cash Count (Optional)</h5></div>
        <div class="card-body">
            @include('tenant.partials.cash-count')
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Complete Daily Closing
        </button>
        <a href="{{ url('/daily-closings') }}" class="btn btn-light">Cancel</a>
    </div>
</form>
@endsection
