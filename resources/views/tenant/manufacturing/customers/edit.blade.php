@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $title }}</h1>
        <p class="fw-medium text-muted">Manufacturing customer — separate from POS/Sales customers.</p>
    </div>
    <a href="{{ url('/manufacturing/customers/' . $customer->id) }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1"></i>Back
    </a>
</div>

@include('tenant.manufacturing.customers.partials.form', [
    'customer' => $customer,
    'nextCode' => null,
])
@endsection
