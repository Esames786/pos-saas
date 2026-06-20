@extends('layouts.app')

@section('title', $title)

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $title }}</h4>
                <h6>Configuration only — no inventory or GL posting at this stage</h6>
            </div>
            <div class="page-btn">
                <a href="{{ url('/manufacturing/bom') }}" class="btn btn-light">
                    <i class="ti ti-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>

        @include('tenant.manufacturing.bom.partials.form', [
            'bom'      => null,
            'nextNo'   => $nextNo,
            'products' => $products,
            'units'    => $units,
            'statuses' => $statuses,
        ])
@endsection
