@extends('layouts.app')

@section('title', 'Billing Rates')

@section('content')
@php
    $fields = [
        \App\Services\Saas\BillingRates::WHATSAPP_RATE => [
            'label' => 'WhatsApp message charge',
            'unit' => 'PKR per message',
            'step' => '0.01',
            'help' => 'Charged to the tenant for every WhatsApp message sent on its behalf.',
        ],
        \App\Services\Saas\BillingRates::WHATSAPP_COST => [
            'label' => 'WhatsApp cost to us',
            'unit' => 'PKR per message',
            'step' => '0.01',
            'help' => 'Meta + tax + bank FX. Used only to show our margin on the usage page.',
        ],
        \App\Services\Saas\BillingRates::WEBSITE_COMMISSION => [
            'label' => 'Website order commission',
            'unit' => '% of net website sales',
            'step' => '0.01',
            'help' => 'Charged monthly on the tenant\'s website orders (delivery fee included, returns deducted).',
        ],
    ];
@endphp

<div class="mb-3">
    <h1 class="mb-1">Billing Rates</h1>
    <p class="text-muted mb-0">What Bingoo charges tenants for usage. A change applies from now on; usage already recorded keeps the rate it was recorded at.</p>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif
@if(session('status'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ route('central.billing-rates.update') }}">
            @csrf
            @method('PUT')
            <div class="row g-4">
                @foreach($fields as $key => $f)
                    @php $row = $stored->get($key); @endphp
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="rate-{{ $key }}">{{ $f['label'] }}</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="rate-{{ $key }}" name="{{ $key }}"
                                   step="{{ $f['step'] }}" min="0" value="{{ old($key, rtrim(rtrim(number_format($values[$key], 4, '.', ''), '0'), '.')) }}" required>
                            <span class="input-group-text">{{ $f['unit'] }}</span>
                        </div>
                        <div class="form-text">{{ $f['help'] }}</div>
                        <div class="form-text">
                            @if($row)
                                Last changed {{ $row->updated_at?->format('d M Y, H:i') }}
                                @if($row->updatedBy)
                                    by {{ $row->updatedBy->name }}
                                @endif
                            @else
                                Not set here yet — using the server default.
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            @can('central.billing-rates.update')
                <div class="mt-4">
                    <button class="btn btn-primary">Save rates</button>
                </div>
            @endcan
        </form>
    </div>
</div>
@endsection
