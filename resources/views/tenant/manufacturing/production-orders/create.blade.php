@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $title }}</h1>
        <p class="fw-medium text-muted">Planning only — no stock or GL posting at this stage.</p>
    </div>
    <a href="{{ url('/manufacturing/production-orders') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1"></i>Back
    </a>
</div>

@include('tenant.manufacturing.production-orders.partials.form', [
    'order'      => null,
    'nextNo'     => $nextNo,
    'customers'  => $customers,
    'branches'   => $branches,
    'products'   => $products,
    'statuses'   => $statuses,
    'priorities' => $priorities,
])
@endsection
