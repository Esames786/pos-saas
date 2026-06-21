@extends('layouts.app')

@section('title', $title)

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $title }}</h4>
                <h6>Request / planning only — no stock issue, WIP, COGS or GL posting at this stage</h6>
            </div>
            <div class="page-btn">
                <a href="{{ url('/manufacturing/material-requisitions/' . $requisition->id) }}" class="btn btn-light">
                    <i class="ti ti-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-xxl-9 col-xl-8">
                @include('tenant.manufacturing.material-requisitions.partials.form', [
                    'requisition'      => $requisition,
                    'nextNo'           => null,
                    'units'            => $units,
                    'branches'         => $branches,
                    'statuses'         => $statuses,
                    'priorities'       => $priorities,
                    'prefill'          => $prefill,
                    'prefillLines'     => $prefillLines,
                    'selectedOrder'    => $selectedOrder,
                    'selectedCustomer' => $selectedCustomer,
                    'componentOptions' => $componentOptions,
                    'bomWarning'       => $bomWarning,
                ])
            </div>
            <div class="col-xxl-3 col-xl-4">
                @include('tenant.manufacturing.material-requisitions.partials.help')
            </div>
        </div>
@endsection
