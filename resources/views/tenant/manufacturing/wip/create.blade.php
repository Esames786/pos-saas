@extends('layouts.app')

@section('title', $title)

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $title }}</h4>
                <h6>Tracking / planning only — no stock, WIP accounting, finished goods, COGS or GL posting</h6>
            </div>
            <div class="page-btn">
                <a href="{{ url('/manufacturing/wip') }}" class="btn btn-light">
                    <i class="ti ti-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-xxl-9 col-xl-8">
                @include('tenant.manufacturing.wip.partials.form', [
                    'job'                     => null,
                    'nextNo'                  => $nextNo,
                    'units'                   => $units,
                    'branches'                => $branches,
                    'statuses'                => $statuses,
                    'priorities'              => $priorities,
                    'prefill'                 => $prefill,
                    'prefillLines'            => $prefillLines,
                    'selectedOrder'           => $selectedOrder,
                    'selectedMrc'             => $selectedMrc,
                    'selectedCustomer'        => $selectedCustomer,
                    'selectedFinishedProduct' => $selectedFinishedProduct,
                    'componentOptions'        => $componentOptions,
                    'warning'                 => $warning,
                ])
            </div>
            <div class="col-xxl-3 col-xl-4">
                @include('tenant.manufacturing.wip.partials.help')
            </div>
        </div>
@endsection
