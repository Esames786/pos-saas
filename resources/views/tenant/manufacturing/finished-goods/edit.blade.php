@extends('layouts.app')

@section('title', $title)

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $title }}</h4>
                <h6>Tracking only — no inventory increase, WIP accounting, COGS or GL posting</h6>
            </div>
            <div class="page-btn">
                <a href="{{ url('/manufacturing/finished-goods/' . $receipt->id) }}" class="btn btn-light">
                    <i class="ti ti-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-xxl-9 col-xl-8">
                @include('tenant.manufacturing.finished-goods.partials.form', [
                    'receipt'                 => $receipt,
                    'nextNo'                  => null,
                    'units'                   => $units,
                    'branches'                => $branches,
                    'statuses'                => $statuses,
                    'qualityStatuses'         => $qualityStatuses,
                    'priorities'              => $priorities,
                    'prefill'                 => $prefill,
                    'prefillLines'            => $prefillLines,
                    'selectedWip'             => $selectedWip,
                    'selectedOrder'           => $selectedOrder,
                    'selectedCustomer'        => $selectedCustomer,
                    'selectedFinishedProduct' => $selectedFinishedProduct,
                    'productOptions'          => $productOptions,
                ])
            </div>
            <div class="col-xxl-3 col-xl-4">
                @include('tenant.manufacturing.finished-goods.partials.help')
            </div>
        </div>
@endsection
