@extends('layouts.app')

@section('title', $title)

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $title }}</h4>
                <h6>Tracking only — no stock deduction, scrap expense, WIP variance, COGS or GL posting</h6>
            </div>
            <div class="page-btn">
                <a href="{{ url('/manufacturing/scrap/' . $record->id) }}" class="btn btn-light">
                    <i class="ti ti-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-xxl-9 col-xl-8">
                @include('tenant.manufacturing.scrap.partials.form', [
                    'record'           => $record,
                    'nextNo'           => null,
                    'units'            => $units,
                    'branches'         => $branches,
                    'statuses'         => $statuses,
                    'sourceTypes'      => $sourceTypes,
                    'scrapTypes'       => $scrapTypes,
                    'reasonCodes'      => $reasonCodes,
                    'qualityStatuses'  => $qualityStatuses,
                    'prefill'          => $prefill,
                    'prefillLines'     => $prefillLines,
                    'selectedWip'      => $selectedWip,
                    'selectedFg'       => $selectedFg,
                    'selectedOrder'    => $selectedOrder,
                    'selectedCustomer' => $selectedCustomer,
                    'productOptions'   => $productOptions,
                    'warning'          => $warning,
                ])
            </div>
            <div class="col-xxl-3 col-xl-4">
                @include('tenant.manufacturing.scrap.partials.help')
            </div>
        </div>
@endsection
