@extends('layouts.app')

@section('title', $kitchenWastage->wastage_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">{{ $kitchenWastage->wastage_no }}</h1>
    <a href="{{ url('/kitchen/wastages') }}" class="btn btn-light">Back</a>
</div>

<div class="col-md-6">
    <div class="card">
        <div class="card-body">
            <dl class="mb-0">
                <dt>Branch</dt>
                <dd>{{ $kitchenWastage->branch?->name }}</dd>
                <dt>Product</dt>
                <dd>
                    {{ $kitchenWastage->product?->name }}
                    @if($kitchenWastage->variant)
                        <small class="text-muted">({{ $kitchenWastage->variant->name }})</small>
                    @endif
                </dd>
                <dt>Quantity</dt>
                <dd>{{ $kitchenWastage->quantity }} {{ $kitchenWastage->unit?->code ?? $kitchenWastage->product?->unit?->code }}</dd>
                <dt>Reason</dt>
                <dd>{{ $kitchenWastage->reason ?? '—' }}</dd>
                <dt>Date</dt>
                <dd>{{ $kitchenWastage->wastage_date?->format('d M Y') }}</dd>
                <dt>Recorded By</dt>
                <dd>{{ $kitchenWastage->recordedBy?->name ?? '—' }}</dd>
                <dt>Recorded At</dt>
                <dd>{{ $kitchenWastage->created_at?->format('d M Y H:i') }}</dd>
            </dl>
        </div>
    </div>
</div>
@endsection
