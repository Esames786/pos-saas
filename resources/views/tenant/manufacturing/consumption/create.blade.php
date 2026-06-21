@extends('layouts.app')

@section('title', $title)

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $title }}</h4>
                <h6>Tracking only — no stock deduction, WIP/MRC mutation, consumption accounting, COGS or GL posting</h6>
            </div>
            <div class="page-btn">
                <a href="{{ url('/manufacturing/consumption') }}" class="btn btn-light">
                    <i class="ti ti-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-xxl-9 col-xl-8">
                @include('tenant.manufacturing.consumption.partials.form', [
                    'record'           => null,
                    'nextNo'           => $nextNo,
                    'units'            => $units,
                    'branches'         => $branches,
                    'statuses'         => $statuses,
                    'sourceTypes'      => $sourceTypes,
                    'consumptionTypes' => $consumptionTypes,
                    'prefill'          => $prefill,
                    'prefillLines'     => $prefillLines,
                    'selectedWip'      => $selectedWip,
                    'selectedMrc'      => $selectedMrc,
                    'selectedOrder'    => $selectedOrder,
                    'selectedCustomer' => $selectedCustomer,
                    'productOptions'   => $productOptions,
                    'warning'          => $warning,
                ])
            </div>
            <div class="col-xxl-3 col-xl-4">
                @include('tenant.manufacturing.consumption.partials.help')
            </div>
        </div>
@endsection
