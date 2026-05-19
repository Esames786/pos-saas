@extends('layouts.app')

@section('title', 'Close Shift #' . $shift->id)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Close Shift #{{ $shift->id }}</h1>
        <p class="fw-medium">{{ $shift->branch?->name }} — {{ $shift->terminal?->name }}</p>
    </div>
    <a href="{{ url('/shifts') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/shifts/' . $shift->id . '/close') }}" novalidate>
    @csrf

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Shift Summary</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3 col-sm-6">
                    <p class="form-help mb-1">Opening Cash</p>
                    <strong class="fs-5">{{ number_format($shift->opening_cash, 2) }}</strong>
                </div>
                <div class="col-md-3 col-sm-6">
                    <p class="form-help mb-1">Total Sales</p>
                    <strong class="fs-5">{{ number_format($shift->total_sales, 2) }}</strong>
                </div>
                <div class="col-md-3 col-sm-6">
                    <p class="form-help mb-1">Expected Cash</p>
                    <strong class="fs-5">{{ number_format($shift->expected_cash, 2) }}</strong>
                </div>
                <div class="col-md-3 col-sm-6">
                    <p class="form-help mb-1">Opened At</p>
                    <strong>{{ $shift->opened_at?->format('Y-m-d H:i') }}</strong>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Cash Count</h5></div>
        <div class="card-body">
            @include('tenant.partials.cash-count')

            <div class="row g-3 mt-2">
                <div class="col-md-4">
                    <label for="counted_cash" class="form-label">Manual Counted Cash</label>
                    <input type="number" id="counted_cash" name="counted_cash"
                        value="{{ old('counted_cash') }}"
                        class="form-control" min="0" step="0.01"
                        placeholder="Leave empty if using denomination count">
                    <p class="form-help mt-1">Used only if no denomination entries are made above.</p>
                </div>

                <div class="col-md-8">
                    <label for="closing_notes" class="form-label">Closing Notes</label>
                    <input type="text" id="closing_notes" name="closing_notes"
                        value="{{ old('closing_notes') }}"
                        class="form-control" maxlength="500">
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-danger">
            <i class="ti ti-lock me-1" aria-hidden="true"></i>Close Shift
        </button>
        <a href="{{ url('/shifts') }}" class="btn btn-light">Cancel</a>
    </div>
</form>
@endsection
