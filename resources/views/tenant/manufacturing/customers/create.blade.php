@extends('layouts.app')

@section('title', $title)

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $title }}</h4>
                <h6>For production orders, job-work and manufacturing costing — not linked to POS/Sales customers</h6>
            </div>
            <div class="page-btn">
                <a href="{{ url('/manufacturing/customers') }}" class="btn btn-light">
                    <i class="ti ti-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-8 col-lg-7">
                @include('tenant.manufacturing.customers.partials.form', [
                    'customer' => null,
                    'nextCode' => $nextCode,
                ])
            </div>
            <div class="col-xl-4 col-lg-5">
                @include('tenant.manufacturing.customers.partials.help')
            </div>
        </div>
@endsection
