@extends('layouts.app')

@section('title', $title)

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $title }}</h4>
                <h6>Planning only — no stock or GL posting at this stage</h6>
            </div>
            <div class="page-btn">
                <a href="{{ url('/manufacturing/production-orders') }}" class="btn btn-light">
                    <i class="ti ti-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-8 col-lg-7">
                @include('tenant.manufacturing.production-orders.partials.form', [
                    'order'      => null,
                    'nextNo'     => $nextNo,
                    'customers'  => $customers,
                    'branches'   => $branches,
                    'products'   => $products,
                    'statuses'   => $statuses,
                    'priorities' => $priorities,
                ])
            </div>
            <div class="col-xl-4 col-lg-5">
                @include('tenant.manufacturing.production-orders.partials.help')
            </div>
        </div>
@endsection
