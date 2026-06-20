@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $title }}</h1>
        <p class="fw-medium text-muted">Configuration only — no inventory or GL posting at this stage.</p>
    </div>
    <a href="{{ url('/manufacturing/bom') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1"></i>Back
    </a>
</div>

@include('tenant.manufacturing.bom.partials.form', [
    'bom'      => null,
    'nextNo'   => $nextNo,
    'products' => $products,
    'units'    => $units,
    'statuses' => $statuses,
])
@endsection
